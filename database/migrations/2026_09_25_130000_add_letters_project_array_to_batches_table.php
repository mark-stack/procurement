<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Project letters ("Project A", "Project B") are assigned when a batch is nested and stamped onto
     * every cut in the saved nesting. The batch screens used to recompute them from the batch's
     * projects, which orders the projects differently, so the legend and the cut drawings could
     * disagree about which project a letter belonged to.
     *
     * Store the map that was actually used. Batches nested before this carry the letters on the cuts
     * themselves, which is what NestingFormatter falls back to.
     */
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->json('letters_project_array')->nullable()->after('nested_state');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn('letters_project_array');
        });
    }
};
