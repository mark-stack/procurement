<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A mill certificate that hangs off the batch rather than off an order.
     *
     * The table was built for the one place certificates arrive: an order, sent to a merchant,
     * delivered against a goods receipt. That is still where most of them land - and it is nowhere at
     * all for the shop this application has been opening up to since the supplier-free marks went on
     * the Nesting card. A fabricator who rings the merchant has no order row, so the PDF that comes
     * back by email had nowhere to go, and "Delivered" would have been a date with no paperwork
     * behind it.
     *
     * So order_id becomes nullable and batch_id joins it. Exactly one of the two is set - a
     * certificate belongs to an order or to a batch, never to both and never to neither - which is a
     * rule the application keeps rather than the schema: the two controllers that write these rows
     * each set one of them (see MaterialCertificateController and BatchCertificateController), and a
     * check constraint would be a third place to maintain for no reader's benefit.
     *
     * Cascading on delete, the way order_id does. Deleting a batch is re-nesting it, which already
     * takes its quotes, its draft orders and the offcuts it cut - a certificate for steel that was
     * never delivered against a nest that no longer exists goes with them.
     */
    public function up(): void
    {
        Schema::table('material_certificates', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('order_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('material_certificates', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('material_certificates', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
            $table->dropColumn('batch_id');
        });

        /*
         * Left nullable on the way down. Every row written while this was in place belongs to a batch
         * and carries no order, so putting the column back to NOT NULL would refuse to run against
         * exactly the data this migration exists to allow.
         */
    }
};
