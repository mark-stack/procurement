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
use Illuminate\Support\Facades\Gate;
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
                $supplierCategories = $supplier->categories();
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
        /*
         * {supplier} is bound by id alone and nothing else here narrowed it, so this route rewrote
         * any supplier in the table - a verified user of one business could rename a merchant
         * attached only to another, and that name goes out on their quote requests and purchase
         * orders. See SupplierPolicy.
         */
        Gate::authorize('owned', $supplier);

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
        /*
         * The user branch below was already harmless - detaching a supplier you never had is a
         * no-op - but it answered "done" to a request for somebody else's row. Refusing says what
         * happened, and it means this route cannot grow a write that assumes the caller's ownership.
         */
        Gate::authorize('owned', $supplier);

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
