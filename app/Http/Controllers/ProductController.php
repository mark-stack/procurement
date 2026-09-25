<?php

namespace App\Http\Controllers;

use App\Imports\ExcelImport;
use App\Models\Project;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\CsvService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Project $project): RedirectResponse
    {
        /**
         * Single purpose: extract and save materials in a CSV material list
         */
        Gate::authorize('owned', $project);

        $user = auth()->user();
        $business = $user->business;

        //Prerequisite conditions
        $prerequisiteUploadMaterials = (new PrerequisiteConditions())->uploadMaterials(
            $user,
            $project,
        );
        abort_if(!$prerequisiteUploadMaterials,403);

        //Validate
        $request->validate([
            'excel' => 'required|mimes:xlsx,xls|max:2048',
        ]);

        //Services
        $csvService = new CsvService;

        //Store the uploaded file temporarily
        $file = $request->file('excel');
        $path = $file->store('uploads');

        //Read the CSV
        $csvArray = Excel::toArray(new ExcelImport, $file)[0];

        //Process the CSV
        $errorMsg = "The spreadsheet didn't auto-detect properly. Did the template change? Please email the file to mark.laravel.coder@gmail.com to have it re-calibrated quickly.";

        //Users to get nice error message, admin to throw error.
        if ($user->isAdmin()) {
            $return = $csvService->processCsv($csvArray, $project, $errorMsg);
        } else {
            try {
                $return = $csvService->processCsv($csvArray, $project, $errorMsg);
            } catch (\Exception $e) {
                $return = back()->with('warning', $errorMsg);
            }
        }

        // Delete the file after processing
        unlink(storage_path("app/private/{$path}"));

        return $return;
    }
}
