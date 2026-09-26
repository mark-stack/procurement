<?php

use App\Models\Offcut;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give a bar a link back to the batch that nested it.
     *
     * Actions/Bar/CreateBarsAndOffcuts writes one bar per utilised bar, and the table had no batch
     * column at all - so unwinding a batch deleted the offcuts those bars produced and left the bars
     * themselves behind as rows nothing can reach. Every re-nest leaked another set.
     *
     * Cascading makes that structural: a bar only exists because a batch was nested, so it goes when
     * the batch does, whichever path deletes it.
     */
    public function up(): void
    {
        Schema::table('bars', function (Blueprint $table) {
            //Nullable: the bars already in the table predate the column and not all of them can be traced
            $table->foreignId('batch_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        $this->backfillFromOffcuts();
    }

    /**
     * A bar that produced an offcut can be traced through it: offcuts.bar_id names the bar and
     * offcuts.batch_from_id names the batch that cut it. A bar whose remainder fell under the scrap
     * threshold produced no offcut and has no trace, so it keeps a null batch_id and stays orphaned.
     */
    private function backfillFromOffcuts(): void
    {
        Offcut::query()
            ->whereNotNull('bar_id')
            /*
             * batch_from_id is a plain integer with no foreign key of its own, and unwinds from before
             * the offcut deletion was fixed left offcuts pointing at batch rows that are gone. Those
             * name no batch to trace a bar back to, and writing one would fail the new constraint.
             */
            ->whereIn('batch_from_id', DB::table('batches')->select('id'))
            ->select(['id', 'bar_id', 'batch_from_id'])
            ->orderBy('id')
            ->chunk(500, function ($offcuts) {
                foreach ($offcuts->groupBy('batch_from_id') as $batchId => $rows) {
                    DB::table('bars')
                        ->whereIn('id', $rows->pluck('bar_id')->unique()->all())
                        ->update(['batch_id' => $batchId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('bars', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
            $table->dropColumn('batch_id');
        });
    }
};
