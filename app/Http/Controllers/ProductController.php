<?php

namespace App\Http\Controllers;

use App\Imports\ExcelImport;
use App\Models\MaterialListFile;
use App\Models\Project;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\CsvService;
use App\Services\TemplateLearningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ProductController extends Controller
{
    /**
     * Extract and save the materials in an uploaded bill of materials - and, when nothing recorded
     * can read it, work out how to read it first.
     *
     * That second half is new and is the whole of what onboarding used to be. A file matching none of
     * the business's templates used to end here with "didn't auto-detect properly, did the template
     * change? Please email the file to support", which is the product telling a customer that the
     * next step is an email and two business days. It is now taken to TemplateLearningService, which
     * describes the sheet, runs the real importer over its description, and records a template if
     * every check that would stop an admin saving one passes. Then detection is asked again - and the
     * ordinary path below runs, because by then there is nothing unusual about this file.
     */
    public function store(
        Request $request,
        Project $project,
        CsvService $csvService,
        TemplateLearningService $learner,
    ): RedirectResponse {
        Gate::authorize('owned', $project);

        $user = auth()->user();

        //Prerequisite conditions
        $prerequisiteUploadMaterials = (new PrerequisiteConditions())->uploadMaterials(
            $user,
            $project,
        );
        abort_if(! $prerequisiteUploadMaterials, 403);

        //Validate
        $request->validate([
            'excel' => 'required|mimes:xlsx,xls|max:2048',
        ]);

        /*
         * Excel::toArray() reads the uploaded temp file directly. This used to
         * $file->store('uploads') first and then unlink a copy it never read back -
         * and the unlink sat after the processing, so an admin's rethrown exception
         * left the copy on disk.
         */
        $file = $request->file('excel');
        $csvArray = Excel::toArray(new ExcelImport, $file)[0];

        $detected = $csvService->detectTables($csvArray);

        /*
         * Learning happens outside any transaction, deliberately. It makes two calls to OpenAI and
         * runs a full extraction, which is tens of seconds - and a transaction held open across an
         * outbound HTTP call is a database connection pinned to somebody else's API. It writes one
         * row of its own and wraps that itself.
         */
        $learning = $detected === [] ? $learner->learn($file, $project) : null;

        if ($learning?->learned()) {
            $detected = $csvService->detectTables($csvArray);
        }

        /*
         * Still nothing, which means learning ran and failed: reaching here with nothing detected is
         * only possible if nothing was detected before it either.
         *
         * The sentence comes from the result rather than from supportMessage() below, because it says
         * we have the file and are dealing with it - an admin has been emailed and the attempt is on
         * their screen - instead of asking the customer to send us a second copy.
         */
        if ($detected === []) {
            return back()->with('warning', $learning === null
                ? $this->supportMessage()
                : $learning->message);
        }

        /*
         * The spreadsheet itself, kept, with every row it is about to produce pointing back at it.
         *
         * Recorded here rather than at the top of the method: a file nothing could be read out of has
         * no materials to be the file behind, and listing it on the batch's BOM as an upload with no
         * rows would be offering to delete something that was never imported. A file that failed
         * learning is kept by TemplateLearningService instead, where an admin can get at it.
         *
         * Outside the transaction below, because it writes to the disk as well as the database and a
         * rolled-back row would leave the file behind it orphaned. The reverse - a stored file whose
         * import then failed - is a row with no materials on it, which the modal can show and somebody
         * can delete.
         */
        $materialListFile = MaterialListFile::record($file, $project, $user);

        /*
         * A transaction so a failure part way through leaves nothing behind, and \Throwable rather
         * than \Exception so a TypeError is caught too - it used to escape as a 500. Admins get the
         * exception instead, because a broken extraction is theirs to read.
         */
        $import = fn () => $csvService->processTemplate($detected, $project, $materialListFile);

        if ($user->isAdmin()) {
            DB::transaction($import);
        } else {
            try {
                DB::transaction($import);
            } catch (Throwable $e) {
                report($e);

                //Nothing came of it, so nothing is listed as having come of it
                $materialListFile->deleteWithRows($user->business);

                return back()->with('warning', $this->supportMessage());
            }
        }

        return back()
            ->with('project', $project)
            /*
             * Only when a template was just written for this file. Saying nothing would leave the
             * customer to notice for themselves that a format we had never seen now works, and the
             * sentence is also the only place the name we gave it appears.
             */
            ->with($learning?->learned() ? ['success' => $learning->message] : []);
    }

    /**
     * The fallback message, for an extraction that threw rather than a format we could not read.
     *
     * TemplateLearningResult carries its own wording for the unreadable case and it deliberately
     * does not ask the customer to email anything - we already have the file. This one still does,
     * because the failure it describes is not about the format and there is nothing on our side
     * waiting in a queue.
     */
    private function supportMessage(): string
    {
        $supportEmail = config('env.admin_email');

        return "Something went wrong reading that spreadsheet. Please email the file to {$supportEmail} and we will look at it.";
    }
}
