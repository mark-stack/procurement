<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who wrote this template, and whether anybody has looked at it since.
     *
     * Until now every row in this table was typed by an admin at the templates screen, so "who
     * recorded it" was not a question worth a column. A customer's upload can now record one by
     * itself - it is proposed, tested against the file that triggered it, and saved if the test
     * passes - and that is a different kind of row: it is live, it reads a real customer's bills of
     * materials, and no human has ever seen it.
     *
     * The gate it passed is the same one an admin's template passes. These columns do not exist
     * because a machine-written template is less trusted at import time; they exist so that "nobody
     * has checked this" is answerable, which it otherwise is not. Marking one reviewed changes
     * nothing about what the importer reads.
     *
     * reviewed_by_user_id is nullOnDelete rather than cascade: a departed admin's review still
     * happened, and the date is the half of it that matters.
     */
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->boolean('generated_by_ai')->default(false)->after('active');
            $table->timestamp('reviewed_at')->nullable()->after('generated_by_ai');
            $table->foreignId('reviewed_by_user_id')->nullable()->after('reviewed_at')
                ->constrained('users')->nullOnDelete();
        });

        /*
         * Every row that exists today was typed by an admin at the form, which is a human having
         * read it - so they are reviewed, as of now. Left null they would all appear on the "nobody
         * has checked these" list the admin screen is about to grow, which would bury the rows that
         * genuinely need it under every template ever recorded.
         */
        DB::table('templates')->update(['reviewed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropColumn(['generated_by_ai', 'reviewed_at']);
        });
    }
};
