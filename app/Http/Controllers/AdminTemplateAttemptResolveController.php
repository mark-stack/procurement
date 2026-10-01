<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\TemplateLearningAttempt;
use Illuminate\Http\RedirectResponse;

/**
 * Closes a failed learning attempt by hand.
 *
 * Most of them close themselves: recording a template re-reads every unresolved sample for the
 * business and resolves the ones that template now finds - see TemplateLearningService::
 * resolveSettled(). This is for the rest, and they are the ordinary cases rather than the exotic
 * ones: a customer who uploaded their accounts export by mistake, a scanned drawing saved as .xlsx,
 * the same file three times over, a format we have decided not to support.
 *
 * It resolves with no template, which is the honest record of what happened: somebody looked at this
 * and there was nothing to record. The spreadsheet is deleted with it - see
 * TemplateLearningAttempt::resolve() for why a sample does not outlive the reason it was kept.
 */
class AdminTemplateAttemptResolveController extends Controller
{
    public function __invoke(Business $business, TemplateLearningAttempt $attempt): RedirectResponse
    {
        abort_unless($attempt->business_id === $business->id, 404);

        //Already closed. Not an error - a second tab, a double click - and nothing to redo
        if ($attempt->resolved_at !== null) {
            return back();
        }

        $attempt->resolve(null, auth()->user());

        return back()->with('success', 'Attempt closed. The copy of the customer\'s spreadsheet has been deleted with it.');
    }
}
