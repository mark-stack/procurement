<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): Response
    {
        $user = auth()->user();
        $projects = $user->projects()
            ->active()
            ->latest()
            ->get();

        return Inertia::render('Dashboard',[
            "projects" => ProjectResource::collection($projects),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        dd("create");
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required',
            'awarded' => 'required|boolean',
            "reference" => 'nullable|required_if:awarded,true',
            'date_materials_required' => 'nullable|required_if:awarded,true|date|after:today',
        ]);

        //Clear reference and date if not awarded
        if(!$validated["awarded"]){
            $validated["reference"] = null;
            $validated["date_materials_required"] = null;
        }

        Project::create([
            "name" => $validated["name"],
            "user_id" => auth()->user()->id,
            "awarded" => $validated["awarded"],
            "reference" => $validated["reference"],
            "date_materials_required" => $validated["date_materials_required"],
        ]);

        return back();
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Project $project)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required',
            'awarded' => 'required|boolean',
            "reference" => 'nullable|required_if:awarded,true',
            'date_materials_required' => 'nullable|required_if:awarded,true|date|after:today',
        ]);

        //Clear reference and date if not awarded
        if(!$validated["awarded"]){
            $validated["reference"] = null;
            $validated["date_materials_required"] = null;
        }

        $project->update($validated);

        return back();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project)
    {
        $project->archive = true;
        $project->save();

        return back();
    }
}
