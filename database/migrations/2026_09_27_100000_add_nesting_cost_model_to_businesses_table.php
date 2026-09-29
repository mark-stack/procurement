<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money and minutes for the nesting cost model - see App\Services\NestingCostModel.
     *
     * Nesting used to rank candidate nests on a list of millimetre totals, purchase first, and it counted
     * a banked offcut as costing nothing at all. Two consequences on the shop floor:
     *
     *  - An offcut was either 100% scrap or 100% free at the scrap threshold, so a 999mm offcut cost 999 and a
     *    1,001mm offcut cost zero. The search had a thousand iterations to find the arrangement that landed
     *    just past the line, which is the least useful reusable length there is.
     *  - Handling was unpriced. Rack space, retrieving an offcut, a saw cut and a bar to lift were all
     *    free, so yield always won however much labour it cost to get it.
     *
     * Everything here is in DOLLARS, because that is the only currency in which labour and steel can be
     * compared. A plan is costed as material plus the time it takes, and the question the nest is really
     * answering is whether the labour of preserving a remnant is worth less than the remnant: going to
     * lengths over $9 of equal angle is not worth anybody's time, while a $3,000 beam can carry a good
     * deal of extra handling before it stops paying.
     *
     * Labour is priced from DURATIONS rather than flat amounts, because a bigger section is more work:
     *
     *  - Cutting scales with the section's mass per metre. A cut is a cut whatever the length, but feeding
     *    a blade through a 500UB web is not the same job as a 65mm angle.
     *  - Moving scales with the mass of the actual piece. A 6m angle is carried by one person; a 12m 500UB
     *    at over a tonne is a crane, slings and a second pair of hands.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            //What an hour on the shop floor costs, all in
            $table->decimal('labour_rate_per_hour', 8, 2)->default(50.00)->after('kerf_mm');

            //Delivered steel price. $2,000/t is $2.00/kg, which is what every material figure converts through
            $table->decimal('material_cost_per_tonne', 10, 2)->default(2000.00)->after('labour_rate_per_hour');

            /*
             * What the scrap bin pays back, as a share of the new price. Steel cut off and binned is not a
             * total loss - it is weighed in and credited, at roughly 13% of what it cost.
             *
             * Applies to solid scrap offcuts only. Saw kerf gets nothing: it leaves as swarf mixed with
             * coolant and other sections, which is not what a merchant weighs in and pays for.
             */
            $table->float('scrap_recovery_rate')->default(0.13)->after('material_cost_per_tonne');

            /*
             * Fallback for products.kg_per_m, which is nullable. Mass is how material cost AND labour time
             * are both derived, so without it a nest cannot tell light angle from a heavy beam.
             */
            $table->float('default_kg_per_m')->default(10.0)->after('material_cost_per_tonne');

            /*
             * One saw cut: setup and marking off, plus blade time through the section.
             * 1.5min + 0.06/kg-per-m puts a light angle near 1.8min and a 500UB near 6.9min.
             */
            $table->float('cut_base_minutes')->default(1.5)->after('default_kg_per_m');
            $table->float('cut_minutes_per_kg_per_m')->default(0.06)->after('cut_base_minutes');

            /*
             * Getting a piece to the saw, before the mass of it is counted. Retrieving an offcut costs more
             * than taking a new bar off the lift because it has to be found in the rack and its mark read
             * and trusted first.
             */
            $table->float('offcut_draw_base_minutes')->default(4.0)->after('cut_minutes_per_kg_per_m');
            $table->float('bar_handling_base_minutes')->default(3.0)->after('offcut_draw_base_minutes');

            /*
             * What one more piece on the offcut rack costs: writing the mark, another link in the
             * certificate chain, and the handling it will eventually take. The only entry here that is not
             * a single physical operation - it is the amortised labour a racked offcut causes over its
             * life, and it is what stops the yard filling with stubs nobody will ever reach for.
             */
            $table->float('offcut_rack_base_minutes')->default(6.0)->after('bar_handling_base_minutes');

            /*
             * Added to every move, per tonne of the piece being moved. 15min/t puts a 12m 500UB at over a
             * quarter hour to shift and a 6m angle at half a minute, which is the difference between a
             * crane lift and picking it up.
             */
            $table->float('move_minutes_per_tonne')->default(15.0)->after('offcut_rack_base_minutes');

            /*
             * The most of its value an offcut keeps by going on the rack, reached when it is as long as a full
             * stock length. Bounded by purchase_cost_weight rather than by how valuable a long offcut
             * feels: if racking a millimetre is worth as much as buying one, the nest buys steel in order
             * to rack it. See NestingCostModel::retention().
             */
            $table->float('offcut_retention_cap')->default(0.6)->after('move_minutes_per_tonne');

            /*
             * Weight on steel actually bought. 1.0 means a dollar of purchase costs a dollar, which is what
             * stops the nest buying a bar to avoid cutting into inventory. Raise it to buy leaner still.
             */
            $table->float('purchase_cost_weight')->default(1.0)->after('offcut_retention_cap');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'labour_rate_per_hour',
                'material_cost_per_tonne',
                'default_kg_per_m',
                'cut_base_minutes',
                'cut_minutes_per_kg_per_m',
                'offcut_draw_base_minutes',
                'bar_handling_base_minutes',
                'offcut_rack_base_minutes',
                'move_minutes_per_tonne',
                'offcut_retention_cap',
                'purchase_cost_weight',
            ]);
        });
    }
};
