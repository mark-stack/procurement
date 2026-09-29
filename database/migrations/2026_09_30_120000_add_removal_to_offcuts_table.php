<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
         * Taking an offcut out of inventory by hand.
         *
         * Marked, never deleted. Three things hang off the row surviving:
         *
         *  - Descendants point at it through offcut_from_id, and Offcut::loadAncestry walks that chain
         *    to build the certificate trail. Delete the row and every offcut cut from it loses its
         *    provenance, which is the one thing this application exists to keep.
         *  - The mark stamped on it stays taken. UniqueLetterIDGenerator reads every offcut of the
         *    business, consumed ones included, precisely so paperwork naming a mark always names the
         *    same piece of steel. A mark freed by a delete could be stamped on a second bar while the
         *    first is still in somebody's rack.
         *  - Somebody removes the wrong row. A marked row goes back; a deleted one is gone.
         *
         * batch_to_id is deliberately left alone: it means "consumed by this batch's cuts", and the
         * nesting totals are balanced against it. Steel that walked out of the yard was not cut here.
         */
        Schema::table('offcuts', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->after('unique_mark');
            $table->unsignedBigInteger('removed_by_user_id')->nullable()->after('removed_at');
            //One of App\Enums\OffcutRemovalEnums
            $table->string('removed_reason', 32)->nullable()->after('removed_by_user_id');
            //What actually happened, in the words of whoever recorded it
            $table->string('removed_note', 255)->nullable()->after('removed_reason');
        });

        /*
         * Every read of the inventory now filters on this column, and the inventory is also what the
         * nest is built from - so this is on the hot path of the most expensive write in the
         * application, not just of the offcuts page.
         */
        Schema::table('offcuts', function (Blueprint $table) {
            $table->index(['business_id', 'removed_at'], 'offcuts_business_removed_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offcuts', function (Blueprint $table) {
            $table->dropIndex('offcuts_business_removed_index');
            $table->dropColumn(['removed_at', 'removed_by_user_id', 'removed_reason', 'removed_note']);
        });
    }
};
