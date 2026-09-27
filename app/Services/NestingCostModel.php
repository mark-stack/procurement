<?php

namespace App\Services;

use App\Models\Business;

/**
 * What one candidate nest costs the business, in dollars: the steel it consumes plus the time it takes.
 *
 * Nesting used to rank candidates on a list of millimetre totals compared in order - cuts made, then
 * purchase, then destruction, then shelf drawn on, then bar count, then largest drop. Three things that
 * ranking could not express, and that a fabricator pays for every day:
 *
 *  1) A banked offcut cost NOTHING. Scrap and kerf were penalised, reusable material was not, so the scrap
 *     threshold was a cliff: a 999mm drop cost 999 and a 1,001mm drop cost zero. With a thousand search
 *     iterations the solver reliably found the arrangement that landed just past the line, which is the
 *     least useful reusable length it is possible to produce. The rack filled up with 1.0-1.2m stubs of
 *     every section the shop touched, and the reported yield went UP as it happened.
 *
 *  2) Labour was free. A saw cut, finding an offcut in the rack, getting a bar off the lift - none of it
 *     scored, so yield won however many hours it cost to chase.
 *
 *  3) Millimetres are not comparable across sections, and are not comparable to time at all. Saving 500mm
 *     of 65x65 angle is worth about $6; saving 500mm of 500UB is worth $90. The same nesting decision is
 *     right for one and wrong for the other.
 *
 * So everything here is money. Material converts through the section's mass and the steel price; labour
 * converts through a duration and an hourly rate. The question the model is really answering is whether
 * the labour of preserving a remnant costs less than the remnant is worth - which is why going to lengths
 * over $9 of equal angle is never right, and why a $3,000 beam can carry a lot of extra handling first.
 *
 * Durations are derived, not flat, because a bigger section is more work to deal with:
 *
 *  - CUTTING scales with mass per metre, i.e. with how much section the blade has to get through. A cut is
 *    a cut whatever the length of the piece.
 *  - MOVING scales with the mass of the piece actually being moved. A 6m angle is carried; a 12m 500UB at
 *    over a tonne is a crane, slings and a second person.
 *
 * Deliberately NOT modelled here, because a nest is built for one product spec at a time and these are
 * properties of the whole order: delivery fees, per-supplier minimum order values, and consolidating
 * products onto a shared stock length. Those need a pass over all the nests together.
 */
class NestingCostModel
{
    /**
     * A cut nothing can hold is not a cheaper nest, it is a piece the workshop does not get. Large enough
     * that no amount of material or labour can buy its way past one.
     */
    public const UNMADE_CUT_PENALTY = 1_000_000_000.0;

    /** Matches the businesses.scrap_threshold_mm column default. */
    private const DEFAULT_SCRAP_THRESHOLD_MM = 1000;

    /**
     * Fallbacks for every coefficient, matching the column defaults in the migration that added them.
     *
     * These are not belt-and-braces. A Business instance that was loaded before these columns existed, or
     * built by a factory that does not mention them, has no such attribute - and reading a missing
     * attribute gives null, which casts to 0.0 rather than to the default the column carries. Every
     * coefficient at zero does not make the model cautious, it makes it silent: the cost collapses to
     * "everything not cut into a piece", which is the same number for every candidate nest, so the search
     * stops discriminating and returns whichever plan it happened to build first.
     *
     * A zero a business has actually chosen still reads as zero - the attribute is present then.
     */
    private const DEFAULTS = [
        'labour_rate_per_hour' => 50.00,
        'material_cost_per_tonne' => 2000.00,
        'scrap_recovery_rate' => 0.13,
        'default_kg_per_m' => 10.0,
        'cut_base_minutes' => 1.5,
        'cut_minutes_per_kg_per_m' => 0.06,
        'offcut_draw_base_minutes' => 4.0,
        'bar_handling_base_minutes' => 3.0,
        'offcut_rack_base_minutes' => 6.0,
        'move_minutes_per_tonne' => 15.0,
        'offcut_retention_cap' => 0.6,
        'purchase_cost_weight' => 1.0,
    ];

