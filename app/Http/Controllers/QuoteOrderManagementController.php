<?php

namespace App\Http\Controllers;

use App\Formatters\QuoteFormatter;
use App\Models\Batch;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class QuoteOrderManagementController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Batch $batch): Response
    {
        Gate::authorize('owned', $batch);

        $user = auth()->user();

        /**
         * "Quotes and orders"
         * 1) Assign a letter to each project. A, B, C, etc
         * 2) Get all nested pieces
         * 3) Group nested pieces by nesting algorithm. e.g "meterage"
         * 4) get list of supplier categories available to the business
         * 5) filter out categories not features in the nesting list
         */
        $quotesData = (new QuoteFormatter)->quotesData($user->business, $batch, $user);

        return Inertia::render('QuoteOrderManagement', [
            'width' => 850,
            'quotesData' => $quotesData,
        ]);
    }
}
