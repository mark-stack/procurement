<?php

namespace App\Services;

use App\Models\Business;

class SupplierService
{
    /**
     * @deprecated
     */
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
                 * Upgraded = find all supplier categories
                 */

                //Supplier group belongs to current plan
                if ($business->supplierGroupIsCurrentPlan($supplierGroup)) {
                    $output[$supplierGroup][] = $config['productCategory'];
                }
            }
        }

        return $output;
    }

    public function allSupplierGroupsWithIncludedProducts(): array
    {
        /**
         * All supplier groups with included products.
         * e.g "steel merchant" has "UB, UC, PFC" etc
         */
        $output = [];
        $supplierCategoriesWithIncludedProducts = [];

        $implementations = (new ProductService)->getImplementations();
        foreach ($implementations as $implementation) {
            // Check if the class exists
            if (class_exists($implementation)) {
                $service = new $implementation;
                $config = $service->config();
                $supplierGroup = $config['supplierGroup']->value;
                $supplierCategoriesWithIncludedProducts[$supplierGroup][] = $config['productCategory'];
            }
        }

        //2) Build an array that includes a string of included products. e.g "PFC, UB, UC..."
        foreach ($supplierCategoriesWithIncludedProducts as $supplierGroup => $includedProducts) {
            $output[$supplierGroup] = [
                'array' => $includedProducts,
                'string' => implode(', ', $includedProducts),
            ];
        }

        return $output;
    }

    public function supplierGroupsAvailableToBusiness(Business $business): array
    {
        /**
         * Single purpose: get list of supplier categories available to the business
         * Includes plan limitations. e.g lite plan is STEEL_MERCHANT only
         */
        $supplierGroupsArray = [];

        //All supplier groups
        $allSupplierGroupsWithIncludedProducts = $this->allSupplierGroupsWithIncludedProducts();

        //This business's suppliers
        foreach ($business->suppliers as $supplier) {
            //For each supplier, identify what category of products they offer. e,g "steel merchant"
            $supplierCategories = unserialize($supplier->supplier_categories);
            foreach ($supplierCategories as $supplierGroup => $activeForSupplier) {
                //Supplier group belongs to current plan
                if ($business->supplierGroupIsCurrentPlan($supplierGroup)) {
                    if ($activeForSupplier) {
                        $supplierGroupsArray[$supplierGroup] = $allSupplierGroupsWithIncludedProducts[$supplierGroup];
                    }
                }
            }
        }

        return $supplierGroupsArray;
    }

    public function suppliersForSupplierGroup(string $supplierGroup, Business $business): array
    {
        $suppliersForSupplierGroup = [];

        //This business's suppliers
        foreach ($business->suppliers as $supplier) {
            //For each supplier, identify what category of products they offer. e,g "steel merchant"
            $supplierGroups = unserialize($supplier->supplier_categories);
            foreach ($supplierGroups as $thisSupplierGroup => $activeForSupplier) {
                if ($activeForSupplier && $thisSupplierGroup === $supplierGroup) {
                    $suppliersForSupplierGroup[] = $supplier;
                }
            }
        }

        return $suppliersForSupplierGroup;
    }
}
