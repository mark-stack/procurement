<?php

namespace App\Http\Controllers;

use App\Actions\Batch\SaveNesting;
use App\Actions\OrderApproval\CreatePendingOrderApprovals;
use App\Actions\Piece\AttachPiecesToBatch;
use App\Formatters\NestingFormatter;
use App\Http\Requests\UpdateQuoteRequest;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Models\Batch;
use App\Models\Quote;
use App\Services\NotificationImplementations\NotificationColleagueQuotedImplementation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class QuoteController extends Controller
{
    /**
     * @deprecated
     */
    public function index() {

    }

    public function create()
    {
        //
    }

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
            $batch = DB::transaction(function () use($business,$user,$piecesReadyForBatching){
                /*
                 * Create batch
                 */
                $batch = Batch::create([
                    'user_id' => $user->id,
                ]);

                //Attach pieces to batch
                AttachPiecesToBatch::run($piecesReadyForBatching, $batch);

                /*
                 * Create pending order approvals - after the pieces, because the approvals are read off
                 * them. See CreatePendingOrderApprovals for why they are not read off
                 * $projectsReadyForBatching, which is a smaller set than what actually got nested.
                 */
                CreatePendingOrderApprovals::run($batch);

                //Save the current nesting state (points offcuts to new batch)
                SaveNesting::run($piecesReadyForBatching, $batch, $business);

                return $batch;
            });
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

    public function show(Quote $quote)
    {
        Gate::authorize('owned', $quote);
    }

    public function edit(Quote $quote)
    {
        Gate::authorize('owned', $quote);
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

    public function destroy(Quote $quote)
    {
        Gate::authorize('owned', $quote);
    }
}