    private int $scrapThresholdMm;

    private float $kgPerM;

    /**
     * The length at which a drop is treated as being as good as stock - the longest bar this nest could
     * have bought. A drop that long is not really an offcut; it is a stock length that happens to be on
     * the rack.
     */
    private int $referenceLengthMm;

    public function __construct(
        private Business $business,
        ?float $kgPerM = null,
        ?int $referenceLengthMm = null,
    ) {
        /*
         * Guarded rather than read straight off the model. A null threshold reads as 0, and a threshold of
         * 0 tells the model every drop is worth banking - a 50mm offcut included - which is how a rack
         * fills with material nobody would ever pick up. Business declares a default for exactly this
         * reason; this is the backstop for an instance built some other way.
         */
        $threshold = $business->getAttribute('scrap_threshold_mm');
        $this->scrapThresholdMm = $threshold === null ? self::DEFAULT_SCRAP_THRESHOLD_MM : (int) $threshold;

        /*
         * Mass per metre drives BOTH the material cost and the labour time, so a zero or negative value
         * would not merely mis-price a nest, it would make every section cost the same and take the same
         * time to handle. Anything unusable falls back to the business default.
         */
        $resolvedKgPerM = $kgPerM ?? $this->setting('default_kg_per_m');
        $this->kgPerM = $resolvedKgPerM > 0 ? $resolvedKgPerM : self::DEFAULTS['default_kg_per_m'];

        /*
         * Guarded so the retention curve always has a span to work across. Without stock lengths to nest
         * against (a nest built entirely out of inventory) the threshold itself is the only anchor
         * available, and every drop then sits at the top of the curve.
         */
        $this->referenceLengthMm = max($referenceLengthMm ?? 0, $this->scrapThresholdMm + 1);
    }

    /**
     * One cost coefficient off the business, falling back when the attribute is not there at all.
     * See DEFAULTS.
     */
    private function setting(string $key): float
    {
        $value = $this->business->getAttribute($key);

        return $value === null ? self::DEFAULTS[$key] : (float) $value;
    }

    /**
     * Millimetres of this section as kilograms.
     */
    public function mmToKg(float|int $mm): float
    {
        return ($mm / 1000) * $this->kgPerM;
    }

    /**
     * Millimetres of this section as dollars of steel.
     */
    public function mmToCost(float|int $mm): float
    {
        return $this->mmToKg($mm) * ($this->setting('material_cost_per_tonne') / 1000);
    }

    /**
     * What the scrap bin pays back on a length of this section.
     *
     * Steel cut off and binned is not a total loss: it is weighed in and credited at roughly 13% of the new
     * price. Small next to the steel, but it is the difference between scrap being a write-off and scrap
     * being a cheap way out of a bad remnant - and it is real money on a heavy section, where 800mm binned
     * is $144 of steel and nearly $19 of it comes back.
     */
    public function scrapIncome(float|int $mm): float
    {
        return $this->mmToCost($mm) * $this->setting('scrap_recovery_rate');
    }

    /**
     * What binning a length of this section actually costs, net of what the bin pays back.
     */
    public function netScrapCost(float|int $mm): float
    {
        return $this->mmToCost($mm) - $this->scrapIncome($mm);
    }

    /**
     * Minutes of shop-floor time as dollars.
     */
    public function minutesToCost(float $minutes): float
    {
        return $minutes * ($this->setting('labour_rate_per_hour') / 60);
    }

    /**
     * How long one saw cut through this section takes.
     *
     * Scales with mass per metre, which stands in for how much section the blade has to get through. The
     * length of the piece does not come into it - a cut is a cut.
     */
    public function cutMinutes(): float
    {
        return $this->setting('cut_base_minutes')
            + ($this->setting('cut_minutes_per_kg_per_m') * $this->kgPerM);
    }

