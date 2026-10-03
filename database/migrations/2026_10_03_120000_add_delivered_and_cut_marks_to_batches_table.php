<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "It has arrived" and "it has been cut" - the two steps after the ones the 2026_10_03_110000
     * migration added, and the rest of the same answer.
     *
     * Those two let a shop that buys off the application say the prices were in and the steel was
     * bought. This pair carries the batch the rest of the way: delivered_at is the material turning up
     * in the rack, cut_at is the saw having been through it.
     *
     * delivered_at is the same kind of mark as the two before it - a shop with no supplier rows has no
     * goods receipt either, so nothing else can ever say its steel arrived. cut_at is not: cutting
     * happens on every job, bought over the phone or ordered through the application, and this is the
     * first time anybody has been able to record it. So it is the one mark that is offered on an
     * order-driven batch too, once its deliveries are all booked in.
     *
     * Neither closes the batch. It stays on the Nesting page saying what has happened to it - a
     * fully delivered batch with real orders behind it still archives itself on the goods receipts
     * (App\Services\DeliveredBatchArchiving) and these marks are not part of that count.
     *
     * Who pressed it beside when, the way the two before it are, and for the same reason: these are
     * claims about somebody else's steel, and a claim with nobody's name on it is the one nobody can
     * ask about. Restricting rather than nullOnDelete, as those are.
     */
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('ordered_by_user_id');
            $table->foreignId('delivered_by_user_id')->nullable()->after('delivered_at')->constrained('users');
            $table->timestamp('cut_at')->nullable()->after('delivered_by_user_id');
            $table->foreignId('cut_by_user_id')->nullable()->after('cut_at')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeign(['delivered_by_user_id']);
            $table->dropForeign(['cut_by_user_id']);
            $table->dropColumn(['delivered_at', 'delivered_by_user_id', 'cut_at', 'cut_by_user_id']);
        });
    }
};
