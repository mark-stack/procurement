<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each batch measured, kept so a month of them can be read as a line.
     *
     * The application already works all of this out. A nest produces a yield the moment it is built
     * (NestingFormatter::usageStats), the scrap it leaves is a row per drop since scrap became a
     * record, and whether the steel turned up in time is a date against a date. What none of them
     * could do is be read as a SERIES: the yield lives inside a batch's nested_state, so answering
     * "how did we nest in March" meant decoding every nest of the month and adding them up, and
     * nobody had ever written down whether a delivery was on time at all.
     *
     * That is the gap 9.1 and 6.2 both open onto. An objective is a number with a target and a
     * trend - "95% of deliveries on the day, yield above 85%" - and neither clause is satisfiable by
     * a figure that has to be recomputed from the source material every time somebody asks.
     * Recomputation is also how a measurement quietly changes: a yield re-derived today is derived
     * through today's cost model, and an on-time delivery re-derived today is measured against a
     * fabrication date that may have moved twice since (see App\Models\Project - the date is shared
     * state and every manager on a batch may edit it).
     *
     * ONE ROW PER BATCH, filled at two different moments by two different events:
     *
     *  - NESTING writes the yield. Taken from the saved nest at the moment it is saved, which is the
     *    same reading the Nesting page's cards show, so the card and the trend cannot disagree.
     *  - DELIVERY writes the outcome. The day the steel was wanted, the day it arrived, and the gap
     *    between them - captured when it arrives, because the promise it is measured against is a
     *    date somebody may move afterwards.
     *
     * Deliberately NOT a monthly rollup table. A month's figure is a sum over these rows and the
     * report does that sum (App\Services\MeasuresReport); a stored monthly total would be a third
     * copy of the same quantity that has to be kept in step with the two below it, and it answers
     * nothing a group-by cannot. What makes the series cheap is that nothing here is re-nested, not
     * that it is pre-added.
     *
     * No business_id, for the reason the scraps table has none: everything reaches this through
     * batch_id, which is also what carries the sandbox filter batches already have - so a test-mode
     * nest stays out of a business's real figures without this table knowing a sandbox exists.
     */
    public function up(): void
    {
        Schema::create('batch_measurements', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            /*
             * One row per batch, and the unique index is what says so.
             *
             * Both writers are idempotent in PHP (App\Services\BatchMeasurements), but the yield half
             * runs inside the same transaction as the nest and the delivery half runs on a button two
             * people may be looking at - so the rule belongs where a second writer cannot talk its
             * way past it.
             *
             * Cascades like cuts and scrap: unwinding a batch says the nest never happened, and a
             * measurement of a nest that never happened is not a historical figure, it is a wrong one.
             */
            $table->foreignId('batch_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * When the nest this measures was saved.
             *
             * Its own column rather than created_at, for the reason scraps.scrapped_at is its own
             * column: a row written by the backfill over an old batch belongs in the month that batch
             * was nested, not the month somebody ran the command.
             */
            $table->timestamp('nested_at')->nullable()->index();

            /*
             * The nest, in millimetres, exactly as usageStats() totalled it.
             *
             * Decimal rather than integer because a cut length is a decimal - "1432.5" is a real
             * entry on a material list - and the totals carry that through. Both sides of the yield
             * are stored rather than the percentage alone, so a month's figure can be summed and
             * divided ONCE over the whole month. Averaging twelve batch percentages gives a tiny job
             * the same weight as one that bought six tonnes, which is how a good month reads as a bad
             * one.
             */
            $table->decimal('purchased_mm', 14, 1)->default(0);
            $table->decimal('offcut_mm', 14, 1)->default(0);
            $table->decimal('consumed_mm', 14, 1)->default(0);
            $table->decimal('used_mm', 14, 1)->default(0);
            $table->decimal('reusable_mm', 14, 1)->default(0);
            $table->decimal('kerf_mm', 14, 1)->default(0);
            $table->decimal('scrap_mm', 14, 1)->default(0);

            /*
             * And the two percentages the screens print, kept beside the millimetres they come from.
             *
             * Redundant on purpose. They are what the card showed whoever approved the nest, and a
             * stored reading of a screen is worth more than a correct recalculation of it when the
             * question being asked is what we told ourselves at the time.
             */
            $table->decimal('efficiency', 5, 1)->default(0);
            $table->decimal('effective_efficiency', 5, 1)->default(0);

            /*
             * What the nest was costed at, in dollars - the figure the search minimised, summed over
             * the products on the batch. See App\Services\NestingCostModel.
             *
             * Nullable because a batch with nothing meterage on it (a bolts-only batch) is not costed
             * by that model at all, and zero would read as a nest that cost nothing.
             */
            $table->decimal('cost', 12, 2)->nullable();

            /*
             * Whether that cost was struck against settings retained with the nest, or against
             * whatever the business's figures happened to be when the row was written.
             *
             * False on every backfilled row and on nothing else - see the nesting_settings migration.
             * A dollar figure nobody can date is still useful as a comparison between batches of the
             * same week and is not evidence of anything; the flag is what lets a report say which
             * kind it is holding rather than printing both the same way.
             */
            $table->boolean('cost_from_retained_settings')->default(false);

            /*
             * The delivery half, written when the steel arrives and not before.
             *
             * required_on is the batch's own required-by day as it stood AT DELIVERY - the earliest
             * fabrication date among its jobs, less a working day (Project::earliestMaterialsRequiredDate).
             * It is copied here rather than read back through the projects because that date is
             * shared, live state: any manager on any job on the batch may move it, and a job whose
             * date slips a fortnight after the steel landed would otherwise turn a late delivery into
             * a punctual one, retrospectively, with nothing recording that it had.
             *
             * Null where the batch names no fabrication date at all. Those are projects created
             * before the date was asked for, and there is no promise for a delivery to have kept.
             */
            $table->date('required_on')->nullable();
            $table->date('delivered_on')->nullable()->index();

            /*
             * The gap, in calendar days: positive is late, zero is the day, negative is early.
             *
             * Calendar days rather than the working days the deadlines are counted in, because this
             * is not a deadline - it is how long the workshop waited, and a weekend spent waiting is
             * still spent. Stored rather than derived from the two dates above so that "on time" is
             * one definition in one place, and null exactly when required_on is: a delivery with
             * nothing to be measured against is neither on time nor late, and counting it either way
             * is the quickest way to an objective nobody believes.
             */
            $table->integer('days_late')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_measurements');
    }
};
