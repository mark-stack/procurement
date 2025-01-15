<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Quote;
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
        $nestingService = new NestingService();
        $quoteService = new QuoteService();

        //Prerequisite variables
        $user = auth()->user();
        $business = $user->business;

        $projects = [
            "BOM_REQUIRED" => ProjectResource::collection(Project::query()
                ->thisBusiness($business)
                ->active()
                ->doesntHave('rawMaterialQuotes')
                ->latest()
                ->get()),
            "BOM_IMPORTED" => ProjectResource::collection($business
                ->projectsReadyForBatching()
                ->sortBy("created_at")),
        ];

        //Category and included products
        $supplierCategoriesWithIncludedProducts = (new SupplierService())->supplierGroups();
        $supplierCategoriesFormatted = [];
        foreach($supplierCategoriesWithIncludedProducts as $supplierCategory => $includedProducts){
            $supplierCategoriesFormatted[$supplierCategory] = [
                "includedProducts" => [
                    "array" => $includedProducts,
                    "string" => implode(", ",$includedProducts),
                ],
            ];
        }

        /**
         * Batches for quoting
         */
        $quoted = [];
        $batchesForQuoting = $business->batches()
            ->doesntHave('orders')
            //todo other criteria for being ready
            ->get();
        foreach($batchesForQuoting as $batch){
            /**
             * Modal: "add quote requests"
             * table rows of each unique supplier-product_category.
             * e.g "ABC Steel" who does 'fasteners' and 'steel merchant' is 2 rows
             */

            $addQuoteRequests = [];

            //nesting
            $piecesNested = $nestingService->piecesNested($batch->pieces);
            $batchGroups = $nestingService->batchGroups($piecesNested);

            foreach($business->suppliers as $supplier){
                $supplierCategories = unserialize($supplier->supplier_categories);

                foreach($supplierCategories as $supplierCategory => $isUsed){
                    //This means there's pieces for the given supplier category.
                    $batchGroup = $batchGroups["assigned"][$supplierCategory] ?? null;

                    if($isUsed && $batchGroup){

                        $quote = Quote::firstOrCreate(
                            [
                                'user_id' => $user->id,
                                "batch_id" => $batch->id,
                                'supplier_id' => $supplier->id,
                                "supplier_category" => $supplierCategory,
                            ],
                            [
                                "supplier_quote_reference" => null,
                                "quote_sent" => false,
                            ]
                        );

                        $addQuoteRequests[] = [
                            "supplierName" => $supplier->name,
                            "supplierCategory" => $supplierCategory,
                            "batchGroup" => $batchGroup,
                            "quote" => $quote,
                        ];
                    }
                }
            }

            //Modal: "current quote coverage"
            //Append quote quantities
            $currentQuoteCoverage = [];
            foreach($supplierCategoriesFormatted as $supplierCategory => $data){
                //This means there's pieces for the given supplier category.
                $batchGroup = $batchGroups["assigned"][$supplierCategory] ?? null;

                if($batchGroup){
                    $appended = $data;
                    $appended["qtyQuotes"] = $batch->quotes()
                        ->where("supplier_category",$supplierCategory)
                        ->where("quote_sent",true)
                        ->count();
                    $currentQuoteCoverage[$supplierCategory] = $appended;
                }
            }

            $quoted[$batch->id] = [
                "batch" => [
                    "id" => $batch->id,
                    "totalMaterial" => 999, //todo
                    "totalUsage" => 999, //todo
                    "totalWaste" => 999, //todo
                ],
                "projects" => ProjectResource::collection($batch->projects()),
                "otherData" => [
                    "quotes" => $batch->quotes,
                    "totalQuotesQty" => $batch->quotes()->count(),
                    "sentQuotesQty" => $batch->quotes()->where("quote_sent",true)->count(),
                    "batchQuotingDeadline" => $quoteService->batchQuotingDeadline($batch),
                ],
                "modalData" => [
                    "addQuoteRequests" => $addQuoteRequests,
                    "currentQuoteCoverage" => $currentQuoteCoverage,
                ],
            ];
        }

        /**
         * Batches for Ordering
         */
        $ordered = [];
        $batchesForOrdering = $business->batches()
            ->has('orders')
            //todo other criteria for being ready
            ->get();
        foreach($batchesForOrdering as $batch){
            //todo firstOrCreate for ORDER and QUOTE objects?
            $orders = $batch->orders;

            $currentQuoteCoverage = [];


            //nesting
            $piecesNested = $nestingService->piecesNested($batch->pieces);
            $batchGroups = $nestingService->batchGroups($piecesNested);

            foreach($supplierCategoriesFormatted as $supplierCategory => $data){
                //This means there's pieces for the given supplier category.
                $batchGroup = $batchGroups["assigned"][$supplierCategory] ?? null;

                if($batchGroup){
                    $appended = $data;
                    $appended["qtyQuotes"] = $batch->quotes()
                        ->where("supplier_category",$supplierCategory)
                        ->where("quote_sent",true)
                        ->count();

                    $appended["batchGroup"] = $batchGroup;
                    $appended["orders"] = $orders;

                    $currentQuoteCoverage[$supplierCategory] = $appended;
                }
            }

            $ordered[$batch->id] = [
                "batch" => [
                    "id" => $batch->id,
                    "totalMaterial" => 999, //todo
                    "totalUsage" => 999, //todo
                    "totalWaste" => 999, //todo
                ],
                "projects" => ProjectResource::collection($batch->projects()),
                "otherData" => [
                    "orders" => $orders,
                    "approxDueDate" => null, //todo actual - derived from earliest project
                    "totalOrdersQty" => $batch->orders()->count(),
                    "sentOrdersQty" => $batch->orders()->where("order_sent",true)->count(),
                    "all_project_manager_approvals" => (new OrderService())->allProjectManagersApproved($batch),
                ],
                "modalData" => [
                    "currentQuoteCoverage" => $currentQuoteCoverage,
                ],
            ];
        }


        $batches = [
            "QUOTED" => $quoted,
            "ORDERED" => $ordered,
            "DELIVERED" => [
                [
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
                    "otherData" => [

                    ],
                    "modalData" => [

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

        /*
         * Modal data
         */
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
        ]);

        //Clear reference and date if not awarded
        if(!$validated["awarded"]){
            $validated["reference"] = null;
            $validated["date_materials_required"] = null;
        }

        Project::create([
            "name" => $validated["name"],
            "user_id" => auth()->user()->id,
            "awarded" => $validated["awarded"],
            "reference" => $validated["reference"],
            "date_materials_required" => $validated["date_materials_required"],
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
