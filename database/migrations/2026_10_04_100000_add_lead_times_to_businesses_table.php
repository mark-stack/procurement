<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How long this business takes to get a price, and how long its steel takes to turn up.
     *
     * The two halves of the critical path every deadline in the application is measured against -
     * Project::criticalPathDays() is these added together, and the quoting, ordering and delivery
     * deadlines are all counted back from the date the materials are wanted. Until now both were
     * returned by hand from Project: 2 and 4, the same for every business on the platform, with a
     * todo on the second saying the fallback should be 3.
     *
     * They are a property of the business rather than of a project. A fabricator who deals with one
     * merchant down the road and one who waits a fortnight for a mill rolling are being chased on the
     * same schedule, and only one of them is being told the truth - so the figures belong where the
     * business can set them (/profile, "Business preferences").
     *
     * Defaults are the figures asked for: 2 days to quote, 3 to deliver. The delivery default moves
     * the platform figure down from 4, which shortens the critical path from 6 days to 5 - every
     * business keeps the new default until somebody sets its own. Mirrored on Business as
     * DEFAULT_QUOTING_DAYS and DEFAULT_DELIVERY_DAYS, because a column default only fills the row:
     * see the note on Business::$attributes for what that costs when it is forgotten.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->integer('quoting_days')->default(2)->after('allow_custom_products');
            $table->integer('delivery_days')->default(3)->after('quoting_days');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['quoting_days', 'delivery_days']);
        });
    }
};
