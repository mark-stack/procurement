<?php

namespace App\Http\Controllers;

use App\Formatters\QuoteFormatter;
use App\Models\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class DownloadQuotesDataController extends Controller
{
    /**
     * Quotes and orders for one batch, for the modal on the projects board.
     *
     * This used to be a page of its own at /quote-order-management/{batch} that only ever drew a
     * modal. Fetched rather than passed as a dashboard prop because quotesData() provisions a quote
     * per supplier the first time it runs - doing that for every batch on every board render would
     * mint quotes for batches nobody opened.
     */
    public function __invoke(Batch $batch): JsonResponse
    {
        Gate::authorize('owned', $batch);

        $user = auth()->user();

        return response()->json([
            'quotesData' => (new QuoteFormatter)->quotesData($user->business, $batch, $user),
        ]);
    }
}
