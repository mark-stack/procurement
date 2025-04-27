<?php

namespace App\Http\Controllers;

use App\Http\Resources\OffcutResource;
use App\Models\Offcut;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OffcutController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        $user = auth()->user();
        $business = $user->business;

        return Inertia::render('OffcutsIndex', [
            'offcuts' => OffcutResource::collection($business->availableOffcuts()->get()),
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
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Offcut $offcut)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Offcut $offcut)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Offcut $offcut)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Offcut $offcut)
    {
        //
    }
}
