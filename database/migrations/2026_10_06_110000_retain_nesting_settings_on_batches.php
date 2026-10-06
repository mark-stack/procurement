<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The settings a nest was actually run on, kept with the nest.
     *
     * A nest is costed against the business's own figures - the labour rate, the steel price, the
     * freight, the handling durations, the saw kerf and the length below which a remnant is thrown
     * away. Every one of those was read live, off the businesses row, at the moment the question was
     * asked. So a shop that put its labour rate up in March changed what February's batches are
     * reported to have cost, and a shop that raised its scrap threshold changed how much steel last
     * quarter is said to have destroyed. Neither of those batches moved a millimetre.
     *
     * That is the thing a measurement cannot do. 9.1 asks for the results of monitoring and
     * measurement to be RETAINED, and 6.2 asks for objectives to be tracked against them - and a
     * figure that restates itself whenever a setting is edited is not a result, it is a current
     * opinion dressed as one.
     *
     * The instinct was already in the application and in the right place: Actions\Bar\CreateBarsAndOffcuts
     * writes scrap_threshold_mm onto each bar inside nested_state, so the ledger can tell what a drop
     * was judged against rather than guessing with today's figure (Services\ScrapLedger::thresholdIn).
     * This is that instinct applied to the rest of them, one column at the level they belong at: the
     * coefficients are a property of the whole nest, not of one bar in it.
     *
     * JSON rather than a column each, and this is a snapshot rather than a schema. The cost model has
     * grown a coefficient three times since it was written (freight per tonne, per order, the
     * acquisition durations) and will again; a column per figure would mean a migration per
     * coefficient and a dozen columns on a table that is read a card at a time. What a reader needs
     * of this is the whole set exactly as it stood, and it is never queried on.
     *
     * Nullable because every batch nested before today has none, and there is no honest backfill for
     * one - the rates in force on the day are recorded nowhere, which is precisely the gap this
     * closes. A batch with no snapshot falls back to the business's figures as it always did, and
     * says so where it is reported. See App\Services\NestingSettings.
     */
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->json('nesting_settings')->nullable()->after('letters_project_array');
        });
    }

    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn('nesting_settings');
        });
    }
};
