<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use App\Models\Batch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BatchNestingController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Batch $batch, string $redirect, int $print): Response
    {
        Gate::authorize('owned', $batch);

        //Formatter
        $nestingFormatter = new NestingFormatter();

        //Prerequisite variables
        $user = auth()->user();
        $business = $user->business;

        //View data
        $viewData = $nestingFormatter->nestingViewData('BATCH', $business, $batch);

        /*
         * Only what the two screens read. Handing over the model serialised whatever relations happened
         * to be loaded by then - every piece in the batch, and the user's whole business record.
         */
        $batch->loadMissing('user');

        $viewData = array_merge($viewData, [
            'width' => 900,
            "redirect" => $redirect,
            "batch" => [
                "id" => $batch->id,
                "user" => [
                    "name" => $batch->user?->name,
                    "email" => $batch->user?->email,
                ],
            ],
            "newStockOrdersWithCertificates" => $batch->newStockOrdersWithCertificates(),
            "offcutOrdersWithCertificates" => $batch->offcutOrdersWithCertificates($business),
        ]);

        return $print === 1
            ? Inertia::render('NestingPrintFriendly', $viewData)
            : Inertia::render('QuoteIndex', $viewData);
    }
}
