<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who approved the ordering, and when.
     *
     * project_manager_approved was written as a fact about every project manager on the batch and
     * recorded the assent of none of them: one person presses "Sent order", and
     * UpdateOrderApprovalStatus flips the flag true for every project on the batch, including
     * colleagues' projects whose owners were never asked. With nothing recording who actually
     * pressed it, the row could not be told apart from one where everybody had agreed.
     *
     * These two columns do not turn it into per-manager approval - that is a workflow, not a
     * column - but they make the stored row true: this person, at this time, ordered on behalf of
     * the managers on this batch.
     */
    public function up(): void
    {
        Schema::table('order_approvals', function (Blueprint $table) {
            //Nullable: rows created pending have nobody to name yet, and existing rows never did
            $table->foreignId('approved_by_user_id')->nullable()->after('project_manager_approved')->constrained('users');
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn('approved_at');
        });
    }
};