    /**
     * How long it takes to move a piece this long, on top of a base time for the job in hand.
     *
     * The mass term is what makes a 12m 500UB a different proposition from a 6m angle: one is a crane
     * lift with slings and a second person, the other is picked up and carried.
     */
    private function moveMinutes(float $baseMinutes, int $lengthMm): float
    {
        return $baseMinutes + ($this->setting('move_minutes_per_tonne') * ($this->mmToKg($lengthMm) / 1000));
    }

    /**
     * Finding one offcut in the rack, reading its mark, and getting it to the saw.
     */
    public function offcutDrawMinutes(int $offcutLengthMm): float
    {
        return $this->moveMinutes($this->setting('offcut_draw_base_minutes'), $offcutLengthMm);
    }

    /**
     * Taking one newly bought bar off the lift and getting it to the saw.
     */
    public function barHandlingMinutes(int $barLengthMm): float
    {
        return $this->moveMinutes($this->setting('bar_handling_base_minutes'), $barLengthMm);
    }

    /**
     * The labour one more piece on the rack will cost over its life: marking it, recording it, and moving
     * it about before it is finally used or scrapped.
     */
    public function offcutRackMinutes(int $dropLengthMm): float
    {
        return $this->moveMinutes($this->setting('offcut_rack_base_minutes'), $dropLengthMm);
    }

    /**
     * The share of its value a drop of this length keeps once it is on the rack.
     *
     * Zero below the scrap threshold, because a drop that short is destroyed rather than banked. From
     * there it rises to the cap as the drop approaches a full stock length.
     *
     * The curve is concave (square root) for two reasons. It rises steeply just above the threshold, so a
     * drop banked only because it cleared the line by a millimetre is worth almost nothing - that is what
     * removes the cliff. And because retention rises with length, retained VALUE (length x retention)
     * rises faster than length, so one 2,500mm drop is worth more than a 1,000mm and a 1,500mm one. The
     * old ranking reached for that with a separate "largest drop" tiebreak; here it falls out of the curve.
     *
     * THE CAP IS BOUNDED BY purchase_cost_weight, and not by how valuable a long offcut feels.
     *
     * Retained value is V(L) = cap x L x sqrt((L-t)/(ref-t)), so dV/dL rises to 1.5 x cap at L = ref. If
     * that marginal value reaches purchase_cost_weight, then buying one more millimetre of bar and racking
     * it pays for itself, and the nest starts buying steel in order to bank it: at a cap of 0.98 it bought
     * a 12,000mm bar to put a 2,500mm cut in and racked the other 8,000mm, over two 9,000mm bars that
     * covered the same cuts for 3,000mm less. Keep 1.5 x cap comfortably under purchase_cost_weight - 0.6
     * against 1.0 here.
     */
    public function retention(int $dropLengthMm): float
    {
        if ($dropLengthMm < $this->scrapThresholdMm) {
            return 0.0;
        }

        $span = $this->referenceLengthMm - $this->scrapThresholdMm;
        $position = ($dropLengthMm - $this->scrapThresholdMm) / $span;
        $position = max(0.0, min(1.0, $position));

        return $this->setting('offcut_retention_cap') * sqrt($position);
    }

    /**
     * Whether a drop this long is banked as an offcut rather than destroyed.
     *
     * Has to agree with the per-bar test in Actions\Bar\CreateBarsAndOffcuts, which decides which drops
     * actually become offcut records - scoring a drop as banked that the yard then throws away, or the
     * reverse, would have the nest optimising against something that never happens.
     */
    public function isBanked(int $dropLengthMm): bool
    {
        return $dropLengthMm >= $this->scrapThresholdMm;
    }

