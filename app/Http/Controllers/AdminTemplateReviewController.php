<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Template;
use Illuminate\Http\RedirectResponse;

/**
 * Records that a person has read a template a customer's upload wrote for itself.
 *
 * It changes nothing about importing. A machine-written template is live from the moment it passes
 * its test, because the point of the whole feature is that the next upload of that format needs
 * nobody - gating it on a review would put us back where onboarding was, with a customer waiting on
 * an admin. This is the opposite end: an audit of templates in production that no human has seen.
 *
 * So the only thing it writes is who looked and when. An admin who disagrees with what they are
 * looking at does not press this - they edit the template, or deactivate it, both of which already
 * exist and both of which are recorded by RecordsChanges.
 */
class AdminTemplateReviewController extends Controller
{
    public function __invoke(Business $business, Template $template): RedirectResponse
    {
        //Idempotent: the first review is the one that counts, and a second press is not a correction
        if ($template->reviewed_at !== null) {
            return back();
        }

        $template->update([
            'reviewed_at' => now(),
            'reviewed_by_user_id' => auth()->id(),
        ]);

        return back()->with('success', sprintf('"%s" marked as reviewed.', $template->name));
    }
}
