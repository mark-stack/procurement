<?php

namespace App\Http\Controllers;

use App\Jobs\AdminMaterialsImport;
use App\Services\MasterMaterialsParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AdminUpdateMasterMaterialsSpreadsheetController extends Controller
{
    public const FILE = 'master_materials.csv';

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, MasterMaterialsParser $parser): RedirectResponse
    {
        if (! Storage::exists(self::FILE)) {
            return back()->with('materialsImport', [
                'ok' => false,
                'messages' => [self::FILE." not found on the configured disk. Check 'storage/app/private'."],
            ]);
        }

        /*
         * Read through the Storage disk rather than a hardcoded storage_path(). The existence
         * check above and the read are then guaranteed to be talking about the same file.
         */
        $handle = Storage::readStream(self::FILE);

        if (! is_resource($handle)) {
            return back()->with('materialsImport', [
                'ok' => false,
                'messages' => [self::FILE.' could not be read from the configured disk.'],
            ]);
        }

        try {
            $result = $parser->parse($handle);
        } catch (Throwable $exception) {
            return back()->with('materialsImport', [
                'ok' => false,
                'messages' => [$exception->getMessage()],
            ]);
        } finally {
            fclose($handle);
        }

        if ($result->rows === []) {
            return back()->with('materialsImport', [
                'ok' => false,
                'messages' => ['No usable rows found in '.self::FILE.'. Nothing was imported.', ...$result->messages()],
            ]);
        }

        AdminMaterialsImport::dispatch($result->collection(), $result->messages());

        return back()->with('materialsImport', [
            'ok' => true,
            'messages' => [
                'Master materials import started. You will be emailed when it finishes.',
                ...$result->messages(),
            ],
        ]);
    }
}
