<?php

namespace App\Services;

use App\Enums\NestingEnums;
use App\Enums\ScrapSourceEnums;
use App\Models\Bar;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Offcut;
use App\Models\Scrap;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The one thing that writes scrap down.
 *
 * Two events destroy steel and both come through here, so the weight and the money are worked out in
 * one place and cannot drift between them:
 *
 *   recordNest()     - a cut plan leaving a drop too short to bank. Arithmetic, not a decision.
 *   recordCleanout() - somebody weighing in dead stock off the rack. A decision, not arithmetic.
 *
 * recordNest() reads the saved nest rather than being told what to write, which is deliberate and is
 * what makes a batch nested before this table existed reconcilable: the same method, given the same
 * nested_state, produces the same rows whether it runs a second after the nest was saved or a year
 * later from the backfill command. The per-bar scrap figures the nesting screens have always shown
 * (NestingFormatter::summariseBars) and the rows in this table are therefore two readings of one
 * number, not two numbers.
 *
 * Nothing here deletes. Unwinding a batch takes its scrap with it through the foreign key - see the
 * migration - which is the only way a row goes away.
 */
class ScrapLedger
{
    /**
     * Write the scrap a batch's nest left behind, from the nest itself.
     *
     * Idempotent by batch: a batch that already has NEST DROP rows is left alone. Re-reading a nest
     * and writing it again would double every figure in the report, and there is no version of this
     * that wants to happen twice - a re-nest unwinds the batch first, which takes the old rows with
     * it.
     *
     * THE SOURCE FILTER IS LOAD-BEARING, and the lack of it was a silent hole. A cleanout row carries
     * the batch that originally cut the offcut being weighed in (see recordCleanout), so a batch one
     * of whose offcuts had already been scrapped off the rack looked, to an unfiltered existence
     * check, like a batch whose nest was already recorded. The backfill then skipped it for good and
     * its drops never reached the report. The two sources are not interchangeable - they are not even
     * the same steel - and only this one is written from the nest.
     *
     * @return int how many rows were written
     */
    public function recordNest(Batch $batch): int
    {
        $business = $batch->user?->business;

        if (! $business instanceof Business) {
            /*
             * Not reachable from the application - a batch is created with a user on it by
             * QuoteController::store - but the money on every row below is resolved against this
             * business's own cost coefficients, and there is no honest figure to write without one.
             * Said out loud rather than written as zeros, which would read as "nothing was destroyed".
             */
            Log::warning('Scrap not recorded: the batch has no business to value it against', [
                'batch_id' => $batch->id,
            ]);

            return 0;
        }

        if (Scrap::query()->where('batch_id', $batch->id)->where('source', ScrapSourceEnums::NEST_DROP)->exists()) {
            return 0;
        }

        $drops = $this->dropsIn($batch, $business);

        if ($drops === []) {
            return 0;
        }

        foreach ($drops as $drop) {
            Scrap::create($drop);
        }

        return count($drops);
    }

    /**
     * Write the scrap one offcut became when somebody weighed it in off the cleanout list.
     *
     * Takes the candidate row rather than the offcut, so the money recorded is the money the page
     * showed. The cleanout has already resolved this section's mass per metre against the catalogue
     * and priced the piece both ways (Services\OffcutCleanout::candidates); re-deriving it here would
     * be a second opinion on a decision somebody has already made on the strength of the first.
     *
     * @param  array<string, mixed>  $candidate  one row of OffcutCleanout::candidates()
     */
    public function recordCleanout(array $candidate, User $user, ?string $note = null): Scrap
    {
        /** @var Offcut $offcut */
        $offcut = $candidate['offcut'];

        $kgPerM = (float) $candidate['kg_per_m'];

        return Scrap::create($this->specOf($offcut, $offcut->product_derived_label) + [
            //The batch that cut this offcut. The only batch the steel was ever part of
            'batch_id' => $offcut->batch_from_id,
            /*
             * The offcut itself, not the bar behind it. This row IS that piece of steel reaching the
             * end of its life, and an offcut of an offcut has no bar to name anyway.
             */
            'bar_id' => null,
            'offcut_id' => $offcut->id,

            'source' => ScrapSourceEnums::CLEANOUT,
            'scrapped_by_user_id' => $user->id,
            'note' => $note,

            'length' => (float) $offcut->length,
            'weight_kg' => ($offcut->length / 1000) * $kgPerM,

            /*
             * The LANDED cost of this steel, the same quantity a nest drop records - not what the
             * rack was carrying it at.
             *
             * These two used to be one column and the carried figure is what went into it, which
             * made netLoss() negative for essentially every piece this list proposes: the carried
             * value of a remnant below its own racking floor is a fraction of the steel, while the
             * bin pays 13% of all of it. The ledger was reporting that destroying dead stock earned
             * the yard money. A write-off is a write-off whatever the rack had stopped valuing it
             * at - that is the point of writing it off - so value means the steel, and what it was
             * being carried at is recorded beside it.
             */
            'value' => (float) $candidate['landed'],
            'carried_value' => (float) $candidate['worth'],
            'recovered_value' => (float) $candidate['bin_recovers'],
            'kg_per_m' => $kgPerM,
            //The cleanout says whether the catalogue answered; false here means it did not
            'kg_per_m_estimated' => $candidate['kg_per_m_resolved'] !== true,

            'scrapped_at' => now(),
        ]);
    }

