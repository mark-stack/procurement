<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Product;
use App\Models\Scrap;
use App\Services\NestingCostModel;
use App\Services\NestingSettings;
use App\Services\ProductService;
use App\Services\ProductSpec;
use App\Services\SupplierGroupCosts;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Re-price scrap rows whose money was worked out through the wrong material.
 *
 * THIS RESTATES HISTORY, which the rest of the application goes to some trouble not to do - a nest
 * keeps the figures it was run on (Services\NestingSettings) precisely so that changing a setting
 * today does not change what February cost. So this is a command somebody runs on purpose, with a
 * dry run by default, and not a migration: a migration would re-price every environment's history
 * without anybody choosing to, which is the thing being guarded against.
 *
 * WHAT IT IS CORRECTING IS A MEASUREMENT ERROR, NOT A CHANGE OF MIND. That distinction is the whole
 * justification, and it is worth being precise about which half of a valuation comes from where:
 *
 *  - The MASS is a fact about the section. A section weighs what it weighs; where the catalogue
 *    had no figure for it the cost model fell back to a yard-wide 10.0, which is a heavy number for
 *    anything light. Re-reading it from the catalogue is correcting a measurement, and every row it
 *    touches is one that said so at the time - kg_per_m_estimated was already true on it.
 *  - The PRICE PER TONNE and the SCRAP RECOVERY RATE are policy, and they come from the batch's own
 *    retained snapshot, never from today's business row. The only thing that reaches them is the
 *    merchant layer (Services\SupplierGroupCosts), and only where the snapshot is silent - a batch
 *    nested before merchants existed names no merchant, so it falls back to the platform's figures
 *    for that merchant exactly as NestingSettings::asOf documents for any coefficient added later.
 *
 * The practical case it was written for: ten 200mm LVL drops valued at the steel price with 13%
 * credited back from a weighbridge that never saw them, because a timber merchant does not buy
 * offcuts. The timber left the catalogue in October 2026 and the correction it needed is why this
 * exists; the next merchant whose figures are wrong will need the same thing.
 *
 * ROWS ARE RE-VALUED IN PLACE, never deleted and rewritten. That the drop happened, how long it
 * was, which bar it came off, who decided it and when are all facts and none of them is in question;
 * only the money derived from them was wrong. Re-running the ledger would also re-derive the
 * identity of each row, which is a far larger claim than the one being made here.
 *
 * Safe to run twice: a row already carrying the right figures is left alone and reported as
 * unchanged.
 */
class RevalueScrap extends Command
{
    protected $signature = 'scrap:revalue {--apply : Write the corrections. Without this nothing is written}
                                          {--business= : One business id, instead of every business}
                                          {--category= : One product category, e.g. PLATE}';

    protected $description = 'Re-price scrap rows whose valuation used the wrong material';

    /**
     * How close two figures have to be before this calls them the same.
     *
     * A cent, and a hundredth of a kilogram. Every one of these is stored as a float and arrived
     * through a chain of multiplications, so an exact comparison would rewrite rows that are already
     * right and report a correction nobody made.
     */
    private const TOLERANCE = 0.005;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $rows = Scrap::query()
            ->when($this->option('category'), fn (Builder $query, string $category) => $query
                ->where('product_category', $category))
            ->when($this->option('business'), fn (Builder $query, string $business) => $query
                ->whereIn('batch_id', Business::query()->whereKey((int) $business)->first()?->batches()->select('batches.id') ?? []))
            ->with(['batch.user.business'])
            ->orderBy('id')
            ->get();

        $corrected = [];
        $unchanged = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $revalued = $this->revalue($row);

            if ($revalued === null) {
                $skipped++;

                continue;
            }

            if ($this->matches($row, $revalued)) {
                $unchanged++;

                continue;
            }

