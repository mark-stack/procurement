<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The cuts a nest could not place, and why the cost beside them may be missing.
     *
     * NestingCostModel::cost() charges UNMADE_CUT_PENALTY - a billion dollars - for every cut no bar
     * or offcut could hold, so that no amount of material saving can buy a candidate past a part the
     * workshop does not get. As a ranking device that is exactly right and it is why it is that size.
     * As MONEY it is nonsense, and the measurements table was storing it as money: the first backfill
     * over real data produced two batches "costed" at $5,000,022,477 and $5,000,007,268, both of them
     * five 8,000mm cuts of 90x63 angle that no purchasable length could hold.
     *
     * So a nest that did not place every cut now records no cost at all - see Batch::nestCost() - and
     * this column is what stops that null being ambiguous. A null cost otherwise reads the same on a
     * bolts-only batch, which is not costed by that model at all, as on a nest that failed to fit
     * five parts. The first is a batch with nothing to say; the second is a batch somebody needs to
     * look at.
     *
     * The yield figures beside it stay, and are not affected by this: the steel that WAS nested
     * achieved the yield recorded, and an unmade cut consumed no steel to be counted against.
     */
    public function up(): void
    {
        Schema::table('batch_measurements', function (Blueprint $table) {
            $table->unsignedInteger('unmade_cuts')->default(0)->after('cost_from_retained_settings');
        });
    }

    public function down(): void
    {
        Schema::table('batch_measurements', function (Blueprint $table) {
            $table->dropColumn('unmade_cuts');
        });
    }
};
