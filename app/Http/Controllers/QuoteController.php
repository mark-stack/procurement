<?php

namespace App\Http\Controllers;

use App\Actions\Batch\PressStartQuoting;
use App\Http\Requests\UpdateQuoteRequest;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Models\Quote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class QuoteController extends Controller
{
    /*
     * index, create, show, edit and destroy are gone with the route registrations that reached them.
     * The first four had no body at all; destroy had a Gate call and nothing else, so a DELETE on a
     * quote authorised the caller, deleted nothing and answered 200 - a success as far as anything
     * calling it could tell. A batch's quotes are unwound by BatchController::destroy.
     */
    /**
     * The Nesting page's "Lock batch for quoting", pressed from the open batch card.
     *
     * The press itself is PressStartQuoting, which the bell's green action on a fabrication deadline
     * warning runs too - same gate, same nesting, same notifications. All that is left here is how a
     * refusal reads to the form that posted: a 403 for the gate, which is what the page's own button
     * is drawn from and so should never be seen, and an error on the batch field for the two that
     * can happen to anybody.
     *
     * back(), because the card's presser is already looking at the page they want to see: they
     * chose the moment, and the batch they just made is on it. The bell's green action is the one
     * press that lands somewhere else, and it runs PressStartQuoting itself rather than posting
     * here - see NotificationBatchReadyToQuoteImplementation::markGreen.
     */
    public function store(Request $request): RedirectResponse
    {
        $press = PressStartQuoting::run(auth()->user());

        abort_if(! $press['allowed'], 403);

        if ($press['refusal'] !== null) {
            return back()->withErrors(['batch' => $press['refusal']]);
        }

        return back();
    }

    public function update(UpdateQuoteRequest $request, Quote $quote): RedirectResponse
    {
        Gate::authorize('owned', $quote);

        $validated = $request->validated();

        //"boolean" accepts 1/0/"1"/"0" as well as true/false, so settle on the real thing once
        $validated['quote_sent'] = $request->boolean('quote_sent');

        //Attempting to mark as sent
        if(!$quote->quote_sent && $validated['quote_sent']){
            $canMarkQuoteAsSent = (new PrerequisiteConditions())->markQuoteAsSent(
                auth()->user(),
                $quote,
            );
            abort_if(!$canMarkQuoteAsSent,403,"Cannot mark quote as sent");
        }
        //Attempting to undo mark as sent
        if($quote->quote_sent && !$validated['quote_sent']){
            $canUndoMarkQuoteAsSent = (new PrerequisiteConditions())->undoMarkQuoteAsSent(
                auth()->user(),
                $quote,
            );
            abort_if(!$canUndoMarkQuoteAsSent,403,"Cannot undo mark quote as sent");
        }

        $quote->update($validated);

        return back();
    }
}