            $corrected[] = [$row, $revalued];
        }

        $this->report($corrected, $unchanged, $skipped);

        if ($corrected === []) {
            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('Nothing written. Re-run with --apply to write these corrections.');

            return self::SUCCESS;
        }

        foreach ($corrected as [$row, $revalued]) {
            $row->forceFill($revalued)->save();
        }

        $this->info('Re-valued '.count($corrected).' scrap rows.');

        return self::SUCCESS;
    }

    /**
     * What this row's money should say, or null where there is nothing trustworthy to say it from.
     *
     * @return array<string, mixed>|null
     */
    private function revalue(Scrap $row): ?array
    {
        $business = $row->batch?->user?->business;

        if (! $business instanceof Business) {
            //The same refusal ScrapLedger makes: there is no honest figure without a business
            return null;
        }

        $catalogueMass = $this->catalogueMass($row, $business);

        $model = new NestingCostModel(
            //Policy from the batch's own snapshot, exactly as the ledger reads it
            NestingSettings::asOf($row->batch, $business),
            $catalogueMass,
            null,
            SupplierGroupCosts::forCategory($row->product_category),
        );

        $length = (float) $row->length;

        $revalued = [
            'weight_kg' => $model->mmToKg($length),
            'value' => $model->mmToCost($length),
            'recovered_value' => $model->scrapIncome($length),
            'kg_per_m' => $model->mmToKg(1000),
            'kg_per_m_estimated' => $catalogueMass === null,
        ];

        /*
         * carried_value is what the rack was still carrying a cleanout piece at, and it is derived
         * through the same mass and the same price - so it moves with them. Left alone on a nest
         * drop, where it is null by definition: a drop was never banked, and that distinction is
         * what the column exists to keep.
         */
        if ($row->carried_value !== null) {
            //Whole millimetres, which is what the retention curve is defined over - a cleanout
            //records the offcut's own length and an offcut is an integer number of millimetres
            $revalued['carried_value'] = $model->mmToCost($model->inventoryValueMm((int) $length));
        }

        return $revalued;
    }

    /**
     * What the catalogue says a metre of this section weighs, or null if it still cannot say.
     *
     * Matched on the category's MANDATORY spec columns, which is what identifies the section
     * itself - and pointedly not on ProductSpec's full key, which adds the purchasable variations.
     * For a meterage product that means nominal_length, and A SCRAP ROW HAS NONE: it describes a
     * remnant, not a stock length, so every product carries 9000 or 12000 there and the row carries
     * nothing. Matching on it finds no product at all, every row falls back to the yard default,
     * and this command would "correct" a perfectly good 17.70 kg/m PFC down to 10.0.
     *
     * The same set NestingFormatter::resolveKgPerM matches on, for the same reason - these tables
     * carry no product_id (see Services\ProductSpec) - and the heaviest where several agree, so two
     * runs of this command cannot disagree about an unordered result.
     *
     * Null leaves the row on the business default and keeps kg_per_m_estimated true, which is the
     * honest answer for a section nothing in the catalogue can weigh.
     */
    private function catalogueMass(Scrap $row, Business $business): ?float
    {
        $mandatory = (new ProductService)->generalProductDefinition((string) $row->product_category)['mandatory'];

        //Only the columns a scrap row actually carries. nominal_units is a piece column, not one of these
        $columns = array_intersect($mandatory, ProductSpec::USAGE_TABLE_COLUMNS['offcuts']);

        if ($columns === []) {
            return null;
        }

        $query = Product::query()->availableForBusiness($business);

        foreach ($columns as $column) {
            $value = $row->{$column};

            /*
             * Blank matches blank either way round. The products table has held '' for every
             * unanswered cell since the first spreadsheet import, while a scrap row copied from an
             * offcut holds null - the same absence, stored two ways.
             */
            $value === null || $value === ''
                ? $query->where(fn (Builder $blank) => $blank->whereNull($column)->orWhere($column, ''))
                : $query->where($column, $value);
        }

        $mass = $query->max('kg_per_m');

        return $mass !== null && (float) $mass > 0 ? (float) $mass : null;
    }

    /**
     * @param  array<string, mixed>  $revalued
     */
    private function matches(Scrap $row, array $revalued): bool
    {
        foreach ($revalued as $column => $value) {
            if (is_bool($value)) {
                if ((bool) $row->{$column} !== $value) {
                    return false;
                }

                continue;
            }

            if (abs((float) $row->{$column} - (float) $value) > self::TOLERANCE) {
                return false;
            }
        }

        return true;
    }

    /**
     * What would change, grouped by section rather than listed row by row.
     *
     * Ten identical 200mm drops of one section are one correction somebody needs to agree with, not ten - and
     * a list that reads as ten invites skimming past the one row that is different.
     *
     * @param  array<int, array{0: Scrap, 1: array<string, mixed>}>  $corrected
     */
    private function report(array $corrected, int $unchanged, int $skipped): void
    {
        if ($corrected === []) {
            $this->info('Nothing to correct. '.$unchanged.' rows already carry the right figures.');

            return;
        }

        $grouped = [];

        foreach ($corrected as [$row, $revalued]) {
            $key = trim((string) $row->product_derived_label).'|'.$row->product_category;

            $grouped[$key] ??= [
                'label' => trim((string) $row->product_derived_label),
                'merchant' => SupplierGroupCosts::forCategory($row->product_category) ?? 'the yard',
                'rows' => 0,
                'was_value' => 0.0,
                'now_value' => 0.0,
                'was_recovered' => 0.0,
                'now_recovered' => 0.0,
                'was_kg' => (float) $row->kg_per_m,
                'now_kg' => (float) $revalued['kg_per_m'],
            ];

            $grouped[$key]['rows']++;
            $grouped[$key]['was_value'] += (float) $row->value;
            $grouped[$key]['now_value'] += (float) $revalued['value'];
            $grouped[$key]['was_recovered'] += (float) $row->recovered_value;
            $grouped[$key]['now_recovered'] += (float) $revalued['recovered_value'];
        }

        $this->table(
            ['Section', 'Merchant', 'Rows', 'kg/m', 'Written off', 'Bin paid'],
            array_map(fn (array $group): array => [
                $group['label'],
                $group['merchant'],
                $group['rows'],
                $this->change($group['was_kg'], $group['now_kg'], 2),
                $this->change($group['was_value'], $group['now_value'], 2),
                $this->change($group['was_recovered'], $group['now_recovered'], 2),
            ], $grouped),
        );

        $this->line($unchanged.' rows already correct, '.$skipped.' skipped for having no business to price against.');
    }

    private function change(float $was, float $now, int $decimals): string
    {
        return number_format($was, $decimals).' -> '.number_format($now, $decimals);
    }
}
