<?php

namespace App\Http\Controllers;

use App\Formatters\SupplierFormatter;
use App\Http\Resources\SupplierResource;
use App\Models\Business;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminSupplierIndexController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Business $business): Response
    {
        $suppliers = $business->suppliers()->orderBy('name')->get();

        //Category and included products
        $categories = (new SupplierFormatter)->supplierGroups($business);

        //Category, included products, user attached suppliers
        $byCategory = [];
        foreach ($categories as $categoryLabel => $includedProducts) {
            $suppliersWithThisCategory = [];
            foreach ($suppliers as $supplier) {
                $supplierCategories = unserialize($supplier->supplier_categories);
                foreach ($supplierCategories as $thisCategoryLabel => $value) {
                    //Is set
                    if ($value && $thisCategoryLabel === $categoryLabel) {
                        $suppliersWithThisCategory[] = $supplier->name;
                    }
                }
            }

            $byCategory[$categoryLabel] = [
                'includedProductsArray' => $includedProducts,
                'includedProductsString' => implode(', ', $includedProducts),
                'suppliersArray' => $suppliersWithThisCategory,
                'suppliersString' => implode(', ', $suppliersWithThisCategory),
            ];
        }

        return Inertia::render('AdminSuppliersIndex', [
            'suppliers' => SupplierResource::collection($suppliers),
            'byCategory' => $byCategory,
            'business' => $business,
            //Someone else's suppliers, so the form posts to the admin route
            'adminView' => true,
        ]);
    }
}
