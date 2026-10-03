<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuggestedNestingController extends Controller
{
    /**
     * What everything waiting would nest into, on either of the two nesting screens.
     *
     * The same pair BatchNestingController renders, off the same view data, so the open batch is read
     * the way a live batch is: the modal on the board, and the printable sheet from /nesting.
     *
     * There is no batch row behind this one - nothing has been quoted, so nothing has been bought -
     * which is the whole of what the sheet has to say differently. It carries no batch number, nobody
     * generated it, and there are no material certificates against it, so those are sent as nothing at
     * all rather than as a batch's empty ones. And it is stamped DO NOT CUT: this nest is a suggestion
     * that is re-run on every load, and the one it is bought and cut on is the one written when
     * quoting starts (Actions/Batch/SaveNesting) - a sheet off this page taken to the saw would be
     * cutting to a plan that no longer exists.
     */
    public function __invoke(Request $request, int $print = 0): Response
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

        if ($print !== 1) {
            return Inertia::render('QuoteIndex', $viewData);
        }

        return Inertia::render('NestingPrintFriendly', array_merge($viewData, [
            //No batch row yet, which is what the sheet draws its "not bought yet" wording off
            "batch" => null,
            "watermark" => "Do not cut",
        ]));
    }
}
