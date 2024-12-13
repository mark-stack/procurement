<?php

namespace App\Http\Controllers;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Product;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use App\Services\NestingService;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Project $project): Response
    {
        Gate::authorize('owned', $project);

        $nestingService = new NestingService();

        $materialListRows = [];
        $checkIfNested = [];
        $productCategories = [];
        $generalProductMatches = [];
        $allCertificateProductLabels = $nestingService->getCertificateProductLabels();
        $hasCertificateProducts = []; //todo: get from master_materials
        $customItems = [];



        foreach($project->rawMaterialQuotes as $row){
            /**
             * Nesting check
             * todo reinstate this
             */
//            $isPurchasableSize = $productService->isPurchasableSize($row);
//            $row->checkIfPreNested = $isPurchasableSize;
//            if($isPurchasableSize){
//                $checkIfNested[] = $isPurchasableSize;
//            }

            /**
             * Options
             */
            //Is custom user product?
            $business = $project->user->business;
            $userCustomOptions = $business->products()
                ->where("description",$row->description)
                ->get()
                ->toArray();
            $decodedOptions = $userCustomOptions;

            if(count($userCustomOptions) > 0){
                if(count($decodedOptions) > 1){
                    $generalProductMatches[] = [
                        "selected" => null,
                        "data" => $row,
                        "options" => $decodedOptions,
                    ];
                }
            }

            //Is price book product?
            else{
                $decodedOptions = unserialize($row->general_product_matches);
                if(count($decodedOptions) > 1){
                    $generalProductMatches[] = [
                        "selected" => null,
                        "data" => $row,
                        "options" => $decodedOptions,
                    ];
                }
                if(count($decodedOptions) === 0){
                    $customItems[] = [
                        "selected" => [
                            "product" => null,
                            "material" => null,
                            "grade" => null,
                            "size" => null,
                            "quantify" => null,
                            "nesting_algo" => null,
                            "purchasable_length_1" => null,
                            "purchasable_length_2" => null,
                            "purchasable_length_3" => null,
                            "purchasable_width_1" => null,
                            "purchasable_width_2" => null,
                            "purchasable_width_3" => null,
                            "suppliers" => [],
                        ],
                        "selected_other" => [
                            "product" => null,
                            "material" => null,
                            "grade" => null,
                            "surface" => null,
                            "quantify" => null,
                            "suppliers" => [],
                        ],
                        "data" => $row,
                        "subOption" => [
                            "product" => "all",
                            "material" => "all",
                            "grade" => "all",
                            "suppliers" => "all",
                        ],
                    ];
                }
            }

            /**
             * Price book products
             */
            $productCategories[] = $row["product_category"];
            $row["product"] = null;
            if(count($decodedOptions) === 1){
                $row["product"] = $decodedOptions[0];
            }

            /**
             * Mill products //todo: get from master_materials
             */
            foreach($allCertificateProductLabels as $mp){
                if(strtoupper($row->product_category) == strtoupper($mp->value)){
                    $hasCertificateProducts = true;
                }
            }

            //Append Array
            $materialListRows[] = $row;
        }
        $productCategories = array_filter(array_unique($productCategories));


        /**
         * Sense checking
         */
        $senseChecks = null;
        if(count($materialListRows) > 0){
            //No bolts
            $senseChecks["has_bolts"] = false;
            if(in_array(ProductEnums::BOLT->value,$productCategories)){
                $senseChecks["has_bolts"] = true;
            }

            //Bolt quantity is low
            $senseChecks["bolt_qty"] = 1000; //todo
            //todo complete

            //Items might be pre-nested
            $senseChecks["pre_nested_check"] = false;
            if(count($checkIfNested) > 0){
                $senseChecks["pre_nested_check"] = true;
            }

            //material Certificates //todo: get from master_materials
            $senseChecks["certificates"] = false;
            if($hasCertificateProducts){
                $senseChecks["certificates"] = true;
            }
        }

        /**
         * Custom options
         */
        $allGrades = $nestingService->allGradeLabels();
        $allMeasurements = $nestingService->allMeasurementUnitLabels();
        $formDependentData = $nestingService->buildDependencyArray();

        return Inertia::render('ProductIndex', [
            "project" => $project,
            "materialListRows" => $materialListRows,
            "senseChecks" => $senseChecks,
            "generalProductMatches" => $generalProductMatches,
            "customItems" => $customItems,
            "allMeasurements" => $allMeasurements,
            "formDependentData" => $formDependentData,
            "allGrades" => $allGrades,
            "business" => $project->user->business,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Project $project)
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Project $project): RedirectResponse
    {
        $request->validate([
            'csv' => 'required|mimes:csv,txt|max:2048', // Validate the file
        ]);

        // Store the uploaded file temporarily
        $path = $request->file('csv')->store('uploads');

        // Read the CSV
        $data = [];
        if (($handle = fopen(storage_path("app/private/{$path}"), 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                $data[] = $row;
            }
            fclose($handle);
        }

        /**
         * Template detection
         */
        $productService = new ProductService();
        $templatesDetected = $productService->templatesDetected($data,$project->user);

        // Optionally delete the file after processing
        unlink(storage_path("app/private/{$path}"));

        //Only 1 template found (ideal scenario)
        $return = back();
        $onlyOneResult = count($templatesDetected) === 1;
        if($onlyOneResult){
            //Clean the data (but no default assumptions yet)
            $cleanCsvData = $productService->cleanCsvData($data,$templatesDetected[0],$project);

            //Sense checks
            $productService->senseChecks(); //tdo complete

            //Find price book products
            $dataWithProducts = $productService->findProductsFromCleanData($cleanCsvData,$project->user);

            //Save user material list
            $cleanMaterialList = $productService->saveRawMaterialQuoteData($dataWithProducts,$project);

            //Create new user-custom products
            $productService->createUserCustomProducts($dataWithProducts,$project);
        }
        else{
            $return = back()->with("warning","The file didn't auto-detect properly. Did the template change? Please email the file to mark.laravel.coder@gmail to have it re-calibrated");
        }

        return $return;
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project, Product $product)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Project $project, Product $product)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project, Product $product)
    {
        //
    }
}
