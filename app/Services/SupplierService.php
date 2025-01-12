<?php

namespace App\Services;

class SupplierService
{
    public function supplierGroups(): array
    {
        /**
         * Single purpose: generate array of supplier groups and their included product categories
         */
        $output = [];

        /**
         * Pre-group the 'supplierGroup' for each product implementation
         */
        $implementations = (new ProductService())->getImplementations();
        foreach($implementations as $implementation){
            // Check if the class exists
            if (class_exists($implementation)) {
                $service = new $implementation();
                $config = $service->config();
                $supplierGroup = $config["supplierGroup"]->value;
                $output[$supplierGroup][] = $config["productCategory"];
            }
        }

        return $output;
    }
}

