<?php

namespace App\Http\Controllers;

use App\Models\RawMaterialQuote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RawMaterialQuoteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(RawMaterialQuote $rawMaterialQuote)
    {
        Gate::authorize('owned', $rawMaterialQuote);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(RawMaterialQuote $rawMaterialQuote)
    {
        Gate::authorize('owned', $rawMaterialQuote);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, RawMaterialQuote $rawMaterialQuote)
    {
        Gate::authorize('owned', $rawMaterialQuote);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(RawMaterialQuote $rawMaterialQuote): RedirectResponse
    {
        Gate::authorize('owned', $rawMaterialQuote);

        $rawMaterialQuote->delete();

        return back();
    }
}
