<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Test mode.
     *
     * A user can put their own account into a sandbox: projects and batches created while it is on
     * are stamped with their id and are visible to nobody but them, and "Clear everything" throws
     * the lot away. The price book, the templates and the suppliers are untouched by it - the point
     * is to try a real BOM against the real supplier list without a test project appearing on a
     * colleague's board.
     *
     * Per user, not per business. Two people at the same company can be experimenting at once
     * without seeing each other's rubbish, and neither of them sees it on the live board.
     *
     * A nullable owner rather than a boolean, because the row has to say whose sandbox it is as
     * well as that it is one. Null is live data - which is what every existing row is.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //Which mode this user is currently working in. Persisted, not session state: leaving a
            //half-finished experiment and coming back to it tomorrow is the normal way to use this
            $table->boolean('sandbox_mode')->default(false)->after('password');
        });

        /*
         * Restricting, like projects.user_id already is: a user with test data left behind cannot be
         * deleted out from under it. nullOnDelete() would be worse than useless here - it would
         * promote somebody's abandoned test projects to live data on the business's board.
         */
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('sandbox_user_id')->nullable()->after('user_id')->constrained('users');
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->foreignId('sandbox_user_id')->nullable()->after('user_id')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeign(['sandbox_user_id']);
            $table->dropColumn('sandbox_user_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['sandbox_user_id']);
            $table->dropColumn('sandbox_user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sandbox_mode');
        });
    }
};
