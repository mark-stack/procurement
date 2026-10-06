<?php

namespace App\Services;

use App\Enums\NestingEnums;
use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\BatchMeasurement;
use App\Models\Business;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * The one thing that writes a batch's measurements down.
 *
 * Two events are worth measuring and both come through here, so that what is recorded is what the
 * screens showed rather than a second opinion arrived at later:
 *
 *   recordNest()     - the yield a nest achieved, read off the nest at the moment it is saved.
 *   recordDelivery() - whether the steel was in the yard by the day it was wanted.
 *
 * Both are idempotent, and the idempotency is the point rather than a nicety. A nest is saved once
 * and a delivery is marked by whoever gets to it first - the card's "All delivered", the order
 * list's per-merchant mark, or a goods receipt against a real order - and all three routes call this
 * at the moment the batch becomes fully delivered. The first one to arrive records the day; the
 * others find it recorded and leave it alone. A measurement that the second press could revise
 * would be a measurement of who pressed last.
 *
 * recordNest() reads the SAVED nest rather than being handed the nest it was built from, for the
 * reason Services\ScrapLedger does the same: the live path and the backfill are then one method over
 * one piece of JSON, so the yield this writes down and the yield the Nesting page's cards draw are
 * two readings of one number rather than two numbers.
 */
class BatchMeasurements
{
    /**
     * Write down what this batch's nest achieved.
     *
     * Null when there is nothing to measure: a batch with no saved nest (one exists from the moment
     * somebody starts quoting), or one carrying nothing the meterage algorithm handles - a
     * bolts-only batch is bought by the box and has no yield in millimetres at all. A row of zeros
     * for either would drag every month it landed in towards nothing.
     *
     * Leaves an existing row alone. Re-reading the same nest would produce the same figures, so
     * there is nothing to gain by it and one thing to lose: the cost flag below is resolved against
     * what the batch retained, and a row rewritten after an administrator backfilled something would
     * change its own history.
     */
    public function recordNest(Batch $batch): ?BatchMeasurement
    {
        $existing = $batch->measurement()->first();

        if ($existing !== null && $existing->nested_at !== null) {
            return $existing;
        }

        $nest = $batch->nested_state;

        if ($nest === [] || ($nest[NestingEnums::METERAGE->value] ?? []) === []) {
            return null;
        }

        $usage = (new NestingFormatter)->usageStats($nest)[NestingEnums::METERAGE->value] ?? [];

        $yield = [
            /*
             * When the nest was saved, which is the batch's own beginning: a batch is created and
             * nested in one press (QuoteController::store). Its own column rather than created_at on
             * this row so a figure reconstructed by the backfill lands in the month the steel was
             * nested - the same reasoning, and the same date, as scraps.scrapped_at.
             */
            'nested_at' => $batch->created_at ?? now(),

            'purchased_mm' => (float) ($usage['totalPurchasedMaterial'] ?? 0),
            'offcut_mm' => (float) ($usage['totalOffcutMaterial'] ?? 0),
            'consumed_mm' => (float) ($usage['totalConsumed'] ?? 0),
            'used_mm' => (float) ($usage['totalUsedMaterial'] ?? 0),
            'reusable_mm' => (float) ($usage['totalReusable'] ?? 0),
            'kerf_mm' => (float) ($usage['totalKerf'] ?? 0),
            'scrap_mm' => (float) ($usage['totalScrap'] ?? 0),
            'efficiency' => (float) ($usage['efficiency'] ?? 0),
            'effective_efficiency' => (float) ($usage['effectiveEfficiency'] ?? 0),

            /*
             * What the nest was costed at, as it was costed - read out of the saved nest rather than
             * struck again here. See Batch::nestCost().
             *
             * The flag beside it says whether the coefficients behind that figure were retained with
             * the nest. It is false on every batch nested before they were, and a report that prints
             * those dollars the same way as the rest would be presenting a current valuation as a
             * historical record.
             */
            'cost' => $batch->nestCost(),
            'cost_from_retained_settings' => NestingSettings::wasRetained($batch),
        ];

        if ($existing !== null) {
            $existing->fill($yield)->save();

            return $existing;
        }

        return BatchMeasurement::create(['batch_id' => $batch->id] + $yield);
    }

    /**
     * Write down that this batch's steel arrived, and whether it arrived in time.
     *
     * Called by every press that can complete a delivery - the card's "All delivered", the order
     * list's per-merchant mark and a goods receipt booked against a real order - because any of the
     * three can be the one that finishes the job, and the batch is only measurable once all of them
     * are in. Whether it is finished is asked of the batch itself (Batch::isDelivered), not of the
     * press that called, so no caller has to know what the other two have been doing.
     *
     * Null when the batch is not fully delivered yet, which is the ordinary case for all but the
     * last press.
     */
    public function recordDelivery(Batch $batch, Business $business, ?Carbon $deliveredOn = null): ?BatchMeasurement
    {
        if (! $batch->isDelivered($business)) {
            return null;
        }

        /*
         * A delivery is measured once, on the day the last of it landed. A second press is somebody
         * confirming what is already recorded - a stale tab, a colleague marking the same merchant -
         * and must not restate the day or the lateness.
         */
        $measurement = $batch->measurement()->first() ?? $this->recordNest($batch);

        if ($measurement !== null && $measurement->delivered_on !== null) {
            return $measurement;
        }

        /*
         * A batch with no nest to measure still has a delivery worth recording. The row is written
         * with its yield columns untouched and nested_at left null, which is what keeps it out of
         * the yield series - see App\Models\BatchMeasurement and MeasuresReport.
         */
        $measurement ??= BatchMeasurement::create(['batch_id' => $batch->id]);

        $deliveredOn = ($deliveredOn ?? now())->startOfDay();

        /*
         * The day the steel was wanted, as that day stands NOW - at the delivery, not afterwards.
         *
         * This is the whole reason it is copied onto the row. The date is the earliest fabrication
         * date among the jobs on the batch, less a working day, and a fabrication date is shared,
         * editable state: every manager with a job on this batch may move theirs, and the three edit
         * rules on it (see Project) deliberately leave it movable until every batch carrying the
         * job's steel is delivered or cut. A delivery measured by looking the date up later would
         * report a fortnight's slip as a delivery that was always going to be on time.
         */
        $requiredOn = Project::earliestMaterialsRequiredDate($batch->projectDates())?->startOfDay();

        $measurement->fill([
            'required_on' => $requiredOn,
            'delivered_on' => $deliveredOn,
            /*
             * Calendar days, signed: positive is late, zero is the day itself, negative is early.
             * Null where nothing was promised, which is neither on time nor late - see
             * BatchMeasurement::onTime().
             */
            'days_late' => $requiredOn === null
                ? null
                : (int) round($requiredOn->diffInDays($deliveredOn, false)),
        ])->save();

        return $measurement;
    }
}
