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
        $validFiles = [];
        $invalidFiles = [];
        foreach($files as $file){
            $path = $file->store('uploads');

            //Read the CSV
            $csvArray = null;
            try {
                $csvArray = Excel::toArray(new ExcelImport, $file)[0];

                if($csvService->validateTemplateExists($csvArray)){
                    $validFiles[] = $file->getClientOriginalName();
                }
                else{
                    $invalidFiles[] = $file->getClientOriginalName();
                }
            }
            catch (\Throwable $e) {
                $invalidFiles[] = $file->getClientOriginalName();
            }

            // Delete the file after processing
            unlink(storage_path("app/private/{$path}"));
        }

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

        foreach($files as $file){
            $path = $file->store('uploads');

            //Read the CSV
            $csvArray = Excel::toArray(new ExcelImport, $file)[0];

            //Process the CSV
            $errorMsg = "The file didn't auto-detect properly. Did the template change? Please email the file to mark.laravel.coder@gmail.com to have it re-calibrated quickly.";

            //Users to get nice error message, admin to throw error.
            if ($user->isAdmin()) {
                $return = $csvService->processCsv($csvArray, $project, $errorMsg);
            } else {
                try {
                    $return = $csvService->processCsv($csvArray, $project, $errorMsg);
                } catch (\Exception $e) {
                    $return = back()->with('warning', $errorMsg);
                }
            }

            // Delete the file after processing
            unlink(storage_path("app/private/{$path}"));
        }

        return $return;
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
