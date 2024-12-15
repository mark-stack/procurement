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
        /**
         * Single purpose: xxx
         */
        Gate::authorize('owned', $project);

        //Services
        $nestingService = new NestingService();
        $productService = new ProductService();

        //Prerequisite variables
        $user = $project->user;
        $business = $user->business;

        /**
         * Sort the user's material rows into groups:
         * 1) Non-price book (will be user custom product)
         * 2) Price book exact match
         * 3) price book partial match (requires confirmation)
         */
        $materialListRows = [];
        $productCategories = [];
        $partialProductMatches = [];
        $allCertificateProductLabels = $nestingService->getCertificateProductLabels();
        $hasCertificateProducts = []; //todo: get from master_materials
        $requiresCustom = [];

        //Loop user's material rows
        foreach($project->rawMaterialQuotes as $rawMaterialQuote){
            $getProductMatchOptions = $productService->getProductMatchOptions($business,$rawMaterialQuote);
            if($getProductMatchOptions){
                /**
                 * 1) Non-price book (will be user custom product)
                 */
                if($getProductMatchOptions["status"] === "CUSTOM"){
                    $requiresCustom[] = [
                        "selected" => [
                            "product" => null,
                            "material" => null,
                            "grade" => null,
                            "nominal_length" => null,
                            "nominal_width" => null,
                            "nominal_height" => null,
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
                        "data" => $rawMaterialQuote,
                        "nominalSizeData" => $productService->getNominalSizeData(),
                    ];
                }

                /**
                 * 2) Price book exact match
                 */
                elseif($getProductMatchOptions["status"] === "EXACT"){
                    $rawMaterialQuote["product"] = $getProductMatchOptions['decodedOption'];
                }

                /**
                 * 3) Price book partial match (requires confirmation)
                 */
                elseif($getProductMatchOptions["status"] === "PARTIAL"){
                    $partialProductMatches[] = [
                        "selected" => null,
                        "data" => $rawMaterialQuote,
                        "options" => $getProductMatchOptions['decodedOptions'],
                    ];
                }
            }

            /**
             * product categories
             */
            $productCategories[] = $rawMaterialQuote["product_category"];

            /**
             * Mill products //todo: get from master_materials
             */
            foreach($allCertificateProductLabels as $mp){
                if(strtoupper($rawMaterialQuote->product_category) == strtoupper($mp->value)){
                    $hasCertificateProducts = true;
                }
            }

            //Append Array
            $materialListRows[] = $rawMaterialQuote;
        }

        /**
         * Sense checks
         */
        $productCategories = array_filter(array_unique($productCategories));
        $senseChecks = $productService->senseChecks($materialListRows,$productCategories,$hasCertificateProducts);

        /**
         * Custom options (form select options)
         */
        $allGrades = $nestingService->allGradeLabels();
        $allMeasurements = $nestingService->allMeasurementUnitLabels();
        $formDependentData = $nestingService->buildDependencyArray();

        return Inertia::render('ProductIndex', [
            "project" => $project,
            "materialListRows" => $materialListRows,
            "senseChecks" => $senseChecks,
            "partialProductMatches" => $partialProductMatches,
            "requiresCustom" => $requiresCustom,
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
            //$productService->senseChecks(); //todo incomplete

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
