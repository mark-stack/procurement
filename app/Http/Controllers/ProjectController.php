<?php

namespace App\Http\Controllers;

use App\Formatters\KanbanFormatter;
use App\Imports\ExcelImport;
use App\Formatters\NestingFormatter;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ArchivedProjectResource;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\CsvService;
use App\Services\TemplateLearningService;
use App\Services\TemplateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\RedirectResponse;
use Maatwebsite\Excel\Facades\Excel;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    public function index(): Response
    {
        //Services
        $kanbanFormatter = new KanbanFormatter();

        //Prerequisite variables
        $user = auth()->user();
        $business = $user->business;

        /*
         * Project columns
         *
         * There was a "NEW_PROJECTS" column here too. Nothing on the board ever read it - the page
         * draws READY_FOR_NESTING and the three batch columns - but building it walked every project
         * the business has ever had, archived and completed ones included, running a price book match
         * per material row to find the ones needing clarification.
         */
        $projects = [
            //Kanban column 1
            "READY_FOR_NESTING" => $kanbanFormatter->readyForNestingColumn($business, $user),
        ];

        /*
         * Batch columns
         */
        $batches = [
            //Batches for quoting (Kanban column 3)
            'QUOTED' => $kanbanFormatter->quotedColumn($business,$user),

            //Batches for Ordering (Kanban column 4)
            'ORDERED' => $kanbanFormatter->orderedColumn($business),

            //Batches for Delivering (Kanban column 5)
            'DELIVERED' => $kanbanFormatter->deliveredColumn($business),
        ];

        /*
         * Archived projects
         *
         * ProjectResource walks rawMaterialQuotes > piece > quotes/order for every row, which is
         * around three queries per material line and none of it eager loaded - several hundred
         * queries on a board with a few archived projects, to draw a name and a "Restore" link.
         * The list only grows, so it gets its own slim resource.
         */
        $archivedProjects = ArchivedProjectResource::collection(Project::query()
            ->select(['id', 'name', 'user_id', 'archive'])
            ->thisBusiness($business)
            ->where("user_id",$user->id)
            ->where('archive', true)
            ->latest()
            ->get());

        /*
         * Prerequisite Gates
         */
        $piecesReadyForBatching = (new NestingFormatter())->piecesReadyForBatching($business);
        $projectsReadyForBatching = $business->projectsReadyForBatching($piecesReadyForBatching);
        $prerequisiteStartQuoting = (new PrerequisiteConditions())->startQuoting(
            $user,
            $projectsReadyForBatching,
            $piecesReadyForBatching,
        );

        /*
         * ProjectsBoard, which was called Dashboard until /dashboard became a page in its own right.
         * The route name is still projects.index and the file is the same board; nothing was renamed
         * but the component, and that only so the two screens stop sharing a name.
         */
        return Inertia::render('ProjectsBoard', [
            /*
             * The staff a new project can be created for, for the board's own new-project modal -
             * the same facility the upload page has, because the person with the spreadsheet is
             * often not the person running the job. See User::colleagueOptions.
             */
            'colleagues' => $user->colleagueOptions(),
            'projects' => $projects,
            'batches' => $batches,
            'archivedProjects' => $archivedProjects,
            "prerequisiteStartQuoting" => $prerequisiteStartQuoting,
        ]);
    }

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
         * it, archive it, or be reminded of its materials date.
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
        $learnedNames = [];
        $unlearnable = [];

        foreach($read['unmatched'] as $index => $name){
            $learning = $learner->learn($files[$index], $project);

            if(! $learning->learned()){
                $unlearnable[$name] = $learning->message;

                continue;
            }

            $learnedNames[] = $learning->template->name;

            $detected = $csvService->detectTables(
                Excel::toArray(new ExcelImport, $files[$index])[0],
            );

            if($detected !== []){
                $read['tables'][$index] = $detected;
            }
        }

        $failedFiles = [];

        foreach($read['tables'] as $index => $detectedTables){
            /*
             * A transaction per file. Individual unusable rows are already reported
             * without stopping the file; this is for everything else, so a file that
             * fails part way leaves nothing behind rather than half a BOM the user
             * cannot tell apart from a whole one.
             */
            try {
                DB::transaction(function () use ($csvService, $detectedTables, $project) {
                    $csvService->processTemplate($detectedTables, $project);
                });
            }
            //Users to get nice error message, admin to throw error.
            catch (\Throwable $e) {
                report($e);

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

        return back()
            ->with('project', $project)
            /*
             * Only when a format we had never seen now works. The customer has no idea anything
             * happened - the file simply imported - and the name is the only place the template we
             * wrote for them is ever mentioned to them.
             */
            ->with($learnedNames === [] ? [] : [
                'success' => count($learnedNames) === 1
                    ? sprintf('We had not seen that spreadsheet format before. It has been read, checked and saved as "%s" - uploads of it will import straight away from now on.', $learnedNames[0])
                    : sprintf('We had not seen %d of those spreadsheet formats before. They have been read, checked and saved as %s - uploads of them will import straight away from now on.', count($learnedNames), implode(', ', array_map(fn (string $name) => '"'.$name.'"', $learnedNames))),
            ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        Gate::authorize('owned', $project);

        $validated = $request->validated();

        $project->update($validated);

        return back();
    }

    public function destroy(Project $project): RedirectResponse
    {
        /**
         * Single purpose: toggle archive/restore
         *
         * The gate only asks whether the project belongs to your business, which is every
         * colleague's project in the shared Nesting column - and the archived list this
         * restores from is filtered to your own projects, so archiving a colleague's project
         * hid it from the board with no way back for anyone but them.
         */
        Gate::authorize('owned', $project);

        $user = auth()->user();
        $prerequisiteConditions = new PrerequisiteConditions();

        $allowed = $project->archive
            ? $prerequisiteConditions->restoreProject($user, $project)
            : $prerequisiteConditions->archiveProject($user, $project);

        abort_unless($allowed, 403);

        /*
         * A restore can land on a name that has been given away in the meantime - see
         * PrerequisiteConditions::restoreProjectNameIsFree for why that is worse than untidy, and why
         * this is a sentence rather than another abort_unless. The owner can rename the archived
         * project and try again.
         */
        if ($project->archive && ! $prerequisiteConditions->restoreProjectNameIsFree($project)) {
            return back()->withErrors([
                'archive' => 'Another live project is already called "'.$project->name.'". Rename this'
                    .' one before restoring it, or the board will show two projects under the same'
                    .' name and nothing downstream can tell them apart.',
            ]);
        }

        $project->archive = ! $project->archive;
        $project->save();

        return back();
    }
}
