<?php

namespace App\Http\Controllers;

use App\Models\CatalogueReview;
use App\Services\CatalogueTrust;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Records that somebody has read the master catalogue, and what was still wrong with it when they
 * did.
 *
 * It changes nothing about the catalogue. Nothing here corrects a row, nothing here deprecates one,
 * and a review is not a gate anything waits behind - the products are live and being matched against
 * either way. What it answers is the question ISO 9001 7.1.5 asks of a measuring instrument and the
 * catalogue could not answer at all: when was this last checked, by whom, and what did they find.
 *
 * The findings are counted here rather than typed in. An admin who recorded "all good" over a
 * catalogue with thirty unmeasured rows in it would be recording a claim; the counts are a reading,
 * taken at the moment of the review, off the same report the screen was showing them. Note is
 * optional and genuinely optional - looking IS the record, and an empty box should not be a reason
 * not to press the button.
 *
 * Append-only, and not idempotent, which is where this differs from marking one template reviewed
 * (AdminTemplateReviewController, where a second press is not a correction). A catalogue is reviewed
 * again every time somebody goes through it, and the series is the point: "reviewed every quarter"
 * is a claim about a sequence of dates, and a single column that got overwritten could never make
 * it.
 */
class AdminCatalogueReviewController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $trust = new CatalogueTrust;

        $review = CatalogueReview::query()->create([
            'reviewed_at' => now(),
            'user_id' => auth()->id(),
            'note' => $validated['note'] ?? null,
            'products_reviewed' => $trust->productsConsidered(),
            'untrusted' => $trust->outstandingByReason(),
        ]);

        $outstanding = array_sum($review->untrusted);

        return back()->with('materials', [
            'ok' => true,
            'message' => $outstanding === 0
                ? 'Catalogue review recorded. Nothing outstanding.'
                : sprintf(
                    'Catalogue review recorded against %d products, %d finding%s still outstanding.',
                    $review->products_reviewed,
                    $outstanding,
                    $outstanding === 1 ? '' : 's',
                ),
        ]);
    }
}
