<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Offcut;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * The offcuts that have stopped earning their place on the rack, for a human to weigh in.
 *
 * Nesting will not do this. The cost model prices a remnant honestly - what it retains against the
 * labour of keeping it - but once a drop has cleared the scrap threshold, banking it is always
 * cheaper than binning it: the bin pays back about 13% and destroys the rest, so binning a 1.2m
 * angle stub writes off $11.90 of steel to dodge $5 of handling. That arithmetic never changes, so
 * the nest can never be the thing that clears the rack.
 *
 * What changes is time. A stub that a nest draws on next month cost nobody anything; the identical
 * stub still sitting there four quarters later has been marked, shifted and looked past enough
 * times to have spent its own value several times over, and it is not going to be used now. THAT is
 * the write-off, and it is a fact about how long it sat rather than about how long it is.
 *
 * So a candidate has to fail on both counts:
 *
 *  1) It has sat in inventory for at least the shelf life - see config/offcuts.php.
 *  2) It is shorter than the length at which an offcut of ITS OWN SECTION pays for its own keep -
 *     Services\NestingCostModel::worthRackingFromMm(). That length is not a constant: at the default
 *     coefficients it is about 2,250mm for 65x65x6 angle and about 1,040mm for a 500UB, because a
 *     metre of beam is worth far more than the quarter hour it takes to deal with. An old 1.5m
 *     length of heavy beam is not a candidate, and a young one of light angle is not either.
 *
 *     It also moves with what the business pays in FREIGHT, which is why this list is shorter for a
 *     yard having its steel delivered than for one collecting it. Delivery raises what a remnant is
 *     worth without raising the labour of keeping it, so fewer pieces fall below the floor - and the
 *     money columns follow: 'worth' is the landed value, 'bin_recovers' is what a merchant pays for
 *     the metal alone, and the gap between them widens the more the delivery cost.
 *
 * Nothing here removes anything. It produces a list with the money written next to each row, and
 * the yard decides - see Http\Controllers\OffcutScrapController. Scrapping is reversible (the row
 * is flagged, never deleted) but the steel is not, and neither the length of a piece nor the date
 * on it knows whether next month's job needs it.
 */
class OffcutCleanout
{
    /**
     * The reference stock length used when the catalogue cannot say what this section comes in.
     *
     * "As good as stock" is what anchors the top of the retention curve, and the curve needs a span
     * to work across. 12,000mm is the cap most businesses buy to, and it is what the admin
     * explainer draws its curves against, so the two pages agree.
     *
     * Emphatically NOT the offcut's own length. That reads as "this piece is as good as a full bar",
     * which puts retention at its cap for every row and values the shortest stub on the rack as
     * highly as the longest - the exact opposite of what the curve is for.
     */
    private const FALLBACK_REFERENCE_MM = 12000;

    /**
     * How long a piece may sit before age stops counting in its favour.
     */
    public function shelfLifeDays(): int
    {
        $days = (int) config('offcuts.shelf_life_days');

        /*
         * Guarded rather than trusted. A shelf life of zero or less would make every offcut in the
         * yard old enough on the day it was cut, which turns a quarterly review of the dead stock
         * into a proposal to scrap the entire rack.
         */
        return $days > 0 ? $days : 365;
    }

    /**
     * Everything this business is carrying that is both past its shelf life and too short to pay for
     * its own keep, worst drain first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function candidates(Business $business): Collection
    {
        /** @var Collection<int, Offcut> $expired */
        $expired = $business->availableOffcuts()
            ->where('created_at', '<=', now()->subDays($this->shelfLifeDays()))
            ->with(['bar', 'sourceBatch.user.business'])
            ->get();

        if ($expired->isEmpty()) {
            return collect([]);
        }

        $catalogue = $this->catalogueBySpec($business, $expired);

        //One model per (mass per metre, reference length), not one per offcut - a rack of fifty
        //stubs of the same section asks worthRackingFromMm() the same question fifty times
        $models = [];

        /** @var array<int, array<string, mixed>> $candidates */
        $candidates = [];

        foreach ($expired as $offcut) {
            $signature = $this->specSignature($offcut);
            $kgPerM = $catalogue[$signature]['kg_per_m'] ?? null;

            $length = (int) $offcut->length;
            $reference = $this->referenceLengthMm($offcut, $catalogue[$signature]['reference_mm'] ?? null);

            /*
             * The merchant is part of the key, not just part of the model. Two offcuts of the same
             * mass and the same reference length are not the same costing question if one came from
             * the steel merchant and one from the profile cutter - the price per tonne, the freight
             * and what the bin pays back all differ, and a shared model would quietly answer for
             * whichever was reached first.
             */
            $supplierGroup = SupplierGroupCosts::forCategory($offcut->product_category);

            $key = ($kgPerM ?? 'default').'|'.$reference.'|'.($supplierGroup ?? '-');
            $model = $models[$key] ??= new NestingCostModel($business, $kgPerM, $reference, $supplierGroup);

            $floor = $model->worthRackingFromMm();

            /*
             * Null means nothing up to a full stock length of this section ever pays for its keep, so
             * every piece of it is under the floor. Otherwise the floor is a real length to be under.
             */
            if ($floor !== null && $length >= $floor) {
                continue;
            }

            $worth = $model->mmToCost($model->inventoryValueMm($length));
            $keepCost = $model->minutesToCost($model->offcutRackMinutes($length));

            $candidates[] = [
                'offcut' => $offcut,
                'age_days' => (int) $offcut->created_at->diffInDays(now()),
                'length_mm' => $length,
                'floor_mm' => $floor,
                'kg_per_m' => round($model->mmToKg(1000), 2),
                /*
                 * Whether the mass came from the catalogue or from the business default. A product
                 * whose spec columns were edited after this steel was cut no longer matches it - see
                 * Services\ProductSpec - and the model then costs it at default_kg_per_m, which for a
                 * heavy section understates what the offcut is worth by a wide margin. The page says
                 * so rather than quietly presenting a guess as a valuation.
                 */
                'kg_per_m_resolved' => $kgPerM !== null,
                'worth' => round($worth, 2),
                /*
                 * What owning this steel had cost, landed, with nothing taken off for how short it
                 * is. Not what the decision turns on - that is 'worth' against 'keep_cost' above,
                 * and this piece is a candidate precisely because the rack is no longer carrying it
                 * at anything like what it cost. It is here because it is what the scrap ledger
                 * records as the write-off, and both paths into that table have to mean the same
                 * thing by it. See Services\ScrapLedger::recordCleanout.
                 */
                'landed' => round($model->mmToCost($length), 2),
                'keep_cost' => round($keepCost, 2),
                //What the merchant would actually pay for it, which is the other half of the decision
                'bin_recovers' => round($model->scrapIncome($length), 2),
                'net_drain' => round($keepCost - $worth, 2),
            ];
        }

