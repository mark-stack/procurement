<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuggestedNestingController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        //Formatter
        $nestingFormatter = new NestingFormatter();

        //Prerequisite variables
        $user = auth()->user();
        $business = $user->business;

        //View data
        $viewData = $nestingFormatter->nestingViewData('SUGGESTED', $business, null);

        $viewData = array_merge($viewData, [
            'width' => 900,
            "redirect" => "current",
        ]);

        return Inertia::render('QuoteIndex', $viewData);
    }
}