    /**
     * Every drop in a batch's saved nest that was too short to bank, as rows ready to write.
     *
     * Only meterage destroys steel this way - bundle and area materials are bought as units - so the
     * other algos in nested_state are passed over rather than guessed at.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dropsIn(Batch $batch, Business $business): array
    {
        $products = $batch->nested_state[NestingEnums::METERAGE->value] ?? [];

        if ($products === []) {
            return [];
        }

        $barIds = $this->barIdsFor($batch, $products);
        $barCursor = 0;

        /*
         * Priced on the figures this nest was actually run on, not on the business's figures today.
         *
         * A batch nested in February and valued in June was being valued at June's steel price and
         * June's recovery rate, so the kilograms were history and the dollars beside them were a
         * current opinion - which is the whole gap this closes. The retained snapshot is a carrier
         * for those numbers rather than the business itself (see Services\NestingSettings), and a
         * batch nested before it existed falls back to the live business exactly as it always did.
         */
        $costSettings = NestingSettings::asOf($batch, $business);

        //One cost model per section, not one per drop - a batch is a handful of sections
        $models = [];
        $rows = [];

        foreach ($products as $product) {
            $kgPerM = isset($product->kg_per_m) && (float) $product->kg_per_m > 0
                ? (float) $product->kg_per_m
                : null;

            /*
             * Costed as the merchant it came from, not as steel. The case that forced it was the
             * catalogue's LVL: a 200mm drop valued at the steel price AND credited a scrap-merchant
             * rebate on the way out, when a weighbridge buys metal and a timber merchant does not
             * buy offcuts back at all. The timber went in October 2026 and the rule holds for every
             * merchant that is not the steel one. The supplier group is resolved off the retained
             * settings like everything else here, so a batch keeps the rates it was nested on.
             */
            $supplierGroup = SupplierGroupCosts::forCategory($product->product_category ?? null);

            $key = ($kgPerM ?? 'default').'|'.($supplierGroup ?? '-');
            $model = $models[$key] ??= new NestingCostModel($costSettings, $kgPerM, null, $supplierGroup);

            //Null means the catalogue had no mass for this section, so the model is pricing it at the
            //business default - which the row has to say, or a guess reads like a measurement
            $massEstimated = $kgPerM === null;

            $spec = $this->specOf($product, (string) ($product->product_derived_label ?? ''));

            //New stock: a bar whose leftover did not reach the threshold is scrapped rather than racked
            foreach ($product->nested['utilisedBars'] ?? [] as $utilisedBar) {
                $drop = (float) ($utilisedBar['result']['unused'] ?? 0);
                $threshold = $this->thresholdIn($utilisedBar['result'] ?? [], $costSettings);
                $count = (int) ($utilisedBar['count'] ?? 0);

                /*
                 * Bars are consumed in nested_state order whether or not this group scrapped anything,
                 * so a group that banked its drop still advances the cursor. Skipping it would pair
                 * every later group with the wrong bars.
                 */
                $groupBarIds = array_slice($barIds, $barCursor, $count);
                $barCursor += $count;

                if ($drop <= 0 || $drop >= $threshold) {
                    continue;
                }

                /*
                 * "count" identical bars each leave their own drop. One row each, not one row of
                 * count x drop: this is the same reasoning NestingFormatter::summariseBars applies
                 * when it refuses to call ten 400mm leftovers 4m of reusable stock.
                 */
                for ($i = 0; $i < $count; $i++) {
                    $rows[] = $spec + $this->valuation($model, $drop, $massEstimated) + [
                        'batch_id' => $batch->id,
                        'bar_id' => $groupBarIds[$i] ?? null,
                        'offcut_id' => null,
                        'source' => ScrapSourceEnums::NEST_DROP,
                        'scrapped_by_user_id' => null,
                        'note' => null,
                        'length' => $drop,
                        'scrapped_at' => $batch->created_at ?? now(),
                    ];
                }
            }

            //Off the rack: what was left of an offcut after the nest cut into it
            foreach ($product->nested['bestResultOffcuts']['utilisedOffcutBars'] ?? [] as $offcutBar) {
                $drop = (float) ($offcutBar['scrap']['scrapLength'] ?? 0);

                if ($drop <= 0) {
                    continue;
                }

                $rows[] = $spec + $this->valuation($model, $drop, $massEstimated) + [
                    'batch_id' => $batch->id,
                    'bar_id' => null,
                    //The offcut this came off is named in the nest, however old the batch is
                    'offcut_id' => $offcutBar['sourceOffcut']['offcutId'] ?? null,
                    'source' => ScrapSourceEnums::NEST_DROP,
                    'scrapped_by_user_id' => null,
                    'note' => null,
                    'length' => $drop,
                    'scrapped_at' => $batch->created_at ?? now(),
                ];
            }
        }

