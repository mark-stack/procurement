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
}
