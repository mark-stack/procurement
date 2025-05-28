<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DownloadUsageController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        //Formatter
        $nestingFormatter = new NestingFormatter();

        $user = auth()->user();
        $business = $user->business;

        $piecesReadyForBatching = $nestingFormatter->piecesReadyForBatching($business);
        $lettersProjectArray = $nestingFormatter->getLetterProjectArray($piecesReadyForBatching);
        $piecesNested = $nestingFormatter->piecesNested($piecesReadyForBatching, $lettersProjectArray, $business);

        return response()->json([
            'usageData' => $nestingFormatter->usageStats($piecesNested),
        ]);
    }
}