    /**
     * What a piece of steel this long is worth sitting on the offcut rack, as a length of itself.
     *
     * Zero for anything below the scrap threshold, because that is destroyed rather than racked. This is
     * the quantity every inventory decision is measured against: consuming a piece gives up its value,
     * banking a drop earns it back, and the difference is what the nest actually cost the yard.
     *
     * Valuing pieces and then differencing them - rather than charging a drop for how short it is -
     * matters most for the stubs. A 2,000mm offcut cut down to 1,500mm has lost very little, because it
     * was worth very little to begin with; a 9,000mm length cut down to 8,500mm has given up far more,
     * even though the same 500mm came off both. Charging the drop for its own shortness gets that
     * backwards and has the nest nibbling its long lengths while the stubs sit there forever.
     */
    public function inventoryValueMm(int $lengthMm): float
    {
        if (! $this->isBanked($lengthMm)) {
            return 0.0;
        }

        return $lengthMm * $this->retention($lengthMm);
    }

    /**
     * The shortest drop of this section that is worth putting on the rack at all.
     *
     * The rule this model exists to apply: keeping a remnant is only worth it while the remnant is worth
     * more than the labour of keeping it. On light angle that lands somewhere past two metres - a shorter
     * piece costs more in marking, recording and shifting than the steel will ever return. On a heavy beam
     * almost anything over the scrap threshold is worth having, because a metre of it is worth far more
     * than the quarter hour it takes to deal with.
     *
     * Null when nothing up to a full stock length clears its own overhead, which means no drop of this
     * section should ever be banked.
     */
    public function worthRackingFromMm(): ?int
    {
        for ($length = $this->scrapThresholdMm; $length <= $this->referenceLengthMm; $length += 10) {
            if ($this->mmToCost($this->inventoryValueMm($length)) > $this->minutesToCost($this->offcutRackMinutes($length))) {
                return $length;
            }
        }

        return null;
    }

    /**
     * What opening this offcut for this cut costs, over and above making the cut at all.
     *
     * Used by the greedy offcut chooser so it ranks the shelf on the same terms the whole nest is ranked
     * on. Null when the cut does not fit.
     *
     * The saw cut itself is not counted: it is made whichever offcut is opened, so it cannot separate
     * them.
     */
    public function openOffcutCost(int $offcutLengthMm, int $cutLengthMm, int $kerfMm): ?float
    {
        $drop = $offcutLengthMm - $cutLengthMm - $kerfMm;

        if ($drop < 0) {
            return null;
        }

        $banked = $this->isBanked($drop);

        /*
         * One piece leaves the rack and, if the drop is long enough to bank, one goes back on. Consuming a
         * stub outright is a credit: that is a mark retired and a lifetime of handling saved.
         */
        $rackLabour = $banked
            ? $this->minutesToCost($this->offcutRackMinutes($drop)) - $this->minutesToCost($this->offcutRackMinutes($offcutLengthMm))
            : -$this->minutesToCost($this->offcutRackMinutes($offcutLengthMm));

        /*
         * Inventory value given up, plus what gets destroyed. A solid drop that goes in the bin is credited
         * back at the scrap rate; the kerf is not, because swarf is not what a merchant weighs in.
         */
        $scrapped = $banked ? 0 : $drop;

        return $this->mmToCost($this->inventoryValueMm($offcutLengthMm) - $this->inventoryValueMm($drop))
            + $this->netScrapCost($scrapped)
            + $this->mmToCost($kerfMm)
            + $this->minutesToCost($this->offcutDrawMinutes($offcutLengthMm))
            + $rackLabour;
    }

