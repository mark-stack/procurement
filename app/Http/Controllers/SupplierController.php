<?php

namespace App\Http\Controllers;

use App\Formatters\SupplierFormatter;
use App\Http\Resources\SupplierResource;
use App\Models\Business;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Business $business): Response
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
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Business $business)
    {
        /**
         * Find or create supplier
         */
        $validated = $request->validate([
            'name' => 'required|string',
            'supplier_categories' => [
                'required',
                'array',
                function ($attribute, $value, $fail) {
                    if (! in_array(true, $value, true)) {
                        $fail('Select at least ONE category');
                    }
                },
            ],
        ]);

        $supplier = Supplier::query()->firstOrCreate(
            [
                'name' => $validated['name'],
                'supplier_categories' => serialize($validated['supplier_categories']),
            ],
        );

        /**
         * Attach this supplier to the business (if not admin)
         */
        $business->suppliers()->syncWithoutDetaching([$supplier->id]);

        return back();
    }

    /**
     * Display the specified resource.
     */
    public function show(Supplier $supplier)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Supplier $supplier)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'supplier_categories' => [
                'required',
                'array',
                function ($attribute, $value, $fail) {
                    if (! in_array(true, $value, true)) {
                        $fail('Select at least ONE category');
                    }
                },
            ],
        ]);

        $supplier->update([
            'name' => $validated['name'],
            'supplier_categories' => serialize($validated['supplier_categories']),
        ]);

        return back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Supplier $supplier): RedirectResponse
    {
        /**
         ADMIN: delete if unused
         USER: detach supplier from business
         */
        $user = auth()->user();
        $business = $user->business;

        //Admin
        if ($user->isAdmin()) {
            if (! $supplier->isUsed()) {
                $supplier->businesses()->detach();
                $supplier->delete();
            }
        }
        //User
        else {
            $business->suppliers()->detach($supplier->id);
        }

        return back();
    }
}
