<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTemplateRequest;
use App\Http\Requests\UpdateTemplateRequest;
use App\Models\Business;
use App\Models\Template;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Business $business): Response
    {
        return Inertia::render('AdminTemplatesIndex', [
            'templates' => $business->templates,
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
    public function store(StoreTemplateRequest $request, Business $business): RedirectResponse
    {
        $validated = $request->validated();

        $data = array_merge($validated, [
            'business_id' => $business->id,
        ]);

        Template::create($data);

        return back();
    }

    /**
     * Display the specified resource.
     */
    public function show(Business $business, Template $template)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Business $business, Template $template)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTemplateRequest $request, Business $business, Template $template): RedirectResponse
    {
        $validated = $request->validated();

        $template->update($validated);

        return back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Business $business, Template $template): RedirectResponse
    {
        $template->delete();

        return back();
    }
}
