<?php

namespace App\Http\Controllers;

use App\Formatters\KanbanFormatter;
use App\Formatters\NestingFormatter;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Imports\ExcelImport;
use App\Models\Project;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\CsvService;
use App\Services\TemplateService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
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
         */
        $projects = [
            //Kanban column 1
            "NEW_PROJECTS" => $kanbanFormatter->newProjectsColumn($business),

            //Kanban column 2
            "READY_FOR_NESTING" => $kanbanFormatter->readyForNestingColumn($business),
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
         */
        $archivedProjects = ProjectResource::collection(Project::query()
            ->thisBusiness($business)
            ->where("user_id",$user->id)
            ->where('archive', true)
            ->latest()
            ->get());

        /*
         * Prerequisite Gates
         */
        $piecesReadyForBatching = (new NestingFormatter())->piecesReadyForBatching($business);
        $projectsReadyForBatching = $business->projectsReadyForBatching($piecesReadyForBatching,$business); //Note get this before updating pieces because it gets modified
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

    public function create()
    {
        dd('create');
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = auth()->user();

        //Services
        $csvService = new CsvService;

        //Store the uploaded files temporarily
        $files = $request->file('excel');

        /*
         * Validate templates exist
         */
        $invalidFiles = (new TemplateService())->invalidFiles($files);
        if(count($invalidFiles) > 0){
            throw ValidationException::withMessages([
                'invalid_template' => [$invalidFiles],
            ]);
        }

        /*
         * Create project
         */
        $project = Project::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'reference' => $validated['reference'],
            'date_materials_required' => $validated['date_materials_required'],
            'tentative' => $validated['tentative'],
        ]);

        /*
         * Process Excel
         */
        $return = back();
        $supportEmail = config('env.admin_email');

        foreach($files as $file){
            $path = $file->store('uploads');

            //Process the CSV
            $errorMsg = "The file didn't auto-detect properly. Did the template change? Please email the file to {$supportEmail} to have it re-calibrated quickly.";

            /*
             * The read used to sit outside the try, so a file that got past template
             * detection but blew up on a second read handed the user a 500 - and
             * skipped the unlink below, leaving the upload on disk. The finally makes
             * cleanup unconditional.
             */
            try {
                //Read the CSV
                $csvArray = Excel::toArray(new ExcelImport, $file)[0];

                $return = $csvService->processCsv($csvArray, $project, $errorMsg);
            }
            //Users to get nice error message, admin to throw error.
            catch (\Throwable $e) {
                report($e);

                //The finally below still cleans up before this unwinds
                if ($user->isAdmin()) {
                    throw $e;
                }

                $return = back()->with('warning', $errorMsg);
            }
            finally {
                // Delete the file after processing
                $this->deleteTempFile($path);
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

            //processCsv already flashed the project (RedirectResponse::with writes
            //to the session immediately), so it must be cleared or the modal will
            //close and try to download a BOM for a project that no longer exists.
            $request->session()->forget('project');

            return back()->with('warning', "No materials could be matched from the uploaded file. Please email it to {$supportEmail} so we can take a look.");
        }

        return $return;
    }

    private function deleteTempFile(string $path): void
    {
        $fullPath = storage_path("app/private/{$path}");

        if (is_file($fullPath)) {
            unlink($fullPath);
        }
    }

    public function show(Project $project)
    {
        //
    }

    public function edit(Project $project)
    {
        //
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
         */
        Gate::authorize('owned', $project);

        $project->archive = ! $project->archive;
        $project->save();

        return back();
    }
}
