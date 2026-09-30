<?php

namespace App\Http\Controllers;

use App\Formatters\KanbanFormatter;
use App\Formatters\NestingFormatter;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ArchivedProjectResource;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\CsvService;
use App\Services\TemplateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\RedirectResponse;
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

        return Inertia::render('Dashboard', [
            'projects' => $projects,
            'batches' => $batches,
            'archivedProjects' => $archivedProjects,
            "prerequisiteStartQuoting" => $prerequisiteStartQuoting,
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
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
        if(count($read['invalid']) > 0){
            throw ValidationException::withMessages([
                'invalid_template' => [$read['invalid']],
            ]);
        }

        /*
         * Create project
         */
        $project = Project::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'reference' => $validated['reference'] ?? null,
            'date_materials_required' => $validated['date_materials_required'] ?? null,
            'tentative' => $validated['tentative'],
        ]);

        /*
         * Extract the materials
         *
         * Each file's outcome used to overwrite the one before it in a shared $return,
         * so a batch reported whichever file happened to be last. Outcomes are collected
         * and answered once, below.
         */
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

            return back()->with('warning', count($failedFiles) > 0
                ? "We couldn't read ".implode(', ', $failedFiles).". Please email the file to {$supportEmail} so we can take a look."
                : "No materials could be matched from the uploaded file. Please email it to {$supportEmail} so we can take a look.");
        }

        /*
         * Something imported. The modal moves on to the BOM either way - a file that
         * failed outright is named there, alongside the individual rows that could not
         * be used, rather than being reported as a template that stopped auto-detecting.
         */
        if (count($failedFiles) > 0) {
            $project->recordUnimportedItems([], [], $failedFiles);
        }

        return back()->with('project', $project);
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
