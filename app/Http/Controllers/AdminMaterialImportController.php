<?php

namespace App\Http\Controllers;

use App\Services\MaterialsJsonImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Two steps, always: work out what the file would do, then do it.
 *
 * The thing this replaces was one button that rewrote all 1,150 rows with no preview and no way to
 * know what had changed afterwards.
 */
class AdminMaterialImportController extends Controller
{
    /**
     * Step one. Produces a plan and writes nothing.
     */
    public function preview(Request $request, MaterialsJsonImport $import): RedirectResponse
    {
        $validated = $request->validate([
            /*
             * Either, not both required: a file for an export being moved between environments, and
             * pasted text for a handful of rows generated somewhere else.
             */
            'file' => ['nullable', 'file', 'mimetypes:application/json,text/plain,text/json', 'max:8192'],
            'json' => ['nullable', 'string', 'max:8000000'],
        ]);

        $json = isset($validated['file'])
            ? (string) file_get_contents($request->file('file')->getRealPath())
            : trim((string) ($validated['json'] ?? ''));

        if ($json === '') {
            return back()->with('materials', [
                'ok' => false,
                'message' => 'Choose a JSON file or paste some JSON first.',
            ]);
        }

        try {
            $plan = $import->plan($import->decode($json));
        } catch (Throwable $exception) {
            return back()->with('materials', [
                'ok' => false,
                'message' => $exception->getMessage(),
            ]);
        }

        /*
         * The plan and the file both travel back to the browser. Parking a 300KB payload in the
         * session for the length of a review would mean every other request in the meantime carried
         * it, and a review abandoned halfway would leave it there.
         */
        return back()->with('materialsImportPlan', [
            ...$plan,
            'fingerprint' => $import->fingerprint($plan),
            'json' => $json,
        ]);
    }

    /**
     * Step two. Re-derives the plan from the same file and refuses if it no longer matches the one
     * that was reviewed - the catalogue can have moved in between, and a plan approved against a
     * different catalogue is not the plan being applied.
     */
    public function apply(Request $request, MaterialsJsonImport $import): RedirectResponse
    {
        $validated = $request->validate([
            'json' => ['required', 'string', 'max:8000000'],
            'fingerprint' => ['required', 'string'],
        ]);

        try {
            $plan = $import->plan($import->decode($validated['json']));

            if ($import->fingerprint($plan) !== $validated['fingerprint']) {
                return back()->with('materials', [
                    'ok' => false,
                    'message' => 'The catalogue changed while this import was being reviewed, so it '
                        .'would no longer do what the preview said. Nothing was imported - preview it again.',
                ]);
            }

            $summary = $import->apply($plan);
        } catch (Throwable $exception) {
            return back()->with('materials', [
                'ok' => false,
                'message' => 'The import failed and nothing was saved: '.$exception->getMessage(),
            ]);
        }

        return back()->with('materials', [
            'ok' => true,
            'message' => implode(' ', $summary),
        ]);
    }
}
