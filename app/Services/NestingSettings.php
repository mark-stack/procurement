<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Business;

/**
 * The figures a nest was run on, written down with the nest.
 *
 * Everything the nesting algorithm and the cost model read off the businesses row - the saw kerf,
 * the length below which a remnant is thrown away, and every coefficient that turns steel and
 * minutes into dollars. All of it was read live, which meant a batch's recorded cost, its recorded
 * scrap and the yard's recorded yield all moved whenever somebody edited a setting, long after the
 * steel had been cut.
 *
 * Two methods and one idea between them:
 *
 *   inForce()  - what the business's figures are right now, as a flat array, taken at the moment a
 *                nest is saved and stored on the batch.
 *   asOf()     - a Business carrying those retained figures, for anything costing that nest again
 *                afterwards. Falls back to the live business where a batch has no snapshot.
 *
 * THE RETAINED SNAPSHOT IS HANDED BACK AS A Business, which is worth being plain about because it
 * looks like a shortcut and is not one. Every consumer of these settings - NestingCostModel,
 * NestingFormatter::kerf, the threshold test in Actions\Bar\CreateBarsAndOffcuts - already takes a
 * Business and reads attributes off it, and Business carries the whole default set in $attributes,
 * so an instance built from a snapshot answers exactly as the row did on the day. The alternative
 * was a settings object threaded through all three, which is the same behaviour and a much larger
 * change to code that decides what steel gets bought. Services\NestingProof already builds an
 * unsaved Business this way to cost its worked examples.
 *
 * The instance is NEVER saved and has no id. It is a carrier for a dozen numbers, not the business -
 * ask the batch's user for that.
 */
class NestingSettings
{
    /**
     * Settings that decide a nest without being prices.
     *
     * The cost coefficients come from NestingCostModel::DEFAULTS, which is where they are documented
     * and defaulted. These two are the nest's own geometry: how much steel the blade turns into
     * swarf, and how short a remnant has to be before the yard stops keeping it.
     *
     * scrap_threshold_mm is retained here as well as per bar inside nested_state. The per-bar copy is
     * the older one and stays: it is what tells the scrap ledger what an individual bar's drop was
     * judged against, and Services\ScrapLedger reads it in preference to anything else. This copy is
     * what answers the same question for a batch whose bars predate that stamping.
     *
     * @var array<int, string>
     */
    public const array NESTING_KEYS = [
        'kerf_mm',
        'scrap_threshold_mm',
    ];

    /**
     * What this business's nesting figures are at this moment.
     *
     * Every key is present and every value is resolved - a business whose attribute is missing gets
     * the documented default rather than a null, because a snapshot with holes in it is read back
     * through the same fallbacks as no snapshot at all and so retains nothing. A zero a business has
     * actually chosen is a zero here, which is the distinction the cost model's own DEFAULTS note
     * turns on.
     *
     * A round figure comes back out of the JSON as an int rather than the float it went in as -
     * json_encode writes 2000.0 as "2000" - which costs nothing, since every reader of these casts.
     * Worth knowing before writing an identity comparison against one.
     *
     * @return array<string, float|int>
     */
    public static function inForce(Business $business): array
    {
        $settings = [];

        foreach (self::NESTING_KEYS as $key) {
            $settings[$key] = (int) ($business->getAttribute($key) ?? 0);
        }

        foreach (NestingCostModel::DEFAULTS as $key => $default) {
            $value = $business->getAttribute($key);

            $settings[$key] = (float) ($value === null ? $default : $value);
        }

        return $settings;
    }

    /**
     * The figures this batch was nested on, as something the cost model and the formatter can read.
     *
     * The retained snapshot where the batch has one, and the business's figures as they stand where
     * it does not - which is every batch nested before the column existed, and is the behaviour that
     * was there all along. A caller that needs to tell the two apart asks wasRetained() rather than
     * comparing the two, because they are frequently equal: a business that has not touched its
     * settings since February costs February's batches identically either way, and that is not the
     * same thing as having retained them.
     */
    public static function asOf(Batch $batch, Business $business): Business
    {
        $retained = $batch->nesting_settings;

        if (! is_array($retained) || $retained === []) {
            return $business;
        }

        /*
         * Built on top of the defaults Business declares, not instead of them. A snapshot written
         * before a coefficient existed simply does not mention it, and the new coefficient then falls
         * back to its own default - which is what the cost model would have done with a business that
         * had never been given one, and is the only answer that is not an invention.
         */
        return new Business($retained);
    }

    /**
     * Whether this batch's figures are the ones it was actually nested on.
     *
     * False for a batch nested before the snapshot existed. Reported rather than hidden: the
     * kilograms and the millimetres of such a batch are history, and the dollars beside them are
     * today's valuation of that history - the same distinction the scrap backfill draws.
     */
    public static function wasRetained(Batch $batch): bool
    {
        return is_array($batch->nesting_settings) && $batch->nesting_settings !== [];
    }
}
