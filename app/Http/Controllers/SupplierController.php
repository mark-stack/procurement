<?php

namespace App\Http\Controllers;

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
        $suppliers = $business->suppliers()->orderBy("name")->get();

        return Inertia::render('AdminSuppliersIndex',[
            "suppliers" => SupplierResource::collection($suppliers),
            "categories" => config('supplier_groups'),
            "business" => $business,
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
    public function store(Request $request,Business $business)
    {
        /**
         * Find or create supplier
         */
        $validated = $request->validate([
            'name' => 'required|string',
            'category' => 'required|string',
        ]);

        $supplier = Supplier::query()->firstOrCreate(
            [
                "name" => $validated['name'],
                'category' => $validated['category'],
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
        $supplier->update($request->all());

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
        if($user->isAdmin()){
            if(!$supplier->isUsed()){
                $supplier->businesses()->detach();
                $supplier->delete();
            }
        }
        //User
        else{
            $business->suppliers()->detach($supplier->id);
        }

        return back();
    }
}
