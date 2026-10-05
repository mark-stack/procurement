<?php

namespace App\Formatters;

use App\Enums\NestingEnums;
use App\Models\Business;
use App\Services\ProductService;

class SupplierFormatter
{
    public function supplierGroups(Business $business): array
    {
        /**
         * Single purpose: Get list of ALL supplier categories with contained products. e.g "steel merchant" contains "PFC, UB, etc"
         */
        $output = [];

        /**
         * Pre-group the 'supplierGroup' for each product implementation
         */
        $implementations = (new ProductService)->getImplementations();
        foreach ($implementations as $implementation) {
            // Check if the class exists
            if (class_exists($implementation)) {
                $service = new $implementation;
                $config = $service->config();
                $supplierGroup = $config['supplierGroup']->value;

                /*
                 * Supplier group belongs to current plan
                 */
                $currentPlan = $business->supplierGroupIsCurrentPlan($supplierGroup);
                $algoAllowed = $business->meterage_only
                    ? $config["algorithm"] === NestingEnums::METERAGE
                    : true;

                if ($currentPlan && $algoAllowed) {
                    $output[$supplierGroup][] = $config['productCategory'];
                }
            }
        }

        return $output;
    }

    /**
     * Which of those groups a given lot of material falls into.
     *
     * The names are the keys of supplierGroups() above, which is what a quote records in
     * supplier_category, so the answer can be compared against one directly.
     *
     * Here rather than in a controller because two screens count the same thing off it: the Nesting
     * card's "Material order" line, and the same line on a closed batch's card under Past Batches.
     * Two copies of this would let the two pages disagree about how many merchants a batch is bought
     * from, which is the number each of them prints under a button that opens the blocks themselves.
     *
     * @param  array<int, string|null>  $productCategories
     * @param  array<string, array<int, string>>  $supplierGroups
     * @return array<int, string>
     */
    public function groupsFor(array $productCategories, array $supplierGroups): array
    {
        $matched = [];

        foreach ($supplierGroups as $supplierGroup => $includedProducts) {
            if (array_intersect($productCategories, $includedProducts) !== []) {
                $matched[] = (string) $supplierGroup;
            }
        }

        return $matched;
    }
}
