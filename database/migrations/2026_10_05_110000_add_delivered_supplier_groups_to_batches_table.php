<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "This merchant's steel is in the rack", said one merchant at a time.
     *
     * The third of the order list's block marks, after the quoted one (2026_10_05_100000) and the
     * ordered one (2026_10_03_140000), and it exists for the reason they both do: a block is one
     * merchant, and a batch whose steel turned up on Tuesday and whose timber is still on a lorry is
     * neither delivered nor undelivered. The only mark between the two was "All delivered" on the card
     * menu, which says both at once and attaches the certificates for both while it is at it.
     *
     * A list of supplier group names, like the other two and for the same reasons: a supplier group is
     * what SupplierFormatter computes off the business's plan and its products, there is no row
     * anywhere to point a foreign key at, and a name is what the block is keyed by on screen.
     *
     * Only ever written for a merchant this application has no order against. A group bought through
     * the quotes screen is delivered when its order is booked in on a goods receipt, and the order
     * list refuses the mark there rather than keeping two answers to "has it arrived" that can
     * disagree - see BatchMarkGroupDeliveredController.
     *
     * Nothing is booked in by one. No goods receipt is written, no order is flagged delivered and
     * nobody is notified; the order list reads these alongside the real deliveries, and the Nesting
     * card reads "every merchant marked" as the whole job delivered, exactly as it already does for
     * the ordered marks.
     */
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->json('delivered_supplier_groups')->nullable()->after('quoted_supplier_groups');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn('delivered_supplier_groups');
        });
    }
};
