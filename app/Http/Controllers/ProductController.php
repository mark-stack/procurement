<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use App\Imports\ExcelImport;
use App\Models\Product;
use App\Models\Project;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\CsvService;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    public function index(Project $project): Response
    {
        /**
         * Single purpose: upload, clarify, and display consolidated BOM for a project
         */
        dd("hello");
        Gate::authorize('owned', $project);

        //Formatter
        $nestingFormatter = new NestingFormatter();
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
        $allCertificateProductLabels = $nestingFormatter->getCertificateProductLabels();
        $hasCertificateProducts = []; //todo: get from master_materials
        $requiresCustom = [];

        //Loop user's material rows
        foreach ($project->rawMaterialQuotes as $rawMaterialQuote) {
            $getProductMatchOptions = $productService->getProductMatchOptions($business, $rawMaterialQuote);

            if ($getProductMatchOptions) {
                /**
                 * 1) Non-price book (will be user custom product)
                 * NOTE: if business allows custom products
                 */
                if ($getProductMatchOptions['status'] === 'CUSTOM') {
                    $requiresCustom[] = [
                        'selected' => [
                            'product_category' => null,
                            'material' => null,
                            'grade' => null,
                            'nominal_length' => null,
                            'nominal_width' => null,
                            'nominal_height' => null,
                            'nesting_algo' => null,
                            'purchasable_length_1' => null,
                            'purchasable_length_2' => null,
                            'purchasable_length_3' => null,
                            'purchasable_width_1' => null,
                            'purchasable_width_2' => null,
                            'purchasable_width_3' => null,
                            'suppliers' => [],
                        ],
                        'selected_other' => [
                            'product_category' => null,
                            'material' => null,
                            'grade' => null,
                            'surface' => null,
                            'suppliers' => [],
                        ],
                        'data' => $rawMaterialQuote,
                        'nominalSizeData' => $productService->getNominalSizeData(),
                    ];
                }

                /**
                 * 2) Price book exact match
                 */
                elseif ($getProductMatchOptions['status'] === 'EXACT') {
                    $rawMaterialQuote['product'] = $getProductMatchOptions['decodedOption'];
                }

                /**
                 * 3) Price book partial match (requires confirmation)
                 */
                elseif ($getProductMatchOptions['status'] === 'PARTIAL') {
                    $partialProductMatches[] = [
                        'selected' => null,
                        'data' => $rawMaterialQuote,
                        'options' => $getProductMatchOptions['decodedOptions'],
                        'custom' => $getProductMatchOptions['custom'],
                    ];
                }
            }

            /**
             * product categories
             */
            $productCategories[] = $rawMaterialQuote['product_category'];

            /**
             * Mill products //todo: get from master_materials
             */
            foreach ($allCertificateProductLabels as $mp) {
                //Could be enum or string
                $value = gettype($mp) === 'object' ? $mp->value : $mp;

                if (strtoupper($rawMaterialQuote->product_category) == strtoupper($value)) {
                    $hasCertificateProducts = true;
                }
            }

            //Append Array
            $nesting_algo = ($rawMaterialQuote->product_category && $nestingFormatter->getNestingLabelsFromProductCategory($rawMaterialQuote->product_category))
                ? $nestingFormatter->getNestingLabelsFromProductCategory($rawMaterialQuote->product_category)[0]
                : null;
            $rawMaterialQuote->nesting_algo = $nesting_algo;
            $materialListRows[] = $rawMaterialQuote;
        }

        /**
         * Sense checks
         */
        $productCategories = array_filter(array_unique($productCategories));
        $senseChecks = $productService->senseChecks($materialListRows, $productCategories, $hasCertificateProducts);

        /**
         * Custom options (form select options)
         */
        $allGrades = $nestingFormatter->allGradeLabels();
        $allMeasurements = $nestingFormatter->allMeasurementUnitLabels();
        $formDependentData = $nestingFormatter->buildDependencyArray2();

        /**
         * Nesting groups
         */
        $nestingGroups = $nestingFormatter->getNestingGroups($user);

        return Inertia::render('ProductIndex', [
            'project' => $project,
            'materialListRows' => $materialListRows,
            'senseChecks' => $senseChecks,
            'partialProductMatches' => $partialProductMatches,
            'requiresCustom' => $requiresCustom,
            'allMeasurements' => $allMeasurements,
            'formDependentData' => $formDependentData,
            'allGrades' => $allGrades,
            'nestingGroups' => $nestingGroups,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Project $project)
    {
        Gate::authorize('owned', $project);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Project $project): RedirectResponse
    {
        /**
         * Single purpose: extract and save materials in a CSV material list
         */
        Gate::authorize('owned', $project);

        $user = auth()->user();
        $business = $user->business;

        //Prerequisite conditions
        $prerequisiteUploadMaterials = (new PrerequisiteConditions())->uploadMaterials(
            $user,
            $project,
        );
        abort_if(!$prerequisiteUploadMaterials,403);

        //Validate
        $request->validate([
            'excel' => 'required|mimes:xlsx,xls|max:2048',
        ]);

        //Services
        $csvService = new CsvService;

        //Store the uploaded file temporarily
        $file = $request->file('excel');
        $path = $file->store('uploads');

        //Read the CSV
        $csvArray = Excel::toArray(new ExcelImport, $file)[0];

        //Process the CSV
        $errorMsg = "The spreadsheet didn't auto-detect properly. Did the template change? Please email the file to mark.laravel.coder@gmail.com to have it re-calibrated quickly.";

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

        return $return;
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project, Product $product)
    {
        Gate::authorize('owned', $product->business);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Project $project, Product $product)
    {
        Gate::authorize('owned', $product->business);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        Gate::authorize('owned', $product->business);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project, Product $product)
    {
        Gate::authorize('owned', $product->business);
    }
}
