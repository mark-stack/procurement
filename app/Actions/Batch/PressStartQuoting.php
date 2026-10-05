<?php

namespace App\Actions\Batch;

use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\User;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\NotificationImplementations\NotificationColleagueQuotedImplementation;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * The "Lock before quoting" press, whole: the gate, the nesting, and telling the colleagues.
 *
 * StartQuoting below this is the write - it claims the steel, creates the batch and saves the nest,
 * and it is deliberately ignorant of who is allowed to call it. This is everything around that write
 * which has to happen identically however the button was pressed: reading what is waiting, asking
 * whether this user may close it, turning a throw or a lost race into a sentence, and notifying the
 * project managers whose work has just been taken into somebody else's batch.
 *
 * It was the body of QuoteController::store, and a second caller is what moved it here: the bell's
 * green action on a fabrication deadline warning presses the same button (see
 * NotificationBatchReadyToQuoteImplementation::markGreen). The two have to be the same press - the
 * same gate, the same race handling, the same notifications - because what it does to a business is
 * the same either way, and the half anybody would forget to copy is the notification: a batch that
 * swept up three colleagues' jobs without telling them is the kind of silence that is noticed a week
 * later.
 *
 * What the two callers do differ on is how a refusal reads, which is why this reports rather than
 * redirects: the controller answers the page's own form with an error on the field, the bell sends
 * somebody back with a sentence. Neither is this action's business.
 *
 * "allowed" is the two refusals that are about the caller rather than about the steel - a read-only
 * account and the prerequisite gate - which the page's own POST owes a 403. Both carry a refusal
 * sentence as well, so a caller that shows the sentence and nothing else can read that one field and
 * be right every time.
 */
class PressStartQuoting
{
    use AsAction;

    /**
     * @return array{allowed: bool, batch: ?Batch, refusal: ?string}
     */
    public function handle(User $user): array
    {
        $business = $user->business;

        /*
         * A read-only account cannot buy steel.
         *
         * BillingWriteAccessMiddleware is what normally says so - it refuses by HTTP verb, so every
         * POST in the application is covered without anybody having to remember - and this press has
         * exactly one way in that the middleware does not guard: the bell, whose status route is
         * deliberately outside the gate because dismissing a notification changes nothing anybody is
         * billed for. That stopped being the whole truth when the green action on a fabrication
         * deadline warning became the press itself, so the rule is asked here as well.
         *
         * The same answer FabricationDeadlineQuoting::projectsToWarn gives for the same reason: it
         * will not chase a read-only business to press a button that cannot work. A row sent before
         * the trial lapsed can still be sitting in the bell, though, which is what this catches.
         */
        if (! $business->allowsWrites()) {
            return [
                'allowed' => false,
                'batch' => null,
                'refusal' => $business->billingState()->everPaid()
                    ? 'Your subscription has ended, so the account is read-only and nothing can be'
                        .' quoted. Everything you have is still here to read and print.'
                    : 'Your free trial has ended, so the account is read-only and nothing can be'
                        .' quoted. Everything you have is still here to read and print.',
            ];
        }

        /*
         * What is waiting, and whose. Read before anything is written - projectsReadyForBatching is
         * worked out from the pieces, and the pieces are what the write below moves onto a batch.
         */
        $piecesReadyForBatching = (new NestingFormatter)->piecesReadyForBatching($business);
        $projectsReadyForBatching = $business->projectsReadyForBatching($piecesReadyForBatching);

        /*
         * The one gate, asked of the user actually pressing. Reported rather than aborted, because
         * only the caller knows what a 403 should look like where it is standing - the page's form
         * wants the status, the bell wants a sentence.
         */
        $allowed = (new PrerequisiteConditions)->startQuoting(
            $user,
            $projectsReadyForBatching,
            $piecesReadyForBatching,
        );

        if (! $allowed) {
            return [
                'allowed' => false,
                'batch' => null,
                'refusal' => 'These materials are no longer yours to quote - a colleague has taken'
                    .' them into a batch, or the projects waiting on them have moved on.',
            ];
        }

        try {
            /*
             * The locking, the batch, the nesting and the order approvals all live in the action. The
             * race it guards against is not hypothetical: a double click, a second tab, a retried
             * request and - now - a bell pressed on one screen while the card is pressed on another
             * all arrive here holding the same list of unbatched pieces.
             */
            $batch = StartQuoting::run($user, $business, $piecesReadyForBatching);
        } catch (Throwable $e) {
            /*
             * The transaction rolled back, so no batch exists. Said out loud: a silent redirect made
             * a failed press look identical to a successful one.
             */
            Log::error('Failed to start quoting', [
                'user_id' => $user->id,
                'exception' => $e,
            ]);

            return [
                'allowed' => true,
                'batch' => null,
                'refusal' => 'Could not start quoting. The nesting was not saved, so nothing has changed.',
            ];
        }

        /*
         * Somebody else got the steel. Not an error - their batch is a perfectly good batch, and the
         * page behind this already shows it - so it reads as news, and it says where the projects
         * went rather than leaving the presser to work out why the column emptied itself.
         */
        if ($batch === null) {
            return [
                'allowed' => true,
                'batch' => null,
                'refusal' => 'These projects were taken into a batch a moment ago, either by a colleague'
                    .' or by a second press of this button. Nothing was quoted twice - look in Quoting'
                    .' for the batch that has them.',
            ];
        }

        /*
         * Tell the other project managers. This press takes every project waiting into one batch
         * owned by whoever pressed it, which fixes their suppliers and delivery dates and takes Edit,
         * Done and BOM upload away from them - and the confirmation naming whose work is being taken
         * is shown only to the person taking it.
         *
         * After the transaction, so a rolled-back batch notifies nobody. Read off $batch->projects()
         * rather than $projectsReadyForBatching because the pieces are what actually got nested, and
         * the two sets are known to differ: a project awaiting clarification is excluded from
         * projectsReadyForBatching while its already-matched pieces are swept in anyway. Its owner is
         * the one who most needs telling.
         */
        (new NotificationColleagueQuotedImplementation)->notifyAffectedProjectManagers($batch, $user);

        return ['allowed' => true, 'batch' => $batch, 'refusal' => null];
    }
}
