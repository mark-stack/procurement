<?php

namespace App\Http\Controllers;

use App\Actions\Supplier\AttachSupplierToBusiness;
use App\Formatters\SupplierFormatter;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
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
    public function index(Request $request): Response
    {
        $business = $this->businessOf($request);

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
            //Your own suppliers, so the form posts to the route that takes no business
            'adminView' => false,
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
    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        $business = $this->businessOf($request);

        $validated = $request->validated();

        AttachSupplierToBusiness::run(
            $business,
            $validated['name'],
            $validated['supplier_categories'],
        );

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
    public function update(UpdateSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $validated = $request->validated();

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
