<?php

namespace App\Http\Controllers;

use App\Imports\ExcelImport;
use App\Models\Project;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\CsvService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        /*
         * Excel::toArray() reads the uploaded temp file directly. This used to
         * $file->store('uploads') first and then unlink a copy it never read back -
         * and the unlink sat after the processing, so an admin's rethrown exception
         * left the copy on disk.
         */
        $file = $request->file('excel');
        $csvArray = Excel::toArray(new ExcelImport, $file)[0];

        //Process the CSV
        $supportEmail = config('env.admin_email');
        $errorMsg = "The spreadsheet didn't auto-detect properly. Did the template change? Please email the file to {$supportEmail} to have it re-calibrated quickly.";

        //Users to get nice error message, admin to throw error.
        if ($user->isAdmin()) {
            $return = DB::transaction(fn () => $csvService->processCsv($csvArray, $project, $errorMsg));
        } else {
            /*
             * A transaction so a failure part way through leaves nothing behind, and
             * \Throwable rather than \Exception so a TypeError is caught too - it used
             * to escape as a 500.
             */
            try {
                $return = DB::transaction(fn () => $csvService->processCsv($csvArray, $project, $errorMsg));
            } catch (\Throwable $e) {
                report($e);

                $return = back()->with('warning', $errorMsg);
            }
        }

        return $return;
    }
}
