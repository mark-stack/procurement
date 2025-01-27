<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Quote;
use App\Services\BatchService;
use App\Services\NestingService;
use App\Services\OrderService;
use App\Services\QuoteService;
use App\Services\SupplierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $quoteService = new QuoteService();
        $batchService = new BatchService();

        //Prerequisite variables
        $user = auth()->user();
        $business = $user->business;

        $projects = [
            //Kanban column 1
            "NEW_PROJECTS" => ProjectResource::collection(Project::query()
                ->thisBusiness($business)
                ->active()
                ->doesntHave('rawMaterialQuotes')
                ->sortByUserAndLatest()
                ->get()),
            //Kanban column 2
            "READY_FOR_NESTING" => [
                "projects" => ProjectResource::collection($business
                    ->projectsReadyForBatching()
                    ->sortBy("created_at")),
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
        $batchesForQuoting = $batchService->sortByUserAndLatest($batchesForQuoting,$business);

        foreach($batchesForQuoting as $batch){
            /**
             * Modal: "add quote requests"
             * table rows of each unique supplier-product_category.
             * e.g "ABC Steel" who does 'fasteners' and 'steel merchant' is 2 rows
             */

            $quoted[$batch->id] = [
                "info" => [
                    "batch" => [
                        "id" => $batch->id,
                        "totalMaterial" => 999, //todo
                        "totalUsage" => 999, //todo
                        "totalWaste" => 999, //todo
                    ],
                    "projects" => ProjectResource::collection($batch->projects()),
                    "quotes" => $batch->quotes,
                    "totalQuotesQty" => $batch->quotes()->count(),
                    "sentQuotesQty" => $batch->quotes()->where("quote_sent",true)->count(),
                    "batchQuotingDeadline" => $quoteService->batchQuotingDeadline($batch),
                ],
            ];
        }
        $quoted = array_values($quoted);

        /**
         * Batches for Ordering (Kanban column 4)
         */
        $ordered = [];
        $batchesForOrdering = $business->batches()
            ->hasAtLeastOneSentOrder()
            ->get();

        //Sort
        $batchesForOrdering = $batchService->sortByUserAndLatest($batchesForOrdering,$business);

        foreach($batchesForOrdering as $batch){
            //Total orders qty
            $orders = $batch->orders;
            $supplierCategories = [];
            foreach($orders as $order){
                $supplierCategories[] = $order->quote->supplier_category;
            }
            $totalOrdersQty = count(array_unique($supplierCategories));

            $ordered[$batch->id] = [
                "info" => [
                    "batch" => [
                        "id" => $batch->id,
                        "totalMaterial" => 999, //todo
                        "totalUsage" => 999, //todo
                        "totalWaste" => 999, //todo
                    ],
                    "projects" => ProjectResource::collection($batch->projects()),
                    "orders" => $orders,
                    "approxDueDate" => null, //todo actual - derived from earliest project
                    "totalOrdersQty" => $totalOrdersQty,
                    "sentOrdersQty" => $batch->orders()->where("order_sent",true)->count(),
                    "all_project_manager_approvals" => (new OrderService())->allProjectManagersApproved($batch),
                ],
            ];
        }
        $ordered = array_values($ordered);


        $batches = [
            "QUOTED" => $quoted,
            "ORDERED" => $ordered,
            "DELIVERED" => [
                [
                    "info" => [
                        "batch" => [
                            "id" => 1,
                            "totalMaterial" => 999,
                            "totalUsage" => 999,
                            "totalWaste" => 999,
                        ],
                        "projects" => ProjectResource::collection(Project::query()
                            ->thisBusiness($business)
                            ->active()
                            ->latest()
                            ->get()),
                    ],
                ],
            ],
        ];

        /*
         * Archived projects
         */
        $archivedProjects = ProjectResource::collection(Project::query()
            ->thisBusiness($business)
            ->where('archive',true)
            ->latest()
            ->get());

        return Inertia::render('Dashboard',[
            "projects" => $projects,
            "batches" => $batches,
            "archivedProjects" => $archivedProjects,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        dd("create");
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required',
            'awarded' => 'required|boolean',
            "reference" => 'nullable|required_if:awarded,true',
            'date_materials_required' => 'nullable|required_if:awarded,true|date|after:today',
            "tentative" => 'required',
        ]);

        //Clear reference and date if not awarded
        if(!$validated["awarded"]){
            $validated["reference"] = null;
            $validated["date_materials_required"] = null;
        }

        Project::create([
            "user_id" => auth()->user()->id,
            "name" => $validated["name"],
            "awarded" => $validated["awarded"],
            "reference" => $validated["reference"],
            "date_materials_required" => $validated["date_materials_required"],
            "tentative" => $validated["tentative"],
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
        $validated = $request->validate([
            'name' => 'required',
            'awarded' => 'required|boolean',
            "reference" => 'nullable|required_if:awarded,true',
            'date_materials_required' => 'nullable|required_if:awarded,true|date|after:today',
        ]);

        //Clear reference and date if not awarded
        if(!$validated["awarded"]){
            $validated["reference"] = null;
            $validated["date_materials_required"] = null;
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
        $project->archive = !$project->archive;
        $project->save();

        return back();
    }
}