        return collect($candidates)
            //Worst drain first: the rows where keeping it costs most over what it is worth
            ->sortByDesc(fn (array $candidate): float => $candidate['net_drain'])
            ->values();
    }

    /**
     * What counts as "as good as stock" for this offcut, on the retention curve.
     *
     * In order: the longest stock length the catalogue sells this section in, then the stock length
     * the steel was actually cut from (every offcut carries it - CreateBarsAndOffcuts copies the
     * product's nominal_length onto it, and an offcut of an offcut inherits it down the chain), then
     * the fallback.
     *
     * Floored by the offcut's own length, because a reference shorter than the piece being valued
     * would put it off the top of the curve. Never DERIVED from it - see FALLBACK_REFERENCE_MM.
     */
    private function referenceLengthMm(Offcut $offcut, ?int $catalogueReferenceMm): int
    {
        $reference = $catalogueReferenceMm
            ?? ((int) $offcut->nominal_length > 0 ? (int) $offcut->nominal_length : self::FALLBACK_REFERENCE_MM);

        return max($reference, (int) $offcut->length);
    }

    /**
     * What the catalogue knows about each distinct section in the set: its mass per metre, and the
     * longest stock length it is sold in.
     *
     * Matched on the spec WITHOUT nominal_length, which is the stock length rather than part of the
     * section's identity - pinning it would match only the one bar this steel came off and leave
     * "the longest bar the business could buy" unanswerable.
     *
     * Mass is resolved the way nesting resolves it (NestingFormatter::resolveKgPerM): kg_per_m is
     * nullable and a spec matches several rows, so take the heaviest any of them carries. The
     * lengths are compared in PHP because nominal_length is a varchar, and SQL's max() on a varchar
     * is lexicographic - which makes '9000' longer than '12000'.
     *
     * One query per distinct section rather than one per offcut; a rack is a handful of sections in
     * many lengths.
     *
     * @param  Collection<int, Offcut>  $offcuts
     * @return array<string, array{kg_per_m: float|null, reference_mm: int|null}>
     */
    private function catalogueBySpec(Business $business, Collection $offcuts): array
    {
        $resolved = [];

        foreach ($offcuts as $offcut) {
            $signature = $this->specSignature($offcut);

            if (array_key_exists($signature, $resolved)) {
                continue;
            }

            $query = Product::query()->availableForBusiness($business);

            foreach ($this->sectionColumns() as $column) {
                $value = $offcut->getAttribute($column);

                /*
                 * A spec field can legitimately be null - not every category uses every field - and
                 * "where field = null" is never true in SQL, so a single null field would match no
                 * product at all and every offcut would fall back to the business default mass.
                 */
                $query = $value === null
                    ? $query->whereNull($column)
                    : $query->where($column, $value);
            }

            $matches = $query->get(['kg_per_m', 'nominal_length']);

            $kgPerM = $matches
                ->map(fn (Product $product): float => (float) $product->kg_per_m)
                ->filter(fn (float $mass): bool => $mass > 0)
                ->max();

            $reference = $matches
                ->map(fn (Product $product): int => (int) $product->nominal_length)
                ->filter(fn (int $length): bool => $length > 0)
                ->max();

            //Recorded even when empty, so the next offcut of this section does not re-run the query
            $resolved[$signature] = [
                'kg_per_m' => $kgPerM === null ? null : (float) $kgPerM,
                'reference_mm' => $reference === null ? null : (int) $reference,
            ];
        }

        return $resolved;
    }

    /**
     * The spec columns that identify the SECTION rather than the bar it was cut from.
     *
     * @return array<int, string>
     */
    private function sectionColumns(): array
    {
        return array_values(array_filter(
            ProductSpec::USAGE_TABLE_COLUMNS['offcuts'],
            fn (string $column): bool => $column !== 'nominal_length',
        ));
    }

    /**
     * The offcut's section as one comparable string.
     */
    private function specSignature(Offcut $offcut): string
    {
        $parts = [];

        foreach ($this->sectionColumns() as $column) {
            $parts[] = (string) $offcut->getAttribute($column);
        }

        return implode('|', $parts);
    }
}
