<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "The prices are in" and "it has been bought", said of the batch rather than of a supplier.
     *
     * Everything this application knows about quoting and ordering hangs off a supplier: a quote is a
     * row per merchant, an order is a row per quote, and the pill on the Nesting page reads both. That
     * is right for a fabricator who buys through the quotes modal, and it is a wall for one who does
     * not use it - a shop that rings the merchant, or has a standing supply agreement, or is simply
     * trying the application out, has no supplier rows to tick and so can never move a batch past
     * Quoting. The work is nested, bought and in the rack, and the board says it is out for quote.
     *
     * So the two steps get a mark of their own on the batch, set from the Nesting page's card menu and
     * naming nobody. They record what happened, not what was sent: nothing goes to a merchant, no
     * order is placed, and the quotes modal is untouched by them.
     *
     * Both are nullable and both stay null for a batch bought the supplier way, which is how the pill
     * tells the two apart - a batch with every supplier group priced reads Quoted on the strength of
     * its quotes, with or without this (see NestingIndexController::milestoneOf).
     *
     * Who pressed it is kept beside when, the way every other approval in this application is
     * (orders.approved_by_user_id, projects.created_by_user_id): "this batch was called bought" is a
     * claim about money, and a claim with nobody's name on it is the one nobody can ask about.
     * Restricting rather than nullOnDelete, for the same reason those two are.
     */
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->timestamp('quoted_at')->nullable()->after('done');
            $table->foreignId('quoted_by_user_id')->nullable()->after('quoted_at')->constrained('users');
            $table->timestamp('ordered_at')->nullable()->after('quoted_by_user_id');
            $table->foreignId('ordered_by_user_id')->nullable()->after('ordered_at')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeign(['quoted_by_user_id']);
            $table->dropForeign(['ordered_by_user_id']);
            $table->dropColumn(['quoted_at', 'quoted_by_user_id', 'ordered_at', 'ordered_by_user_id']);
        });
    }
};
