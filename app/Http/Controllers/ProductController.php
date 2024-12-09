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
            $userCustomOptions = Product::query()
                ->where('domain',$project->user->getDomainFromEmail())
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
                            "nesting_type" => null,
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
//        $productOptions = [];
//        foreach(ProductEnums::cases() as $productEnum){
//            $productOptions[] = $productEnum->value;
//        }
//        $materialOptions = [];
//        foreach(MaterialEnums::cases() as $materialEnum){
//            $materialOptions[] = $materialEnum->value;
//        }
//        $allGradeOptions = [];
//        foreach(GradeEnums::cases() as $gradeEnum){
//            $allGradeOptions[] = $gradeEnum->value;
//        }
//        $steelGradeOptions = [];
//        foreach(GradeEnums::steelGrades() as $gradeEnum){
//            $steelGradeOptions[] = $gradeEnum->value;
//        }
//        $allGradeOptions = [];
//        foreach(GradeEnums::cases() as $gradeEnum){
//            $allGradeOptions[] = $gradeEnum->value;
//        }
//        $timberGradeOptions = [];
//        foreach(GradeEnums::timberGrades() as $gradeEnum){
//            $timberGradeOptions[] = $gradeEnum->value;
//        }
//        $plasticGradeOptions = [];
//        foreach(GradeEnums::plasticGrades() as $gradeEnum){
//            $plasticGradeOptions[] = $gradeEnum->value;
//        }
////        $surfaceOptions = [];
////        foreach(SurfaceEnums::cases() as $surfaceEnum){
////            $surfaceOptions[] = $surfaceEnum->value;
////        }
//        $measurementOptions = [];
//        foreach(MeasurementUnitEnums::cases() as $measurementEnum){
//            $measurementOptions[] = $measurementEnum->value;
//        }
//        $nestingOptions = [];
//        foreach(NestingEnums::cases() as $nestingEnum){
//            $nestingOptions[] = $nestingEnum->value;
//        }

        //Products
        //BOLT/UB/UC/PFC/PLATE/LVL/SHS
        $bolt = ProductEnums::BOLT->value;
        $ub = ProductEnums::UB->value;
        $uc = ProductEnums::UC->value;
        $pfc = ProductEnums::PFC->value;
        $plate = ProductEnums::PLATE->value;
        $lvl = ProductEnums::LVL->value;
        $shs = ProductEnums::SHS->value;
        //todo more

        //Materials (STEEL/ALLOY/TIMBER/ALUMINIUM/PLASTIC/MIXED)
        $steel = MaterialEnums::STEEL->value;
        $alloy = MaterialEnums::ALLOY->value;
        $timber = MaterialEnums::TIMBER->value;
        $aluminium = MaterialEnums::ALUMINIUM->value;
        $plastic = MaterialEnums::PLASTIC->value;
        $mixed = MaterialEnums::MIXED->value;
        //todo more

        //Grades
        $allGrades = [
            "GR 4.6" => GradeEnums::GR_4_6->value,
            "GR 8.8" => GradeEnums::GR_8_8->value,
            "GR 250" => GradeEnums::GR250->value,
            "GR 300" => GradeEnums::GR300->value,
            "GR 350" => GradeEnums::GR350->value,
            "SS304" => GradeEnums::SS304->value,
            "SS316" => GradeEnums::SS316->value,
            "Hardox" => GradeEnums::HARDOX->value,
            "E13" => GradeEnums::E13->value,
            "HDPE" => GradeEnums::HDPE->value,
            //todo more
        ];

        $allMeasurements = [
            MeasurementUnitEnums::METERS->value,
            MeasurementUnitEnums::MILLIMETERS->value,
            MeasurementUnitEnums::FEET->value,
            MeasurementUnitEnums::INCHES->value,
        ];

        //Nesting
        $linear = NestingEnums::LINEAR->value;
        $area = NestingEnums::AREA->value;
        $pack = NestingEnums::PACK->value;

        $formDependentData = [
            //categories (BOLT/UB/UC/PFC/PLATE/LVL/SHS)
            "Other" => [
                //materials
                $steel => [
                    $allGrades["GR 4.6"] => null, //null = all nesting types
                    $allGrades["GR 8.8"] => null,
                    $allGrades["GR 250"] => null,
                    $allGrades["GR 300"] => null,
                    $allGrades["GR 350"] => null,
                    $allGrades["SS304"] => null,
                    $allGrades["SS316"] => null,
                    $allGrades["Hardox"] => null,
                    //todo more
                ],
                $alloy => [
                    //todo more
                ],
                $timber => [
                    $allGrades["E13"],
                    //todo more
                ],
                $aluminium => [

                ],
                $plastic => [
                    $allGrades["HDPE"] => null,
                ],
                $mixed => [

                ],
                //todo more
            ],
            $bolt => [
                //materials
                $steel => [
                    //grades
                    $allGrades["GR 4.6"] => $pack,
                    $allGrades["GR 8.8"] => $pack,
                ],
                $aluminium => [
                    //grades
                ],
                //todo more
            ],
            $ub => [
                //materials
                $steel => [
                    //grades
                    $allGrades["GR 300"] => $linear,
                ],
                //todo more
            ],
            $uc => [
                //materials
                $steel => [
                    //grades
                    $allGrades["GR 300"] => $linear,
                ],
                //todo more
            ],
            $pfc => [
                //materials
                $steel => [
                    //grades
                    $allGrades["GR 300"] => $linear,
                    $allGrades["SS304"] => $linear,
                    $allGrades["SS316"] => $linear,
                ],
                $aluminium => [
                    //grades
                ],
                //todo more
            ],
            $plate => [
                //materials
                $steel => [
                    //grades
                    $allGrades["GR 250"] => $area,
                    $allGrades["GR 350"] => $area,
                    $allGrades["SS304"] => $area,
                    $allGrades["SS316"] => $area,
                ],
                //todo more
            ],
            $lvl => [
                //materials
                $timber => [
                    //grades
                    $allGrades["E13"] => $linear,
                ],
            ],
            $shs => [
                //materials
                $steel => [
                    //grades
                    $allGrades["GR 300"] => $linear, //todo check is GR300
                ],
                $aluminium => [
                    //grades
                ],
                //todo more
            ],
            //todo more
        ];

        return Inertia::render('ProductIndex', [
            "project" => $project,
            "materialListRows" => $materialListRows,
            "senseChecks" => $senseChecks,
            "generalProductMatches" => $generalProductMatches,
            "customItems" => $customItems,
            "allMeasurements" => $allMeasurements,
            "formDependentData" => $formDependentData,
            "allGrades" => $allGrades,
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
