<?php

namespace App\Http\Controllers;

use App\Actions\Batch\StartQuoting;
use App\Formatters\NestingFormatter;
use App\Http\Requests\UpdateQuoteRequest;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Models\Quote;
use App\Services\NotificationImplementations\NotificationColleagueQuotedImplementation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class QuoteController extends Controller
{
    /*
     * index, create, show, edit and destroy are gone with the route registrations that reached them.
     * The first four had no body at all; destroy had a Gate call and nothing else, so a DELETE on a
     * quote authorised the caller, deleted nothing and answered 200 - a success as far as anything
     * calling it could tell. A batch's quotes are unwound by BatchController::destroy.
     */
    public function store(Request $request): RedirectResponse
    {
        //Prerequisite variables
        $user = auth()->user();
        $business = $user->business;

        //Prerequisites
        $piecesReadyForBatching = (new NestingFormatter())->piecesReadyForBatching($business);
        $projectsReadyForBatching = $business->projectsReadyForBatching($piecesReadyForBatching); //Note get this before updating pieces because it gets modified

        //Prerequisite conditions
        $prerequisiteStartQuoting = (new PrerequisiteConditions())->startQuoting(
            $user,
            $projectsReadyForBatching,
            $piecesReadyForBatching,
        );

        abort_if(!$prerequisiteStartQuoting,403);

        try {
            /*
             * The locking, the batch, the nesting and the order approvals all live in the action. It
             * is this controller's alone again now that App\Services\FabricationDeadlineQuoting only
             * warns rather than pressing this button on a schedule - but the race it guards against
             * was never only about that: a double click, a second tab and a retried request all
             * arrive here holding the same list of unbatched pieces.
             */
            $batch = StartQuoting::run($user, $business, $piecesReadyForBatching);
        } catch (Throwable $e) {
            /*
             * The transaction rolled back, so no batch exists. Say so - redirecting silently made a
             * failed "start quoting" look identical to a successful one.
             */
            Log::error('Failed to start quoting', [
                'user_id' => $user->id,
                'exception' => $e,
            ]);

            return back()->withErrors([
                'batch' => 'Could not start quoting. The nesting was not saved, so nothing has changed.',
            ]);
        }

        /*
         * Somebody else got the steel. Not an error - their batch is a perfectly good batch, and the
         * board behind this redirect already shows it - so it reads as news, and it says where the
         * projects went rather than leaving the presser to work out why the column emptied itself.
         */
        if ($batch === null) {
            return back()->withErrors([
                'batch' => 'These projects were taken into a batch a moment ago, either by a colleague'
                    .' or by a second press of this button. Nothing was quoted twice - look in Quoting'
                    .' for the batch that has them.',
            ]);
        }

        /*
         * Tell the other project managers. This button takes every project in the Nesting column into
         * one batch owned by whoever pressed it, which fixes their suppliers and delivery dates and
         * takes Edit, Archive and BOM upload away from them - and the confirmation naming whose work
         * is being taken is shown only to the person taking it.
         *
         * After the transaction, so a rolled-back batch notifies nobody. Read off $batch->projects()
         * rather than $projectsReadyForBatching because the pieces are what actually got nested, and
         * the two sets are known to differ: a project awaiting clarification is excluded from
         * projectsReadyForBatching while its already-matched pieces are swept in anyway. Its owner is
         * the one who most needs telling.
         */
        (new NotificationColleagueQuotedImplementation)->notifyAffectedProjectManagers($batch, $user);

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
