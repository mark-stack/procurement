<?php

use App\Enums\ScrapSourceEnums;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One meaning for scraps.value, and a column of its own for the other one.
 *
 * The table was created a few hours ago declaring that value is "the LANDED figure" - what owning
 * that steel had cost, freight included - and the nest drop path wrote exactly that. The cleanout
 * path did not. It wrote the offcut's CARRIED value instead: what the retention curve says a piece
 * that length is worth sitting on the rack, which is capped at 0.6 of the steel and discounted
 * steeply towards the scrap threshold. See Services\NestingCostModel::inventoryValueMm.
 *
 * Two different quantities under one column name, and the arithmetic downstream could not tell:
 *
 *  - Scrap::netLoss() is value less recovered_value, and recovered_value is 13% of the FULL bare
 *    price on both paths. So a cleanout row netted out NEGATIVE - the ledger said destroying the
 *    piece had earned the yard money. Not an edge case: the cleanout only ever proposes offcuts
 *    below the length at which their own section pays for its keep, and across that band every
 *    section heavier than light angle is negative for its whole range. A 1,001mm 530UB stub
 *    recorded a net loss of -$22.89.
 *
 *  - Services\ScrapReport::tally sums value, recovered_value and net_loss across both sources into
 *    one set of totals, so a yard's reported write-off was a blend of two bases that FELL the more
 *    dead stock it weighed in.
 *
 * So value becomes the landed cost on both paths, which is the figure the column was documented as
 * carrying and the only one that makes net_loss mean one thing. What the piece was carried at is
 * worth keeping - it is the difference between a drop that was never on the rack and a remnant the
 * yard had been valuing for a year - so it moves into carried_value, null wherever it does not
 * apply.
 *
 * EXISTING CLEANOUT ROWS ARE CORRECTED IN PLACE rather than left to be read under the new meaning.
 * The money on a scrap row is resolved once and never recomputed, which is right, but these rows
 * were resolved against the wrong definition and there is no reading of them that is true.
 * Nest drops are not touched: they were correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scraps', function (Blueprint $table) {
            /*
             * What the rack was carrying this piece at when it was written off, on the retention
             * curve. Null on a nest drop, which was never banked and so was never carried at
             * anything - rather than zero, which would read as a piece the rack had given up on.
             */
            $table->float('carried_value')->nullable()->after('recovered_value');
        });

        /*
         * Move what the old rows do have into the column that now means it, before value is
         * restated. Only cleanouts: a nest drop's value was already the landed figure.
         */
        DB::table('scraps')
            ->where('source', ScrapSourceEnums::CLEANOUT->value)
            ->update(['carried_value' => DB::raw('value')]);

        foreach ($this->restatedCleanoutValues() as $id => $value) {
            DB::table('scraps')->where('id', $id)->update(['value' => $value]);
        }
    }

    /**
     * The landed cost to put back on each cleanout row, keyed by row id.
     *
     * Derived from recovered_value rather than from a price read off the business today, because
     * today's price would restate a quarter that has already been reported - which is the whole
     * reason these figures are stored rather than computed. recovered_value is
     * scrap_recovery_rate of the BARE cost of this exact steel, so dividing it by the rate gives
     * that cost back exactly.
     *
     * The rate itself is not on the row, so it is read off the business behind the batch - the
     * same business the figure was struck against. Freight is not recoverable from the row at all,
     * so these come back at the bare cost and a yard paying freight will find its old cleanouts
     * valued slightly low against its new ones. Said out loud rather than papered over: understating
     * an old write-off by the freight on it is a small and honest error, where inventing a freight
     * rate the yard may never have paid is neither.
     *
     * A row whose rate is zero, missing, or whose batch has no business cannot be divided back and
     * is left alone, keeping the carried figure as the only number it has.
     *
     * @return array<int, float>
     */
    private function restatedCleanoutValues(): array
    {
        $rows = DB::table('scraps')
            ->join('batches', 'batches.id', '=', 'scraps.batch_id')
            ->join('users', 'users.id', '=', 'batches.user_id')
            ->join('businesses', 'businesses.id', '=', 'users.business_id')
            ->where('scraps.source', ScrapSourceEnums::CLEANOUT->value)
            ->where('scraps.recovered_value', '>', 0)
            ->where('businesses.scrap_recovery_rate', '>', 0)
            ->get(['scraps.id', 'scraps.recovered_value', 'businesses.scrap_recovery_rate']);

        $restated = [];

        foreach ($rows as $row) {
            $restated[(int) $row->id] = (float) $row->recovered_value / (float) $row->scrap_recovery_rate;
        }

        return $restated;
    }

    /**
     * Back to one column, with the landed figure overwritten by the carried one on the rows that
     * carried it. Lossy in the same direction the bug was, which is the honest reversal of it.
     */
    public function down(): void
    {
        DB::table('scraps')
            ->where('source', ScrapSourceEnums::CLEANOUT->value)
            ->whereNotNull('carried_value')
            ->update(['value' => DB::raw('carried_value')]);

        Schema::table('scraps', function (Blueprint $table) {
            $table->dropColumn('carried_value');
        });
    }
};
