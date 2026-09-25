<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use App\Formatters\ProductFormatter;
use App\Models\Project;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DownloadBomController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Project $project): JsonResponse
    {
        /**
         * Single purpose: upload, clarify, and display consolidated BOM for a project
         */
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

        /**
         * status() reads the piece, its order and its quotes for every row, so without
         * this a BOM of any size costs three queries a line.
         */
        $rawMaterialQuotes = $project->rawMaterialQuotes()
            ->with('piece.order', 'piece.quotes')
            ->get();

        //Loop user's material rows
        foreach ($rawMaterialQuotes as $rawMaterialQuote) {
            //Append Array
            $nesting_algo = ($rawMaterialQuote->product_category && $nestingFormatter->getNestingLabelsFromProductCategory($rawMaterialQuote->product_category))
                ? $nestingFormatter->getNestingLabelsFromProductCategory($rawMaterialQuote->product_category)[0]
                : null;
            $rawMaterialQuote->nesting_algo = $nesting_algo;
            $rawMaterialQuote->status = $rawMaterialQuote->status();

            //If should include row based on plan. e.g only "steel merchant" supplier group
            $getProductMatchOptions = $productService->getProductMatchOptions($business, $rawMaterialQuote);

            if ($getProductMatchOptions) {
                /**
                 * 1) Non-price book (will be user custom product)
                 * if business "allow_custom_products"
                 */
                if ($getProductMatchOptions['status'] === 'CUSTOM') {
                    if ($business->allow_custom_products) {
                        $requiresCustom[] = (new ProductFormatter())->requiresCustomForm($rawMaterialQuote);
                    }
                }

                /**
                 * 2) Price book exact match
                 */
                elseif ($getProductMatchOptions['status'] === 'EXACT') {
                    //Supplier group belongs to current plan
                    $supplierGroup = $getProductMatchOptions['supplierGroup'];
                    if ($business->supplierGroupIsCurrentPlan($supplierGroup)) {
                        $rawMaterialQuote['product'] = $getProductMatchOptions['decodedOption'];
                    }
                }

                /**
                 * 3) Price book partial match (requires confirmation)
                 */
                elseif ($getProductMatchOptions['status'] === 'PARTIAL') {
                    //Supplier group belongs to current plan
                    $supplierGroup = $getProductMatchOptions['supplierGroup'];
                    if ($business->supplierGroupIsCurrentPlan($supplierGroup)) {
                        $partialProductMatches[] = [
                            'selected' => null,
                            'data' => $rawMaterialQuote, //todo needs "nesting_algo"
                            'options' => $getProductMatchOptions['decodedOptions'],
                            'custom' => $getProductMatchOptions['custom'],
                        ];
                    }
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
        $allGrades = (new nestingFormatter())->allGradeLabels();
        $allMeasurements = $nestingFormatter->allMeasurementUnitLabels();
        $formDependentData = $nestingFormatter->buildDependencyArray2();

        /**
         * Nesting groups
         */
        $nestingGroups = $nestingFormatter->getNestingGroups($user);

        return response()->json([
            'downloadedBomData' => [
                'project_id' => $project->id,
                'data' => [
                    'unimportedItems' => $project->unimportedItems(),
                    'percentageOfMaterialsQuoted' => $project->percentageOfMaterialsQuoted(),
                    'percentageOfMaterialsOrdered' => $project->percentageOfMaterialsOrdered(),
                    'project' => $project,
                    'materialListRows' => $materialListRows,
                    'senseChecks' => $senseChecks,
                    'partialProductMatches' => $partialProductMatches,
                    'requiresCustom' => $requiresCustom,
                    'allMeasurements' => $allMeasurements,
                    'formDependentData' => $formDependentData,
                    'allGrades' => $allGrades,
                    'business' => $project->user->business,
                    'nestingGroups' => $nestingGroups,
                ],
            ],
        ]);
    }
}
