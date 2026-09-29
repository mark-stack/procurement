<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\TemplateProposalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Reads a sample spreadsheet and fills in the template form from it.
 *
 * Writes nothing. The proposal comes back as a flash prop, the admin looks at it next to the checks
 * that were run against the file, and the ordinary store request is what saves anything - so a
 * suggestion can never become a record without somebody agreeing to it.
 */
class AdminTemplateProposalController extends Controller
{
    public function __invoke(Request $request, Business $business, TemplateProposalService $proposals): RedirectResponse
    {
        $request->validate([
            /*
             * Same ceiling as a material list upload (StoreProjectRequest). csv is allowed here and
             * not there because a sample is often a sheet saved out by hand to show us the shape of
             * a report.
             */
            'sample' => ['required', 'file', 'mimes:xls,xlsx,csv', 'max:1024'],
        ], [
            'sample.required' => 'Choose a spreadsheet to read.',
            'sample.mimes' => 'The sample must be a spreadsheet (.xls, .xlsx or .csv).',
            'sample.max' => 'The sample must be under 1Mb.',
        ]);

        return back()->with(
            'templateProposal',
            $proposals->propose($request->file('sample'), $business),
        );
    }
}
