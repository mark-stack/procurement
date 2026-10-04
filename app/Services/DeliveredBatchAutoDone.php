<?php

namespace App\Services;

use App\Actions\Batch\MarkAsDone;
use App\Enums\SupplierGroupEnums;
use App\Models\Batch;
use App\Models\Business;
use App\Sandbox\Sandbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Move a finished batch off the board for a business that has stopped looking at it.
 *
 * The Delivering column is the last one, and a card sits in it after the steel has arrived with
 * nothing left to do but say so: "Move to done" is a button whose only job is to admit the job is
 * over. Nobody presses it on the day. So the column silts up with batches whose deliveries came in
 * weeks ago, and the board stops being a list of work and becomes a list of work plus everything
 * that was ever work.
 *
 * So when every sent order on a batch has been booked in, this waits DAYS_AFTER_DELIVERY and then
 * closes it, exactly as the button does.
 *
 * The wait is the whole point of the delay rather than an arbitrary pause. The days after a delivery
 * are when the certificates get chased, the docket gets queried and the short-shipped bundle gets
 * argued about, and every one of those conversations happens with the card in front of you. Five days
 * is long enough for them and short enough that the column still reads as the current month's work.
 *
 * Deliberately conservative, because nothing in the application re-opens a closed batch: see
 * doneDueDate() for the four things that hold a card on the board indefinitely rather than guess.
 */
class DeliveredBatchAutoDone
{
    /**
     * How long a fully delivered batch stays on the board before it closes itself.
     *
     * Five days, the figure asked for, and the same window App\Services\FabricationDeadlineQuoting
     * uses at the other end of the board - there is one "a few working days" in this application and
     * it is worth the two constants reading the same.
     */
    public const int DAYS_AFTER_DELIVERY = 5;

    /**
     * @return array<int, Batch> the batches closed, for the command to report
     */
    public function sweep(): array
    {
        $done = [];

        /*
         * Live rows only. The sandbox global scope resolves to "sandbox_user_id is null" when no test
         * mode is active, and nothing here is running inside a request - but say so, because closing a
         * batch cannot be undone and doing it to somebody's test data would look exactly like doing it
         * to their real data.
         */
        if (Sandbox::isActive()) {
            return $done;
        }

        foreach (Business::query()->get() as $business) {
            try {
                $done = array_merge($done, $this->sweepBusiness($business));
            } catch (Throwable $e) {
                /*
                 * One business's bad data does not stop the rest, for the reason the fabrication
                 * deadline warnings give: the businesses behind this one in the list would otherwise
                 * be taken down with it, silently, on a schedule nobody is watching.
                 */
                Log::error('Delivered batch auto-done failed', [
                    'business_id' => $business->id,
                    'exception' => $e,
                ]);

                continue;
            }
        }

        return $done;
    }

    /**
     * @return array<int, Batch>
     */
    public function sweepBusiness(Business $business): array
    {
        $done = [];

        /*
         * A read-only account is read-only here too. Closing a batch is a write the user could not
         * make themselves while their trial is lapsed (BillingWriteAccessMiddleware), and it is not
         * one they could undo once their billing is sorted out - so a card must not disappear off the
         * board of a business that has lost the ability to put it back.
         */
        if (! $business->allowsWrites()) {
            return $done;
        }

        /*
         * Asked through BatchStages so that "in the Delivering column" means here what it means on the
         * board. The stage matters as much as the delivery dates do: a batch carrying a material row
         * that never matched a product reads as ORDERING - something on the job was never bought - and
         * a batch like that must not quietly become a past project just because the orders that do
         * exist have all arrived.
         */
        $staged = (new BatchStages)->forBusiness($business);

        /*
         * And the Quoting column beside it, for the batches delivered by hand.
         *
         * A shop that rings the merchant has no order to book in, so its finished batches never reach
         * the Delivering column at all - they sit in Quoting with "Delivered" on the card and nothing
         * to move them on. That was most of them before the quotes screen was deleted and it is all of
         * them since, which left this sweep closing nothing and the Nesting page growing by a card a
         * job. Only the ones carrying the mark: an ordinary Quoting batch is work in progress.
         *
         * The two columns are kept apart on purpose even so - doneDueDate decides what each is
         * waiting for, and the Ordering column is in neither, a batch with material nobody has bought
         * being unfinished however the rest of it arrived.
         */
        $deliveredByHand = $staged[BatchStages::QUOTING]
            ->filter(fn (Batch $batch) => $batch->delivered_at !== null);

        foreach ($staged[BatchStages::DELIVERING]->concat($deliveredByHand) as $batch) {
            $due = $this->doneDueDate($batch);

            if ($due === null || $due->isFuture()) {
                continue;
            }

            /*
             * Through the same action the button posts to. False means the guard refused - a delivery
             * went back out between the stage query and here - and nothing is written.
             */
            if (MarkAsDone::run($batch)) {
                $done[] = $batch;
            }
        }

        return $done;
    }