        return $rows;
    }

    /**
     * The scrap threshold this bar was actually judged against.
     *
     * Read off the saved nest rather than off the business, because the number moves. A yard that
     * raised its threshold from 1,000mm to 1,500mm last month did not retrospectively scrap every
     * 1,200mm offcut it banked before then - those pieces are on the rack. Judging an old nest by
     * today's figure would invent scrap that never happened and lose the offcut it really produced.
     *
     * The fallback is now the batch's retained settings rather than the live business, which makes
     * the two copies of this figure say the same thing: the per-bar stamp is the exact one and this
     * is the batch-wide one behind it. Only a batch older than both lands on today's number.
     *
     * @param  array<string, mixed>  $result
     */
    private function thresholdIn(array $result, Business $settings): float
    {
        $threshold = $result['scrap_threshold_mm'] ?? null;

        return $threshold === null ? (float) $settings->scrap_threshold_mm : (float) $threshold;
    }

    /**
     * The bars this batch cut, in the order the nest produced them.
     *
     * Two routes, and the first is the only exact one. App\Actions\Bar\CreateBarsAndOffcuts stamps
     * the ids it wrote into nested_state as it goes, the way it has always stamped the offcut ids, so
     * for anything nested from here on each group names its own bars.
     *
     * A batch nested before that has to be paired by order instead: bars are created product by
     * product, group by group, copy by copy, so bars.id ascending is the same sequence nested_state
     * walks. That pairing is only taken when the two counts agree exactly - one deleted bar, or a
     * batch from before bars carried a batch_id at all (the 2026_09_26 migration), and every drop
     * after it would be hung on the wrong bar. A wrong bar is worse than no bar: it is the kind of
     * mistake that reads as traceability. Where they disagree the rows are written naming no bar,
     * which costs the per-bar detail and keeps every figure in the report true.
     *
     * @param  array<int, object>  $products
     * @return array<int, int|null>
     */
    private function barIdsFor(Batch $batch, array $products): array
    {
        $stamped = [];
        $expected = 0;

        foreach ($products as $product) {
            foreach ($product->nested['utilisedBars'] ?? [] as $utilisedBar) {
                $count = (int) ($utilisedBar['count'] ?? 0);
                $expected += $count;

                $ids = $utilisedBar['result']['bar_ids'] ?? null;

                //All or nothing per group: a half-stamped group would shift everything after it
                if (is_array($ids) && count($ids) === $count) {
                    foreach ($ids as $id) {
                        $stamped[] = (int) $id;
                    }

                    continue;
                }

                for ($i = 0; $i < $count; $i++) {
                    $stamped[] = null;
                }
            }
        }

        if (! in_array(null, $stamped, true)) {
            return $stamped;
        }

        $actual = Bar::query()
            ->where('batch_id', $batch->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (count($actual) !== $expected) {
            Log::info('Scrap recorded without naming its bars: the saved nest and the bars on the batch do not line up', [
                'batch_id' => $batch->id,
                'bars_in_nest' => $expected,
                'bars_on_batch' => count($actual),
            ]);

            return $stamped;
        }

        return $actual;
    }

    /**
     * What a length of one section weighed and what destroying it cost.
     *
     * The mass stored is read back out of the model (mmToKg of a metre) rather than taken from the
     * catalogue lookup, so a section the catalogue could not price records the default the model
     * actually fell back to. A row whose money rests on default_kg_per_m is not wrong, but it is a
     * different kind of answer to one priced off the real section - for a heavy beam the two are a
     * long way apart - and the flag beside it is what lets a report say so.
     *
     * @return array<string, mixed>
     */
    private function valuation(NestingCostModel $model, float $lengthMm, bool $massEstimated): array
    {
        return [
            'weight_kg' => $model->mmToKg($lengthMm),
            'value' => $model->mmToCost($lengthMm),
            /*
             * Null rather than zero. A drop is below the scrap threshold by definition, so the rack
             * never carried it at anything - which is not the same statement as a remnant the rack
             * had given up on, and that is the distinction the column exists to keep.
             */
            'carried_value' => null,
            'recovered_value' => $model->scrapIncome($lengthMm),
            'kg_per_m' => $model->mmToKg(1000),
            'kg_per_m_estimated' => $massEstimated,
        ];
    }

    /**
     * The product columns, off a nested_state product or off an offcut - both carry the same names.
     *
     * Copied onto the row rather than joined to. The catalogue is editable (see
     * Services\ProductSpec), and a scrap row has to keep saying what was destroyed after the product
     * it was matched to has been renamed or re-specced.
     *
     * @return array<string, mixed>
     */
    private function specOf(object $source, string $derivedLabel): array
    {
        $spec = [];

        foreach (ProductSpec::USAGE_TABLE_COLUMNS['offcuts'] as $column) {
            $spec[$column] = $source->{$column} ?? null;
        }

        $spec['product_derived_label'] = $derivedLabel;

        return $spec;
    }
}
