<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use App\Models\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DownloadNesting extends Controller
{
    public function __invoke(Request $request, int $batch_id): JsonResponse
    {
        /**
         * batch_id = 0 represents "ready to nest" which has no batch object created yet
         */
        $batch = $batch_id === 0 ? null : Batch::findOrFail($batch_id);

        if ($batch) {
            Gate::authorize('owned', $batch);
        }

        //Formatter
        $nestingFormatter = new NestingFormatter();

        //Prerequisite variables
        $user = auth()->user();
        $business = $user->business;

        //View data
        $batchData = $batch
            //Batch nesting
            ? $nestingFormatter->nestingViewData('BATCH', $business, $batch)

            //Suggested
            : $nestingFormatter->nestingViewData('SUGGESTED', $business, null);

        return response()->json([
            'downloadedNestingData' => [
                'batch_id' => $batch ? $batch->id : 0,
                'data' => $batchData,
            ],
        ]);
    }
}