    /**
     * The cost of one whole candidate nest, in dollars. Lower is better.
     *
     * @param  int  $purchasedMm  new stock bought
     * @param  int  $scrapMm  solid drops binned, from new bars and from the offcut inventory alike
     * @param  int  $kerfMm  saw kerf, which leaves as swarf and earns nothing back
     * @param  array<int, array{source: int, drop: int}>  $offcutDraws  each offcut taken off the rack,
     *                                                                 with what was left of it
     * @param  array<int, array{length: int, drop: int}>  $barsOpened  each new bar bought, with its drop
     * @param  int  $cuts  saw cuts made
     * @param  int  $unmadeCuts  cuts no bar or offcut could hold
     */
    public function cost(
        int $purchasedMm,
        int $scrapMm,
        int $kerfMm,
        array $offcutDraws,
        array $barsOpened,
        int $cuts,
        int $unmadeCuts,
    ): float {
        //1) Make the pieces. Nothing below can buy its way past a cut the workshop does not get
        $cost = $unmadeCuts * self::UNMADE_CUT_PENALTY;

        /*
         * 2) Steel bought. The only line the business actually pays money out on, and what keeps the nest
         *    from buying its way out of cutting into inventory.
         */
        $cost += $this->setting('purchase_cost_weight') * $this->mmToCost($purchasedMm);

        /*
         * 3) Steel destroyed, wherever it came from, and never discounted by what the piece it came off was
         *    carried at: a millimetre in the skip is the same millimetre whether it came off a bar just
         *    bought or a stub that had sat on the rack for two years. Discounting it by its carried value is
         *    what made destroying a low-valued stub look almost free.
         *
         *    A solid drop is credited back at the scrap rate, because the bin pays: binning is a real loss
         *    but only of about seven eighths of the steel, which makes scrap a legitimately cheap way out of
         *    a remnant that is not worth keeping. Saw kerf gets nothing - it leaves as swarf mixed with
         *    coolant and whatever else was cut that day, not as something a merchant weighs in.
         */
        $cost += $this->netScrapCost($scrapMm);
        $cost += $this->mmToCost($kerfMm);

        /*
         * 4) Inventory value given up, netted against what went back. Each offcut drawn gives up its own
         *    value and earns back whatever its drop is worth; each drop off a new bar is value added.
         *
         *    This is the term the old ranking was missing entirely, and it is a DIFFERENCE rather than a
         *    charge on the drop alone - see inventoryValueMm().
         *
         *    An offcut consumed down to nothing is charged twice over on purpose: once for the steel that
         *    went in the skip, at (3) above, and once for the piece of inventory that no longer exists.
         *    Those are two separate losses - the metal, and the option of having had a piece that length
         *    on the rack - and a stub is carried at so little that charging only its carried value would
         *    make destroying one very nearly free.
         */
        foreach ($offcutDraws as $draw) {
            $cost += $this->mmToCost(
                $this->inventoryValueMm((int) $draw['source']) - $this->inventoryValueMm((int) $draw['drop']),
            );
        }

        foreach ($barsOpened as $bar) {
            $cost -= $this->mmToCost($this->inventoryValueMm((int) $bar['drop']));
        }

        /*
         * 5) The labour a racked offcut costs over its life, on the NET change to the rack. Banking a drop
         *    off an offcut is close to free - one piece replaced another, and only the change in how heavy
         *    it is to shift counts. Consuming a stub outright is a credit: a mark retired for good. A drop
         *    off a new bar is one more piece to store, find, verify and move on every future job.
         *
         *    Weighed against what the drop is worth at (4), this is the rule the whole model turns on: a
         *    remnant is worth keeping only while it is worth more than the labour of keeping it. See
         *    worthRackingFromMm().
         */
        foreach ($offcutDraws as $draw) {
            $cost -= $this->minutesToCost($this->offcutRackMinutes((int) $draw['source']));

            if ($this->isBanked((int) $draw['drop'])) {
                $cost += $this->minutesToCost($this->offcutRackMinutes((int) $draw['drop']));
            }
        }

        foreach ($barsOpened as $bar) {
            if ($this->isBanked((int) $bar['drop'])) {
                $cost += $this->minutesToCost($this->offcutRackMinutes((int) $bar['drop']));
            }
        }

        /*
         * 6) The labour of the job itself: fetching each offcut out of the rack, taking each bar off the
         *    lift, and every cut on the saw. All three scale with the section - a 500UB takes a crane to
         *    move and the better part of seven minutes to cut through.
         */
        foreach ($offcutDraws as $draw) {
            $cost += $this->minutesToCost($this->offcutDrawMinutes((int) $draw['source']));
        }

        foreach ($barsOpened as $bar) {
            $cost += $this->minutesToCost($this->barHandlingMinutes((int) $bar['length']));
        }

        $cost += $cuts * $this->minutesToCost($this->cutMinutes());

        return $cost;
    }
}
