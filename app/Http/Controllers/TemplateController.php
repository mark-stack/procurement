<?php

namespace App\Http\Controllers;

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
        return Inertia::render('AdminTemplatesIndex',[
            "templates" => $business->templates,
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
    public function store(Request $request,Business $business): RedirectResponse
    {
        $validated = $request->validate([
            "name" => ['required','string'],
            "first_description_cell" => ['required', 'string', 'min:2','max:5'],
            "first_material_cell" => ['nullable', 'string', 'min:2','max:5'],
            "first_length_required_cell" => ['nullable', 'string', 'min:2','max:5'],
            "first_width_required_cell" => ['nullable', 'string', 'min:2','max:5'],
            "first_sub_qty_cell" => ['required', 'string', 'min:2','max:5'],
            "first_unit_rate_cell" => ['required', 'string', 'min:2','max:5'],
            "random_cell_1" => ['required', 'string', 'min:2','max:5'],
            "random_cell_text_1" => ['required', 'string'],
            "random_cell_2" => ['required', 'string', 'min:2','max:5'],
            "random_cell_text_2" => ['required', 'string'],
            "screenshot" => ['required', 'string', 'min:50'],
            "length_width_units" => ["required","string"],
            "active" => 'required',
        ]);

        $data = array_merge($validated,[
            "business_id" => $business->id
        ]);

        Template::create($data);

        return back();
    }

    /**
     * Display the specified resource.
     */
    public function show(Template $template)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Template $template)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Template $template): RedirectResponse
    {
        $validated = $request->validate([
            "name" => ['required','string'],
            "first_description_cell" => ['required', 'string', 'min:2','max:5'],
            "first_material_cell" => ['nullable', 'string', 'min:2','max:5'],
            "first_length_required_cell" => ['nullable', 'string', 'min:2','max:5'],
            "first_width_required_cell" => ['nullable', 'string', 'min:2','max:5'],
            "first_sub_qty_cell" => ['required', 'string', 'min:2','max:5'],
            "first_unit_rate_cell" => ['required', 'string', 'min:2','max:5'],
            "random_cell_1" => ['required', 'string', 'min:2','max:5'],
            "random_cell_text_1" => ['required', 'string'],
            "random_cell_2" => ['required', 'string', 'min:2','max:5'],
            "random_cell_text_2" => ['required', 'string'],
            "screenshot" => ['required', 'string', 'min:50'],
            "length_width_units" => ["required","string"],
            "active" => 'required',
        ]);

        $template->update($validated);

        return back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Template $template): RedirectResponse
    {
        $template->delete();

        return back();
    }
}
