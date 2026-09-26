<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saw kerf: the material the blade turns into swarf on every cut.
     *
     * Nesting had no allowance for it anywhere, so a 12,000mm bar could be filled with 4x 3,000mm and
     * reported as 100% efficient with a 0mm drop. On the saw that plan is short by three blade widths
     * and the last piece comes up undersize.
     *
     * Defaults to 0 so existing nests keep the numbers they were approved with. It is a shop-floor
     * measurement (band saw ~2mm, cold saw ~3mm, plasma wider), so it has to be set per business
     * rather than guessed at here.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->integer('kerf_mm')->default(0)->after('scrap_threshold_mm');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('kerf_mm');
        });
    }
};
