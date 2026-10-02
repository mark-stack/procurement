<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Business;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which step of the board a batch is sitting on.
 *
 * One answer, in one place, because two screens now ask it. The board draws a column per step and the
 * dashboard counts them and says what each one is waiting for - and a dashboard that says "2 quoting"
 * above a board showing three cards in Quoting is worse than no dashboard at all.
 *
 * The three steps are mutually exclusive and every active batch is in exactly one of them:
 *
 *  - QUOTING: no order has been sent. The batch is out with suppliers, or not yet out at all.
 *  - ORDERING: at least one order has gone in, and some material on it is still unordered.
 *  - DELIVERING: every material row on every project of the batch points at a sent order.
 *
 * A batch that is done is on none of them - it is a past project.
 */
class BatchStages
{
    public const string QUOTING = 'QUOTING';

    public const string ORDERING = 'ORDERING';

    public const string DELIVERING = 'DELIVERING';

    /**
     * Every active batch of this business, split by the step it is on.
     *
     * Deliberately unsorted. The board sorts each column with BatchService::sortByUserAndLatest,
     * which walks every batch the business has ever had and eager loads the material tree of each
     * one to find out whose projects are on it - far too much to pay for a count, and the wrong
     * question for a page that is summarising rather than drawing cards.
     *
     * @return array{QUOTING: Collection<int, Batch>, ORDERING: Collection<int, Batch>, DELIVERING: Collection<int, Batch>}
     */
    public function forBusiness(Business $business): array
    {
        $staged = [
            self::QUOTING => [],
            self::ORDERING => [],
            self::DELIVERING => [],
        ];

        /*
         * The business's batches, asked for off the model rather than through
         * Business::batches() - a hasManyThrough answers "undefined method active()" to static
         * analysis, and a plain select of the staff ids is also one query lighter than the
         * relation's join.
         */
        $batches = Batch::query()
            ->active()
            ->whereIn('user_id', $business->users()->select('id'))
            ->get();

        foreach ($batches as $batch) {
            $staged[$this->of($batch)][] = $batch;
        }

        return array_map(fn (array $batches) => collect($batches), $staged);
    }

    public function of(Batch $batch): string
    {
        /*
         * The same test Batch::scopeHasNoSentOrder() applies, which is what the Quoting column is
         * built from: a batch is being quoted until the first order goes in. A draft order exists for
         * every supplier from the moment somebody opens the quotes modal, so this has to ask whether
         * one was SENT, not whether one exists.
         */
        if ($batch->orders()->where('order_sent', true)->doesntExist()) {
            return self::QUOTING;
        }

        return $this->everyRowOrdered($batch) ? self::DELIVERING : self::ORDERING;
    }

    /**
     * Whether every material line on this batch's projects has been ordered.
     *
     * Asked in SQL, as two bounded queries. The columns this replaces asked it by loading every
     * project of every active batch with its rawMaterialQuotes.piece.order tree and counting rows in
     * PHP - twice over per render, because the Ordering and Delivering columns each ran the whole
     * loop to answer opposite halves of the same question.
     *
     * The answer is deliberately identical to the "all 100%" test those columns used
     * (Project::percentageOfMaterialsOrdered() === 100 for every project on the batch), down to its
     * two edge cases:
     *
     *  - A material row that never matched a product has no piece, so it can never be ordered and it
     *    holds the batch in Ordering. That is the honest reading - something on the job has not been
     *    bought - and the fix is on the material list, which is where the dashboard points at it.
     *  - A project carrying no material rows at all reads 0%, never 100%, so it holds the batch too.
     */
    private function everyRowOrdered(Batch $batch): bool
    {
        $projectIds = $batch->pieces()->distinct()->pluck('project_id');

        //Nothing on the batch to be short of, which is how the Delivering column read it too
        if ($projectIds->isEmpty()) {
            return true;
        }

        if (Project::query()->whereIn('id', $projectIds)->doesntHave('rawMaterialQuotes')->exists()) {
            return false;
        }

        /*
         * One piece per material row since the 2026_09_30 migration, so "the row has no piece whose
         * order has been sent" is the whole of "this row is not ordered".
         */
        return RawMaterialQuote::query()
            ->whereIn('project_id', $projectIds)
            ->whereDoesntHave('piece', fn (Builder $piece) => $piece
                ->whereHas('order', fn (Builder $order) => $order->where('order_sent', true)))
            ->doesntExist();
    }
}