    /**
     * The day this batch closes itself, or null if it will not.
     *
     * One method, read by both the sweep and the board - KanbanFormatter::deliveredColumn prints it on
     * the card - because a card that promises a date the schedule does not keep is worse than a card
     * that says nothing. The date is the last delivery booked in plus DAYS_AFTER_DELIVERY, counted
     * from the receipt rather than from today, so it does not drift forward as the card is re-rendered.
     *
     * Two kinds of batch reach a date, because there are two ways to buy. One was bought through the
     * quotes screen and is read off its goods receipts, which is everything below. The other was bought
     * over the phone, has no order to book in, and carries the Nesting card's own delivered mark -
     * counted from the day somebody set it. See the Quoting branch in the body.
     *
     * Null in four cases, and in all four the card stays on the page for somebody to close by hand:
     *
     *  - The batch is already done, or is in neither of the two states above. Nothing to close - and
     *    the Ordering column is deliberately one of them, material on it being unbought.
     *  - A sent order has not been delivered. The steel is still out.
     *  - A sent, delivered order has no received_at. Those are the deliveries booked in before the
     *    goods receipt columns existed (see the add_goods_receipt_to_orders migration, which refused
     *    to backfill them from updated_at for the same reason this refuses to read it): there is no
     *    honest answer to "when did this arrive", and a closed batch cannot be re-opened, so this
     *    counts five days from nothing rather than from a guess. Every delivery booked in through the
     *    app since has the date.
     *  - A delivered steel merchant order is missing its material certs. This is the one case where
     *    the wait is not just a wait: the warning on the card is the only thing chasing the
     *    certificates, closing the batch takes it off the board, and the offcuts that steel becomes
     *    stay in the rack and stay untraceable. The board already hides "Move to done" behind this
     *    warning, so holding here is the sweep agreeing with the button rather than a rule of its own.
     */
    public function doneDueDate(Batch $batch): ?Carbon
    {
        if ($batch->done) {
            return null;
        }

        /*
         * Asked here even though both callers have already established the stage - the board filtered
         * its column on it and the sweep read the column off BatchStages. A method the board prints
         * and a schedule acts on should not have preconditions only its callers know about, and the
         * cost is the same two bounded queries the Ordering and Delivering columns each already pay
         * per batch.
         */
        $stage = (new BatchStages)->of($batch);

        /*
         * The batch delivered by hand, which has no order to read a receipt off and says so with the
         * mark instead. Five days from the day somebody said the steel was in - the same wait, counted
         * from the same event, just recorded by a person rather than by a goods receipt.
         *
         * From the cut where there is one, because that is the later of the two and the batch is
         * plainly still in use until the saw has been through it. Not waiting FOR the cut: a shop that
         * never presses it would then never have a batch close, which is the silting up this is here
         * to stop.
         *
         * Only from the Quoting column. A hand mark on a batch with real orders behind it is read off
         * those orders instead (see the block below), and the Ordering column is excluded by the
         * default: something on that job was never bought.
         */
        if ($stage === BatchStages::QUOTING) {
            if ($batch->delivered_at === null) {
                return null;
            }

            $finished = $batch->cut_at !== null && $batch->cut_at->greaterThan($batch->delivered_at)
                ? $batch->cut_at
                : $batch->delivered_at;

            return $finished->copy()->addDays(self::DAYS_AFTER_DELIVERY);
        }

        if ($stage !== BatchStages::DELIVERING) {
            return null;
        }

        /*
         * One read of the sent orders, answering both questions off it. The two columns are all this
         * needs - everything else about the receipt belongs to the order screen.
         */
        $sentOrders = $batch->orders()
            ->where('order_sent', true)
            ->get(['id', 'is_delivered', 'received_at']);

        //The Delivering stage requires a sent order, so this is defensive rather than reachable
        if ($sentOrders->isEmpty()) {
            return null;
        }

        foreach ($sentOrders as $order) {
            if (! $order->is_delivered || $order->received_at === null) {
                return null;
            }
        }

        if ($this->missingMaterialCerts($batch)) {
            return null;
        }

        /** @var Carbon $lastDelivery */
        $lastDelivery = $sentOrders->max('received_at');

        return $lastDelivery->copy()->addDays(self::DAYS_AFTER_DELIVERY);
    }

    /**
     * Delivered steel with no traceability behind it.
     *
     * The same test the card's orange warning and the dashboard's action apply, down to accepting
     * either form of certificate (see Order::scopeMissingMaterialCerts) - a merchant who emails the
     * PDF and never quotes a number leaves the steel just as traceable as one who does.
     */
    private function missingMaterialCerts(Batch $batch): bool
    {
        return $batch->orders()
            ->where('order_sent', true)
            ->where('is_delivered', true)
            ->whereRelation('quote', 'supplier_category', '=', SupplierGroupEnums::STEEL_MERCHANT->value)
            ->missingMaterialCerts()
            ->exists();
    }
}
