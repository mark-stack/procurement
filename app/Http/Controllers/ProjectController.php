<?php

namespace App\Http\Controllers;

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
            "projects" => $projects,
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
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required',
            "tendering_stage" => 'required',
            "reference" => "required",
        ]);

        Project::create([
            "name" => $validated["name"],
            "user_id" => auth()->user()->id,
            "tendering_stage" => $validated["tendering_stage"],
            "reference" => $validated["reference"],
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
            "tendering_stage" => 'required',
            "reference" => "required",
        ]);

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
