<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Quote;
use App\Services\BatchService;
use App\Services\OrderService;
use App\Services\QuoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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

        //Prerequisite variables
        $user = auth()->user();
        $business = $user->business;

        $projects = [
            //Kanban column 1
            'NEW_PROJECTS' => ProjectResource::collection(Project::query()
                ->thisBusiness($business)
                ->active()
                ->doesntHave('rawMaterialQuotes')
                ->sortByUserAndLatest()
                ->get()),
            //Kanban column 2
            'READY_FOR_NESTING' => [
                'projects' => ProjectResource::collection($business
                    ->projectsReadyForBatching()
                    ->sortBy('created_at')),
            ],
        ];

        /**
         * Batches for quoting (Kanban column 3)
         */
        $quoted = [];
        $batchesForQuoting = $business->batches()
            ->hasNoSentOrder()
            ->get();

        //Sort
        $batchesForQuoting = $batchService->sortByUserAndLatest($batchesForQuoting, $business);

        foreach ($batchesForQuoting as $batch) {
            /**
             * Modal: "add quote requests"
             * table rows of each unique supplier-product_category.
             * e.g "ABC Steel" who does 'fasteners' and 'steel merchant' is 2 rows
             */
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
                ],
            ];
        }
        $quoted = array_values($quoted);

        /**
         * Batches for Ordering (Kanban column 4)
         */
        $ordered = [];
        $batchesForOrdering = [];
        foreach($business->batches as $batch){
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
        foreach($business->batches as $batch){
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
                    "totalDeliveredQty" => $batch->orders()->where('is_delivered', true)->count(),
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

        return Inertia::render('Dashboard', [
            'projects' => $projects,
            'batches' => $batches,
            'archivedProjects' => $archivedProjects,
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
        $business = auth()->user()->business;
        $allProjects = $business->projects;
        $allActiveProjectNames = $allProjects
            ->pluck("name")
            ->toArray();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                Rule::notIn($allActiveProjectNames),
            ],
            'awarded' => 'required|boolean',
            'reference' => 'nullable|required_if:awarded,true',
            'date_materials_required' => 'nullable|required_if:awarded,true|date|after:today',
            'tentative' => 'required',
        ],
        //Rules
        [
            'name.not_in' => 'Pick a name different to currently active projects', // Custom error message
        ]);


        //Clear reference and date if not awarded
        if (! $validated['awarded']) {
            $validated['reference'] = null;
            $validated['date_materials_required'] = null;
        }

        Project::create([
            'user_id' => auth()->user()->id,
            'name' => $validated['name'],
            'awarded' => $validated['awarded'],
            'reference' => $validated['reference'],
            'date_materials_required' => $validated['date_materials_required'],
            'tentative' => $validated['tentative'],
        ]);

        return back();
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
            'awarded' => 'required|boolean',
            'reference' => 'nullable|required_if:awarded,true',
            'date_materials_required' => 'nullable|required_if:awarded,true|date|after:today',
        ],
        //Rules
        [
            'name.not_in' => 'Pick a name different to currently active projects', // Custom error message
        ]);

        //Clear reference and date if not awarded
        if (! $validated['awarded']) {
            $validated['reference'] = null;
            $validated['date_materials_required'] = null;
        }

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
        $project->archive = ! $project->archive;
        $project->save();

        return back();
    }
}
