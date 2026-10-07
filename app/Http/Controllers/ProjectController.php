<?php

namespace App\Http\Controllers;

use App\Imports\ExcelImport;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Batch;
use App\Models\MaterialListFile;
use App\Models\Project;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\CsvService;
use App\Services\NotificationImplementations\NotificationColleagueMovedDateImplementation;
use App\Services\TemplateLearningService;
use App\Services\TemplateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\RedirectResponse;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProjectController extends Controller
{
    /**
     * Create a project and import the bills of materials uploaded with it - writing the template for
     * any of them we have no template for.
     *
     * That last part is what onboarding used to be, and this is the path it mattered most on: the new
     * project modal is where a customer's very first spreadsheet arrives. A file matching nothing used
     * to fail validation here, before the project was even created, with "didn't auto-detect properly,
     * did the template change?" - to a business for which no template had ever been recorded. There
     * was no template to change.
     */
    public function store(StoreProjectRequest $request, TemplateLearningService $learner): RedirectResponse
    {
        $validated = $request->validated();

        $user = auth()->user();

        //Services
        $csvService = new CsvService;

        $files = $request->file('excel');
        $supportEmail = config('env.admin_email');

        /*
         * Read and validate every upload, once.
         *
         * Each file used to be parsed twice over - once to check a template matched,
         * then again here - and the detected tables thrown away in between.
         */
        $read = (new TemplateService())->readFiles($files);

        /*
         * A file that is not a spreadsheet at all - or one our own detection threw on - is refused
         * before anything is created, which is what used to happen to every file that produced no
         * tables. No template can be written for these, so there is nothing to gain by going on.
         *
         * Files that read perfectly well and simply match no template are no longer in here; they are
         * dealt with below, once there is a project to import them into.
         */
        if(count($read['unreadable']) > 0){
            throw ValidationException::withMessages([
                'invalid_template' => [array_values($read['unreadable'])],
            ]);
        }

        /*
         * Who the job is for.
         *
         * user_id is the project manager, and until now it was whoever was logged in - which in a
         * fabricator with a drawing office is the draftsman, not the manager running the job. The BOM
         * comes out of the model and is uploaded by the person who detailed it, so every one of those
         * projects appeared on the board under the draftsman's name and the manager could not edit
         * it, mark it done, or be reminded of its materials date.
         *
         * So the manager is chosen on the upload page and recorded here, and the uploader is kept in
         * created_by_user_id - which is what lets them add the rest of the materials later and finish
         * an import that stops at a clarification. Null when nobody is acting on anybody's behalf:
         * see Project::isManagedBy.
         *
         * The id is already known to be a user of this business - StoreProjectRequest scopes it -
         * so nothing here can hand a project to a stranger.
         */
        //Cast: an upload is multipart, so this arrives as a string and "2" !== 2 would read every
        //manager picking themselves as somebody acting on their own behalf
        $projectManagerId = (int) ($validated['project_manager_id'] ?? $user->id);
        $onBehalfOfColleague = $projectManagerId !== $user->id;

        /*
         * Create project
         */
        $project = Project::create([
            'user_id' => $projectManagerId,
            'created_by_user_id' => $onBehalfOfColleague ? $user->id : null,
            'name' => $validated['name'],
            'reference' => $validated['reference'] ?? null,
            'date_materials_required' => $validated['date_materials_required'] ?? null,
            //Required by the request, so there is always an answer here - no ?? null
            'date_fabrication_begins' => $validated['date_fabrication_begins'],
            'tentative' => $validated['tentative'],
        ]);

        /*
         * Extract the materials
         *
         * Each file's outcome used to overwrite the one before it in a shared $return,
         * so a batch reported whichever file happened to be last. Outcomes are collected
         * and answered once, below.
         */
        /*
         * The spreadsheets we could read and had no template for.
         *
         * Each one is described, tested against itself and recorded if it passes - see
         * TemplateLearningService - and then re-read, because a template that now exists is a template
         * detection will find. Outside any transaction: it makes two calls to OpenAI per file, and a
         * transaction held open across an outbound HTTP call pins a database connection to somebody
         * else's API.
         *
         * A file this cannot write a template for is reported as a failure below, the same as one that
         * threw: the customer is told we have it and are dealing with it, which is true - the attempt
         * is recorded and an admin has been emailed.
         */
        $unlearnable = [];

        foreach($read['unmatched'] as $index => $name){
            $learning = $learner->learn($files[$index], $project);

            if(! $learning->learned()){
                $unlearnable[$name] = $learning->message;

                continue;
            }

            $detected = $csvService->detectTables(
                Excel::toArray(new ExcelImport, $files[$index])[0],
                //The project's own manager, not whoever is uploading: see ProductController::store
                $project->user,
            );

            if($detected === []){
                /*
                 * A template was written and then found nothing in the file it was written for.
                 *
                 * This branch did nothing at all: the file was in neither the failed list nor the
                 * unlearnable one, so it was dropped without a word to the customer or a mark
                 * against the project - the one path here that could lose a whole upload in
                 * silence. It should not be reachable, since the template was tested against this
                 * very file moments ago, which is exactly why it is worth reporting rather than
                 * passing over.
                 */
                report(new RuntimeException(sprintf(
                    'Template %d was recorded for "%s" and then detected nothing in it.',
                    $learning->template->id,
                    $name,
                )));

                $unlearnable[$name] = "We have read {$name} and saved how to read it, but it did not import. We are looking at it.";

                continue;
            }

            $read['tables'][$index] = $detected;
        }

        $failedFiles = [];

        foreach($read['tables'] as $index => $detectedTables){
            /*
             * The spreadsheet itself, kept, with every row it is about to produce pointing back at
             * it - so the Nesting page's BOM can list the uploads behind a batch and take a whole
             * one off again. Only for the files something was actually read out of; see
             * ProductController::store, which records one for the same reason at the same point.
             *
             * Outside the transaction below: it writes to the disk as well as the database, and a
             * rolled-back row would leave the file behind it orphaned.
             */
            $materialListFile = MaterialListFile::record($files[$index], $project, $user);

            /*
             * A transaction per file. Individual unusable rows are already reported
             * without stopping the file; this is for everything else, so a file that
             * fails part way leaves nothing behind rather than half a BOM the user
             * cannot tell apart from a whole one.
             */
            try {
                DB::transaction(function () use ($csvService, $detectedTables, $project, $materialListFile) {
                    $csvService->processTemplate($detectedTables, $project, $materialListFile);
                });
            }
            //Users to get nice error message, admin to throw error.
            catch (\Throwable $e) {
                report($e);

                //Nothing came of it, so nothing is listed as having come of it
                $materialListFile->deleteWithRows($user->business);

                if ($user->isAdmin()) {
                    throw $e;
                }

                $failedFiles[] = $files[$index]->getClientOriginalName();
            }
        }

        /*
         * Nothing extracted.
         * The template matched and no exception was thrown, but not a single row
         * produced a material. Without this the project is flashed as a success
         * and the user is handed an empty BOM with no explanation.
         */
        if ($project->rawMaterialQuotes()->count() === 0) {
            /*
             * Any copy kept of the uploads, before the project goes. The rows cascade off the project
             * and the files on disk do not, so this is the one place an orphan could be left - there
             * are no materials pointing at them, so there is nothing to refuse over.
             */
            foreach ($project->materialListFiles()->get() as $materialListFile) {
                $materialListFile->deleteWithRows($user->business);
            }

            //Discard the empty shell so the user can retry with the same name
            $project->delete();

            /*
             * The learning message in preference to the support one, where there is a learning
             * message to give. It says we have the file and are dealing with it, which is true and is
             * the opposite of what the support line asks of them - we already have it, an admin has
             * been emailed, and asking them to send it in would be asking for a second copy.
             */
            if ($unlearnable !== []) {
                return back()->with('warning', reset($unlearnable));
            }

            return back()->with('warning', count($failedFiles) > 0
                ? "We couldn't read ".implode(', ', $failedFiles).". Please email the file to {$supportEmail} so we can take a look."
                : "No materials could be matched from the uploaded file. Please email it to {$supportEmail} so we can take a look.");
        }

        /*
         * Something imported. The modal moves on to the BOM either way - a file that
         * failed outright is named there, alongside the individual rows that could not
         * be used, rather than being reported as a template that stopped auto-detecting.
         *
         * A file we could not write a template for is in here too. From the BOM's point of view it is
         * the same thing as one that threw - nothing came out of it - and the difference is in the
         * message above and in the attempt an admin is now looking at.
         */
        $notImported = [...$failedFiles, ...array_keys($unlearnable)];

        if (count($notImported) > 0) {
            $project->recordUnimportedItems([], [], $notImported);
        }

        /*
         * The finished project that was carrying this name, retired now the new one has earned it.
         *
         * StoreProjectRequest let the name through on the strength of that project being finished and
         * this user being allowed to retire it; this is the other half, and it is deliberately down
         * here rather than beside the create above. A project that imports nothing is deleted a few
         * lines up so the name can be retried - and retiring somebody's old job as a side effect of
         * an upload that then failed would be a change they never asked for and never saw.
         *
         * Re-asked rather than trusted: validation ran before any of the importing above.
         */
        $clash = $request->finishedProjectHoldingTheName();

        if ($clash !== null && (new PrerequisiteConditions)->markProjectDone($user, $clash)) {
            $clash->done = true;
            $clash->save();
        }

        /*
         * Nothing is said about a template having been written: from the customer's side the file
         * simply imported, which is the whole point of writing one. The template itself is the
         * record - see TemplateLearningService - and an admin reviews it there.
         */
        return back()->with('project', $project);
    }

    /**
     * Rename a job, or move the day it starts on the saw.
     *
     * The second of those is not a private fact about one project. A batch is quoted, bought and
     * delivered as one, and the day its steel has to be at the workshop is the earliest fabrication
     * date among the jobs on it - so moving yours can move the deadline of every colleague whose work
     * is on the same batch, and their card is coloured off it. UpdateProjectRequest decides whether
     * the move is allowed at all (PrerequisiteConditions::moveFabricationDate); this tells the people
     * it lands on, which is the half nothing did.
     *
     * Only the live batches. A done one has nothing left to be late for, and the gate above has
     * already refused the edit where every batch carrying this steel is finished.
     */
    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('owned', $project);

        $validated = $request->validated();

        $user = auth()->user();

        /*
         * The batches this job is on, with the day each of them is currently wanted by - read before
         * the save, because that is the only moment the old day still exists anywhere. The column is
         * overwritten in place and every date on the page is derived from it at read time.
         */
        $liveBatches = Batch::query()
            ->active()
            ->whereIn('id', $project->pieces()->whereNotNull('batch_id')->distinct()->pluck('batch_id'))
            ->get();

        $datesBefore = $liveBatches->mapWithKeys(fn (Batch $batch) => [
            $batch->id => Project::earliestMaterialsRequiredDate($batch->projectDates())?->toDateString(),
        ]);

        $project->update($validated);

        $notifier = new NotificationColleagueMovedDateImplementation;

        foreach ($liveBatches as $batch) {
            //Re-read: projectDates() is deliberately not memoised, so this sees the date just saved
            $projectsOnBatch = $batch->projectDates();
            $requiredBy = Project::earliestMaterialsRequiredDate($projectsOnBatch);

            //A rename, or a move that the earlier date of some other job on the batch absorbs
            if (($datesBefore[$batch->id] ?? null) === $requiredBy?->toDateString()) {
                continue;
            }

            $notifier->notifyBatchManagers($batch, $projectsOnBatch, $user, $requiredBy);
        }

        return back();
    }

    public function destroy(Project $project): RedirectResponse
    {
        /**
         * Single purpose: toggle done/reopen
         *
         * The gate only asks whether the project belongs to your business, which is every
         * colleague's project in the shared Nesting column - and the done list this
         * reopens from is filtered to your own projects, so retiring a colleague's project
         * hid it from the board with no way back for anyone but them.
         */
        Gate::authorize('owned', $project);

        $user = auth()->user();
        $prerequisiteConditions = new PrerequisiteConditions();

        $allowed = $project->done
            ? $prerequisiteConditions->reopenProject($user, $project)
            : $prerequisiteConditions->markProjectDone($user, $project);

        abort_unless($allowed, 403);

        /*
         * A restore can land on a name that has been given away in the meantime - see
         * PrerequisiteConditions::reopenProjectNameIsFree for why that is worse than untidy, and why
         * this is a sentence rather than another abort_unless. The owner can rename the done
         * project and try again.
         */
        if ($project->done && ! $prerequisiteConditions->reopenProjectNameIsFree($project)) {
            return back()->withErrors([
                'done' => 'Another live project is already called "'.$project->name.'". Rename this'
                    .' one before restoring it, or the board will show two projects under the same'
                    .' name and nothing downstream can tell them apart.',
            ]);
        }

        $project->done = ! $project->done;
        $project->save();

        return back();
    }
}
