<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A recorded template could not be matched to the thing it documents. The row held a
     * name, five cell references and a screenshot; detection is driven by the entries in
     * config/TableTemplates.php, keyed on heading labels and relative offsets. Nothing
     * joined the two, so "Tekla Assembly List, B7, mm" could not be checked against the
     * config entry it claimed to describe, and nothing noticed when that entry was
     * renamed or removed.
     *
     * source + config_label name one config entry - the pair is unique across the five
     * of them. Nullable because rows recorded before this migration named nothing; the
     * screen asks for the pair on the next save and flags a row that still has none.
     */
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->string('source')->nullable()->after('name');
            $table->string('config_label')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn(['source', 'config_label']);
        });
    }
};
