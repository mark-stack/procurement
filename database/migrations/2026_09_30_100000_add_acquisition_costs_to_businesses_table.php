<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What it costs to acquire new steel, on top of the steel - see App\Services\NestingCostModel.
     *
     * The cost model priced what happens to steel once it is in the yard: cutting it, fetching it, racking
     * it, binning it. Getting it INTO the yard was free. A nest could buy a bar for the price of the metal
     * and nothing else, so drawing on the rack was competing against an acquisition cost that stopped at
     * the merchant's invoice line.
     *
     * Three things were missing, and all three pull the same way - towards using what is already on the
     * rack, and towards not writing a remnant off:
     *
     *  - Freight. Steel does not arrive by itself, and the delivery is paid for whether it is a full load
     *    or one bar. Split into a per-tonne rate and a flat drop fee, because merchants charge both ways.
     *  - Unloading and storing what arrives. Off the truck, checked against the docket, onto the rack -
     *    per bar received, before anybody has cut anything.
     *  - Raising the order at all. Getting a price, placing it, and checking the invoice against what
     *    turned up. A fixed cost per order, which is what makes buying a single short bar look as silly
     *    as it is.
     *
     * WHY THIS CHANGES THE SCRAP FLOOR, which is the point of the exercise. An offcut's worth is measured
     * against the labour of keeping it (NestingCostModel::worthRackingFromMm). Freight raises what a
     * millimetre of steel is worth without raising what it costs to mark and shift it, so the two curves
     * cross sooner and fewer remnants fall below the floor. Acquisition LABOUR deliberately does not feed
     * that valuation - see the note on the invariant in NestingCostModel::retention().
     *
     * DELIVERY DEFAULTS TO ZERO ON PURPOSE. material_cost_per_tonne was documented as the DELIVERED price,
     * so any non-zero default here would charge freight twice for every business already carrying a
     * figure in that column, and silently revalue every offcut rack in the system. That column now means
     * the bare steel price; the two delivery columns start at nothing and a business opts in by setting
     * its real freight. The labour columns do NOT have that problem - bar_handling_base_minutes is time
     * from the lift to the saw and never included receiving - so they carry real defaults and take effect
     * immediately.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            /*
             * Freight by weight. Landed cost is material_cost_per_tonne + this, and that total is what
             * every valuation, purchase and destruction in the model is priced through.
             *
             * Zero by default - see the class note above. $150/t on $2,000/t steel is a 7.5% uplift on
             * everything the nest thinks steel is worth, which is enough to move the scrap floor down by
             * a couple of hundred millimetres on light sections.
             */
            $table->decimal('delivery_cost_per_tonne', 10, 2)->default(0.00)->after('material_cost_per_tonne');

            /*
             * The flat part of a delivery - the truck turning up, whatever is on it. Charged once to any
             * nest that buys anything at all, not per bar and not per tonne.
             *
             * Nest-local by choice, and it is the one figure here that is not strictly honest: one
             * purchase order usually covers several products, so a batch of five nests that each buy
             * will each carry a full drop fee. It cannot mis-rank the candidates WITHIN a nest, because
             * every candidate that buys carries exactly the same charge, and what it buys is the signal
             * that matters - that buying anything at all has a fixed cost. Apportioning it properly needs
             * a pass across all the nests in a batch, which is the same deferred work as supplier
             * minimums and consolidating onto shared stock lengths.
             */
            $table->decimal('delivery_cost_per_order', 10, 2)->default(0.00)->after('delivery_cost_per_tonne');

            /*
             * Taking one delivered bar off the truck, checking it against the docket, and putting it on
             * the rack. Per bar received, and the mass term of move_minutes_per_tonne is added on top, so
             * a 12m 500UB is a crane lift here exactly as it is everywhere else.
             *
             * Distinct from bar_handling_base_minutes (3.0), which is a bar already in the yard being
             * taken to the saw. A bought bar now costs both: it has to be received, and then it has to be
             * fetched.
             */
            $table->float('receive_base_minutes')->default(5.0)->after('bar_handling_base_minutes');

            /*
             * Raising one order: getting a price, placing it, and checking the invoice against what
             * arrived. Charged once to any nest that buys, on the same terms as delivery_cost_per_order.
             *
             * 15 minutes at the default shop rate is $12.50, which is most of the value of a metre of
             * light angle. That is the intended message - a nest that buys 300mm of angle to avoid
             * walking to the rack is not saving anybody anything.
             */
            $table->float('order_admin_minutes')->default(15.0)->after('receive_base_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_cost_per_tonne',
                'delivery_cost_per_order',
                'receive_base_minutes',
                'order_admin_minutes',
            ]);
        });
    }
};
