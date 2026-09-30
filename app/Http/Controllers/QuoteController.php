<?php

namespace App\Http\Controllers;

use App\Actions\Batch\SaveNesting;
use App\Actions\OrderApproval\CreatePendingOrderApprovals;
use App\Actions\Piece\AttachPiecesToBatch;
use App\Formatters\NestingFormatter;
use App\Http\Requests\UpdateQuoteRequest;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Models\Batch;
use App\Models\Piece;
use App\Models\Quote;
use App\Services\NotificationImplementations\NotificationColleagueQuotedImplementation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use RuntimeException;
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
            $batch = DB::transaction(function () use($business,$user,$piecesReadyForBatching){
                /*
                 * Claim the steel before anything is created.
                 *
                 * Everything above this line is a read, and it happened outside the transaction, so
                 * two people pressing "Start quoting" seconds apart - or one person double clicking,
                 * or a second tab, or a retried request - both got here holding the same list of
                 * unbatched pieces. Nothing then stopped the second one: the batch was created first
                 * and the pieces were moved onto it by id, off whichever batch already had them.
                 *
                 * What that left is not recoverable by anything in the application. The first batch
                 * kept its order approvals, its saved nesting and the offcuts SaveNesting had already
                 * consumed against it, while the pieces those offcuts were cut for now belonged to the
                 * second batch - two batches in the Quoting column for one set of projects, one of
                 * them empty, and the same steel quoted and ordered twice.
                 *
                 * FOR UPDATE makes the second caller wait here until the first commits, and it then
                 * reads the batch_id the first one wrote. A short count means somebody got there
                 * first, and this returns before a single row is written.
                 */
                $stillUnbatched = Piece::query()
                    ->whereIn('id', $piecesReadyForBatching->pluck('id'))
                    ->whereNull('batch_id')
                    ->lockForUpdate()
                    ->count();

                if ($stillUnbatched !== $piecesReadyForBatching->count()) {
                    return null;
                }

                /*
                 * Create batch
                 */
                $batch = Batch::create([
                    'user_id' => $user->id,
                ]);

                /*
                 * Attach pieces to batch.
                 *
                 * The count is checked rather than assumed. The lock above is what actually prevents
                 * the race; this is the same question asked once more by the write itself, so a
                 * database or driver that does not honour the lock still cannot get past it. Throwing
                 * rolls the batch back.
                 */
                $claimed = AttachPiecesToBatch::run($piecesReadyForBatching, $batch);

                if ($claimed !== $piecesReadyForBatching->count()) {
                    throw new RuntimeException(
                        'Claimed '.$claimed.' of '.$piecesReadyForBatching->count().' pieces for batch '.$batch->id
                    );
                }

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
