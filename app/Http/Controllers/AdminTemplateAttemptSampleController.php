<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\TemplateLearningAttempt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The spreadsheet a customer uploaded that could not be read, handed to an admin.
 *
 * It exists so that the failure can be reproduced: the admin downloads this, drops it into the Test
 * box on the templates screen with the proposal that failed already in the form, and fixes whichever
 * cell is wrong. Before this they had to ask the customer to email it.
 *
 * The file is on the local disk and not the public one, so this is the only way to it, and it is
 * behind AdminMiddleware and 'verified' with the rest of the admin panel. {business} is in the path
 * and scoped to the attempt for the reason the screenshot route is: without it a mistyped id serves
 * one customer's bill of materials from another customer's page.
 */
class AdminTemplateAttemptSampleController extends Controller
{
    public function __invoke(Business $business, TemplateLearningAttempt $attempt): StreamedResponse
    {
        abort_unless($attempt->business_id === $business->id, 404);

        /*
         * A row whose sample is gone is a 404 rather than an empty download. The file is deleted when
         * the attempt is resolved and pruned after the retention window, so this is the ordinary end
         * of an attempt's life, not a fault.
         */
        abort_unless($attempt->hasSample(), 404);

        return Storage::disk(TemplateLearningAttempt::DISK)->download(
            (string) $attempt->sample_path,
            //What the customer called it, rather than the attempt id this is stored under
            $attempt->file_name,
        );
    }
}
