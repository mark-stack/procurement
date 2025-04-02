<?php

namespace App\Http\Controllers;

use App\Enums\SupplierGroupEnums;
use App\Formatters\NestingFormatter;
use App\Formatters\QuoteFormatter;
use App\Http\Resources\ProjectResource;
use App\Imports\ExcelImport;
use App\Models\Offcut;
use App\Models\Project;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\CsvService;
use Illuminate\Support\Facades\Gate;
use App\Services\BatchService;
use App\Services\OrderService;
use App\Services\QuoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        //Services
        $quoteService = new QuoteService;
        $batchService = new BatchService;
        $quoteFormatter = new QuoteFormatter();

        //Prerequisite variables
        $user = auth()->user();
        $business = $user->business;
        $piecesReadyForBatching = (new NestingFormatter)->piecesReadyForBatching($business);

        $projects = [
            //Kanban column 1
            'NEW_PROJECTS' => ProjectResource::collection(Project::query()
                //Clarifications required OR no rawMaterialQuotes
                ->where(function($q) use($business){
                     $q->whereIn("id",$business->projectsRequiringClarification()->pluck("id")->toArray())
                       ->orWhere(function($qq){
                           $qq->doesntHave('rawMaterialQuotes');
                       });
                })
                ->thisBusiness($business)
                ->where("archive",false)
                ->sortByUserAndLatest()
                ->get()),
            //Kanban column 2
            'READY_FOR_NESTING' => [
                'projects' => ProjectResource::collection($business
                    ->projectsReadyForBatching($piecesReadyForBatching,$business)
                    ->sortBy('created_at')),
            ],
        ];

        /**
         * Batches for quoting (Kanban column 3)
         */
        $quoted = [];
        $batchesForQuoting = $business->batches()
            ->hasNoSentOrder()
            ->active()
            ->get();

        //Sort
        $batchesForQuoting = $batchService->sortByUserAndLatest($batchesForQuoting, $business);

        foreach ($batchesForQuoting as $batch) {
            /**
             * Modal: "add quote requests"
             * table rows of each unique supplier-product_category.
             * e.g "ABC Steel" who does 'fasteners' and 'steel merchant' is 2 rows
             */

            /*
             * Prerequisite Gate
             */
            $offcutsAssignedToThisBatch = Offcut::query()
                ->where("batch_to_id",$batch->id)
                ->get();
            $prerequisiteUndoStartQuoting = (new PrerequisiteConditions())->undoStartQuoting(
                $batch,
                $user,
                $offcutsAssignedToThisBatch,
            );

            /**
             * "Quotes and orders"
             * 1) Assign a letter to each project. A, B, C, etc
             * 2) Get all nested pieces
             * 3) Group nested pieces by nesting algorithm. e.g "meterage"
             * 4) get list of supplier categories available to the business
             * 5) filter out categories not features in the nesting list
             */
            $quotesData = $quoteFormatter->quotesData($business, $batch, $user);

            $quoted[$batch->id] = [
                'info' => [
                    'batch' => [
                        'id' => $batch->id,
                        'totalPurchasedMaterial' => 999, //todo
                        'totalUsage' => 999, //todo
                        'totalWaste' => 999, //todo
                    ],
                    'projects' => ProjectResource::collection($batch->projects()),
                    'quotes' => $batch->quotes,
                    'totalQuotesQty' => $batch->quotes()->count(),
                    'sentQuotesQty' => $batch->quotes()->where('quote_sent', true)->count(),
                    'batchQuotingDeadline' => $quoteService->batchQuotingDeadline($batch),
                    "prerequisiteUndoStartQuoting" => $prerequisiteUndoStartQuoting,
                    "quotesData" => $quotesData,
                ],
            ];
        }
        $quoted = array_values($quoted);

        /**
         * Batches for Ordering (Kanban column 4)
         */
        $ordered = [];
        $batchesForOrdering = [];
        foreach($business->batches()->active()->get() as $batch){
            //Less than 100% order coverage
            $all100Percent = true;
            foreach($batch->projects() as $project){
                if($project->percentageOfMaterialsOrdered() !== 100){
                    $all100Percent = false;
                }
            }

            $sentOrdersQty = $batch->orders()->where('order_sent', true)->count();
            if(($sentOrdersQty > 0) && !$all100Percent){
                $batchesForOrdering[] = $batch;
            }
        }
        $batchesForOrdering = collect($batchesForOrdering);

        //Sort
        $batchesForOrdering = $batchService->sortByUserAndLatest($batchesForOrdering, $business);

        foreach ($batchesForOrdering as $batch) {
            //Total orders qty
            $orders = $batch->orders;
            $totalOrdersQty = $batchService->totalOrdersQty($batch);

            $ordered[$batch->id] = [
                'info' => [
                    'batch' => [
                        'id' => $batch->id,
                        'totalPurchasedMaterial' => 999, //todo
                        'totalUsage' => 999, //todo
                        'totalWaste' => 999, //todo
                    ],
                    'projects' => ProjectResource::collection($batch->projects()),
                    'orders' => $orders,
                    'approxDueDate' => null, //todo actual - derived from earliest project
                    'totalOrdersQty' => $totalOrdersQty,
                    'sentOrdersQty' => $batch->orders()->where('order_sent', true)->count(),
                    'all_project_manager_approvals' => (new OrderService)->allProjectManagersApproved($batch),
                ],
            ];
        }
        $ordered = array_values($ordered);

        /**
         * Batches for Delivering (Kanban column 5)
         */
        $delivered = [];
        $batchesForDelivering = [];
        foreach($business->batches()->active()->get() as $batch){
            //100% order coverage
            $all100Percent = true;
            foreach($batch->projects() as $project){
                if($project->percentageOfMaterialsOrdered() !== 100){
                    $all100Percent = false;
                }
            }

            if($all100Percent){
                $batchesForDelivering[] = $batch;
            }
        }
        $batchesForDelivering = collect($batchesForDelivering);


        //Sort
        $batchesForDelivering = $batchService->sortByUserAndLatest($batchesForDelivering, $business);

        foreach ($batchesForDelivering as $batch) {
            //Total orders qty
            $orders = $batch->orders;
            $totalOrdersQty = $batchService->totalOrdersQty($batch);
            $totalDeliveredQty = $batch->orders()->where('is_delivered', true)->count();
            $steelMerchantDeliveredButNoCertsYet = $batch->orders()
                ->whereRelation("quote","supplier_category","=",SupplierGroupEnums::STEEL_MERCHANT->value)
                ->where("material_cert_numbers",null)
                ->exists();

            $delivered[$batch->id] = [
                'info' => [
                    'batch' => [
                        'id' => $batch->id,
                        'totalPurchasedMaterial' => 999, //todo
                        'totalUsage' => 999, //todo
                        'totalWaste' => 999, //todo
                    ],
                    'projects' => ProjectResource::collection($batch->projects()),
                    'orders' => $orders,
                    'approxDueDate' => null, //todo actual - derived from earliest project
                    'totalOrdersQty' => $totalOrdersQty,
                    'sentOrdersQty' => $batch->orders()->where('order_sent', true)->count(),
                    "totalDeliveredQty" => $totalDeliveredQty,
                    "allDelivered" => $totalDeliveredQty === $totalOrdersQty,
                    "steelMerchantDeliveredButNoCertsYet" => $steelMerchantDeliveredButNoCertsYet,
                    'all_project_manager_approvals' => (new OrderService)->allProjectManagersApproved($batch),
                ],
            ];
        }
        $delivered = array_values($delivered);

        $batches = [
            'QUOTED' => $quoted,
            'ORDERED' => $ordered,
            'DELIVERED' => $delivered,
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
        $piecesReadyForBatching = (new NestingFormatter)->piecesReadyForBatching($business);
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
            //"prerequisiteUndoStartQuoting" => $prerequisiteUndoStartQuoting,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        dd('create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        /*
         * Validation
         */
        $user = auth()->user();
        $business = $user->business;
        $allCurrentProjectNames = $business->currentProjects()
            ->pluck("name")
            ->toArray();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                Rule::notIn($allCurrentProjectNames),
            ],
            'reference' => 'nullable',
            'date_materials_required' => 'nullable|date|after:today',
            'tentative' => 'required',
            'excel' => 'required|array',
        ],
        //Rules
        [
            'name.not_in' => 'Pick a name different to currently active projects', // Custom error message
        ]);

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

    /**
     * Display the specified resource.
     */
    public function show(Project $project)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Project $project)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('owned', $project);

        $business = $project->user->business;
        $allProjects = $business->projects;
        $allActiveProjectNames = $allProjects
            ->where("name","!=",$project->name)
            ->pluck("name")
            ->toArray();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                Rule::notIn($allActiveProjectNames),
            ],
            'reference' => 'nullable',
            'date_materials_required' => 'nullable|date|after:today',
        ],
        //Rules
        [
            'name.not_in' => 'Pick a name different to currently active projects', // Custom error message
        ]);

        $project->update($validated);

        return back();
    }

    /**
     * Remove the specified resource from storage.
     */
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
