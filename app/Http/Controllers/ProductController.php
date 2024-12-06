<?php

namespace App\Http\Controllers;

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Product;
use App\Models\Project;
use App\Models\RawMaterialQuote;
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

        $productService = new ProductService();

        //Add products
        $project->products = $project->products;

        $materialListRows = [];
        $checkIfNested = [];
        $productCategories = [];
        $generalProductMatches = [];
        $allMillProducts = ProductEnums::millProducts();
        $hasMillProducts = [];
        $customItems = [];
        foreach($project->rawMaterialQuotes as $row){
            /**
             * Nesting check
             */
//            $isPurchasableSize = $productService->isPurchasableSize($row);
//            $row->checkIfPreNested = $isPurchasableSize;
//            if($isPurchasableSize){
//                $checkIfNested[] = $isPurchasableSize;
//            }

            /**
             * Options
             */
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

            /**
             * Price book products
             */
            $productCategories[] = $row["product_category"];
            $row["product"] = null;
            if(count($decodedOptions) === 1){
                $row["product"] = $decodedOptions[0];
            }

            /**
             * Mill products
             */
            foreach($allMillProducts as $mp){
                if(strtoupper($row->product_category) == strtoupper($mp->value)){
                    $hasMillProducts = true;
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

            //Mill certs
            $senseChecks["mill_certs"] = false;
            if($hasMillProducts){
                $senseChecks["mill_certs"] = true;
            }
        }

        /**
         * Custom options
         */
        $productOptions = [];
        foreach(ProductEnums::cases() as $productEnum){
            $productOptions[] = $productEnum->value;
        }
        $materialOptions = [];
        foreach(MaterialEnums::cases() as $materialEnum){
            $materialOptions[] = $materialEnum->value;
        }
        $allGradeOptions = [];
        foreach(GradeEnums::cases() as $gradeEnum){
            $allGradeOptions[] = $gradeEnum->value;
        }
        $steelGradeOptions = [];
        foreach(GradeEnums::steelGrades() as $gradeEnum){
            $steelGradeOptions[] = $gradeEnum->value;
        }
        $allGradeOptions = [];
        foreach(GradeEnums::cases() as $gradeEnum){
            $allGradeOptions[] = $gradeEnum->value;
        }
        $timberGradeOptions = [];
        foreach(GradeEnums::timberGrades() as $gradeEnum){
            $timberGradeOptions[] = $gradeEnum->value;
        }
        $plasticGradeOptions = [];
        foreach(GradeEnums::plasticGrades() as $gradeEnum){
            $plasticGradeOptions[] = $gradeEnum->value;
        }
//        $surfaceOptions = [];
//        foreach(SurfaceEnums::cases() as $surfaceEnum){
//            $surfaceOptions[] = $surfaceEnum->value;
//        }
        $measurementOptions = [];
        foreach(MeasurementUnitEnums::cases() as $measurementEnum){
            $measurementOptions[] = $measurementEnum->value;
        }
        $customOptions = [
            "products" => [
                "all" => $productOptions
            ],
            "materials" => [
                "all" => $materialOptions
            ],
            "grades" => [
                "all" => $allGradeOptions,
                "STEEL" => $steelGradeOptions,
                "TIMBER" => $timberGradeOptions,
                "PLASTIC" => $plasticGradeOptions,
            ],
            //"surfaces" => $surfaceOptions,
            "measurement_unit" => [
                "all" => $measurementOptions
            ],
            "suppliers" => [
                //todo placeholder
                "all" => ["ABC Company","XYZ Company"]
            ],
        ];

        return Inertia::render('ProductIndex', [
            "project" => $project,
            "materialListRows" => $materialListRows,
            "senseChecks" => $senseChecks,
            "generalProductMatches" => $generalProductMatches,
            "customItems" => $customItems,
            "customOptions" => $customOptions,
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

            //Save matches price book products
            //todo: this can't happen until materials are nested

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
