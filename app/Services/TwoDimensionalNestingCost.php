<?php

namespace App\Services;

/**
 * What one candidate 2D nest costs, in dollars. The plate equivalent of Services\NestingCostModel.
 *
 * The 1D model's whole argument transplanted into two dimensions, because the argument is the same one:
 * a nest cannot be ranked on area saved any more than it could be ranked on millimetres saved. Area is
 * not comparable across thicknesses, it is not comparable to time, and - the point of the exercise - a
 * remnant that is "big" is not necessarily a remnant that is worth keeping.
 *
 * So everything here is money, exactly as it is in the 1D model:
 *
 *   - MATERIAL converts through the plate's mass per square metre and the steel price
 *   - LABOUR converts through a duration and an hourly rate
 *   - A REMNANT is worth a share of what it would cost to buy, and that share is what the nest weighs
 *     the labour of keeping it against
 *
 * WHAT IS GENUINELY DIFFERENT IN TWO DIMENSIONS, and the reason this is a separate class rather than a
 * second constructor on NestingCostModel:
 *
 *  1) A remnant needs TWO numbers to describe it, so "worthwhile" is no longer one threshold. A 2,400 x 40
 *     sliver has more area than a 300 x 300 square and is worth nothing, because nothing can be cut out of
 *     40mm. isBanked() therefore tests the short side, the long side and the area, and all three have to
 *     pass - see the note there.
 *
 *  2) Shape survives into value. Two remnants of identical area are not worth the same: the square one
 *     holds a part the strip cannot. retention() carries a squareness term for this, which has no 1D
 *     counterpart at all and is the most arguable number in the file.
 *
 *  3) One opened piece can produce SEVERAL remnants. A bar has one drop; a sheet has as many leftover
 *     rectangles as the cut plan happens to leave, each judged on its own merits.
 *
 * THIS IS A PROOF OF CONCEPT AND READS NO DATABASE COLUMN. There is no businesses.plate_kerf_mm and no
 * migration adding one - every coefficient is handed in, and DEFAULTS is what a caller gets for anything
 * it does not mention. That is deliberate: the question being prototyped is whether the 1D philosophy
 * carries into 2D at all, and settling it does not need a settings screen first.
 */
class TwoDimensionalNestingCost
{
    /**
     * A part nothing can hold is not a cheaper nest, it is a part the workshop does not get. Large enough
     * that no amount of material or labour can buy its way past one.
     *
     * The same figure and the same reasoning as NestingCostModel::UNMADE_CUT_PENALTY.
     */
    public const float UNMADE_PART_PENALTY = 1_000_000_000.0;

    /**
     * Fallbacks for every coefficient.
     *
     * The ones that have a 1D counterpart carry the 1D default, so a yard reading both pages is not
     * looking at two different steel prices. The ones that do not - the cutting rate, sheet handling -
     * are a plasma table and a crane, and are a starting point rather than a measurement of any
     * particular shop.
     *
     * As in the 1D model, a coefficient left at zero does not make this cautious, it makes it silent:
     * the cost collapses to "area not cut into a part", which is the same number for every candidate
     * plan, so the search stops discriminating and returns whichever plan it built first.
     *
     * @var array<string, float>
     */
    public const array DEFAULTS = [
        //Shared with the 1D model, same meaning, same figure
        'labour_rate_per_hour' => 50.00,
        'material_cost_per_tonne' => 2000.00,
        'delivery_cost_per_tonne' => 0.00,
        'delivery_cost_per_order' => 0.00,
        'scrap_recovery_rate' => 0.13,
        'order_admin_minutes' => 15.00,
        'offcut_retention_cap' => 0.60,
        'purchase_cost_weight' => 1.00,
        'move_minutes_per_tonne' => 15.00,

        /*
         * Cutting. Charged per metre of cut line rather than per cut, which is the one labour figure that
         * genuinely does not carry over from 1D: a saw cut through a bar is a cut whatever the bar, but a
         * profile cut around a plate part is paid for by the metre of travel.
         */
        'cut_base_minutes' => 0.50,
        'cut_minutes_per_metre' => 0.50,

        //Getting a sheet onto the table, and a remnant back out of the rack to it
        'sheet_handling_base_minutes' => 6.00,
        'offcut_draw_base_minutes' => 5.00,
        'receive_base_minutes' => 6.00,

        /*
         * The whole life of a racked remnant - marking it, recording it, and every time it is shifted
         * before it is finally used. Higher than the 1D figure of 6: a sheet remnant is awkward in a way
         * a length of section is not. It does not stack, it has to be stood in a rack or laid flat taking
         * floor space, and finding the right one means walking the rack rather than reading an end.
         */
        'offcut_rack_base_minutes' => 8.00,
    ];

    /**
     * @param  array<string, float>  $settings  overrides for DEFAULTS
     * @param  float  $kgPerM2  the plate's mass, which is what turns area into money
     * @param  int  $minSideMm  no remnant narrower than this is worth keeping
     * @param  int  $minAreaMm2  nor one smaller than this, however square
     * @param  int  $referenceAreaMm2  a full sheet, which is what retention is measured against
     */
    public function __construct(
        private readonly array $settings,
        private readonly float $kgPerM2,
        private readonly int $minSideMm,
        private readonly int $minAreaMm2,
        private readonly int $referenceAreaMm2,
    ) {}

    /**
     * One coefficient, with its documented fallback.
     *
     * A missing key reads as the default rather than as zero, for the reason given on DEFAULTS. A zero a
     * caller has actually passed stays a zero - the key is present then.
     */
    private function setting(string $key): float
    {
        return (float) ($this->settings[$key] ?? self::DEFAULTS[$key] ?? 0.0);
    }

    /**
     * Money
     */
    public function mm2ToKg(float|int $mm2): float
    {
        //mm2 -> m2 -> kg
        return ($mm2 / 1_000_000) * $this->kgPerM2;
    }

    /**
     * Bare steel plus the freight to get it here.
     *
     * Freight is MATERIAL, as it is in the 1D model: valuation, purchase and destruction are all priced
     * through the landed figure, which is what keeps the retention cap safe - see retention().
     */
    public function landedCostPerTonne(): float
    {
        return $this->setting('material_cost_per_tonne') + $this->setting('delivery_cost_per_tonne');
    }

    public function mm2ToCost(float|int $mm2): float
    {
        return $this->mm2ToKg($mm2) / 1000 * $this->landedCostPerTonne();
    }

    /** What the bin pays back for solid plate. Kerf gets nothing - it leaves as dust and dross. */
    public function scrapIncome(float|int $mm2): float
    {
        return $this->mm2ToKg($mm2) / 1000 * $this->setting('material_cost_per_tonne') * $this->setting('scrap_recovery_rate');
    }

    public function netScrapCost(float|int $mm2): float
    {
        return $this->mm2ToCost($mm2) - $this->scrapIncome($mm2);
    }

    public function minutesToCost(float $minutes): float
    {
        return $minutes / 60 * $this->setting('labour_rate_per_hour');
    }

    /**
     * Time
     */
    public function cutMinutes(float $cutLengthMm): float
    {
        return $this->setting('cut_base_minutes') + ($cutLengthMm / 1000) * $this->setting('cut_minutes_per_metre');
    }

    /**
     * Shifting a piece of plate scales with its mass, the same way moving a section does. A 1,200 x 600
     * offcut of 6mm plate is carried; a full 3,000 x 1,500 sheet of 20mm is a crane and two people.
     */
    private function moveMinutes(float $baseMinutes, int $areaMm2): float
    {
        return $baseMinutes + $this->mm2ToKg($areaMm2) / 1000 * $this->setting('move_minutes_per_tonne');
    }

    public function sheetHandlingMinutes(int $areaMm2): float
    {
        return $this->moveMinutes($this->setting('sheet_handling_base_minutes'), $areaMm2);
    }

    public function offcutDrawMinutes(int $areaMm2): float
    {
        return $this->moveMinutes($this->setting('offcut_draw_base_minutes'), $areaMm2);
    }

    public function offcutRackMinutes(int $areaMm2): float
    {
        return $this->moveMinutes($this->setting('offcut_rack_base_minutes'), $areaMm2);
    }

    public function receiveMinutes(int $areaMm2): float
    {
        return $this->moveMinutes($this->setting('receive_base_minutes'), $areaMm2);
    }

    public function orderOverheadCost(): float
    {
        return $this->setting('delivery_cost_per_order')
            + $this->minutesToCost($this->setting('order_admin_minutes'));
    }

    /**
     * Whether a remnant this size is marked and racked, or binned.
     *
     * THE ONE PLACE THIS MODEL REALLY DEPARTS FROM THE 1D ONE. A bar's drop is worthwhile or not on a
     * single number, so a single threshold answers it. A rectangle needs three tests, and all three have
     * to pass:
     *
     *   - the SHORT side clears the minimum, because the short side is what caps the biggest part that
     *     can ever come out of the piece. A 2,400 x 40 strip has 96,000mm2 of perfectly good steel in it
     *     and nothing a fabricator will ever cut from it
     *   - the LONG side clears it too, which is the same test said the other way and costs nothing to
     *     state. A piece has to be usable in both directions to be a piece
     *   - the AREA clears its own minimum, which retires the remnants that pass on shape and are simply
     *     too small to bother marking, recording and walking the rack for
     *
     * Area alone was the first thing tried and it is wrong in the worst direction: it banks slivers, so
     * the rack fills with steel that will be thrown out in a year having been walked past a hundred times.
     * That is the 2D version of the stub problem the 1D retention curve exists to solve, and it is worse,
     * because a 1D stub at least has a usable length.
     *
     * Anything deciding which remnants become records has to apply exactly this test. In the 1D
     * application that rule lives in Actions\Bar\CreateBarsAndOffcuts, and scoring a remnant as banked
     * that the yard then throws away would have the nest optimising against something that never happens.
     */
    public function isBanked(int $widthMm, int $heightMm): bool
    {
        $short = min($widthMm, $heightMm);
        $long = max($widthMm, $heightMm);

        return $short >= $this->minSideMm
            && $long >= $this->minSideMm
            && ($widthMm * $heightMm) >= $this->minAreaMm2;
    }

    /**
     * The share of its value a remnant this size keeps once it is on the rack.
     *
     * Zero for anything that is not banked, because that is destroyed rather than racked. From there it
     * rises towards the cap as the remnant approaches a full sheet, through two terms:
     *
     * AREA, under a square root, for exactly the 1D reason: it rises steeply just above the threshold, so
     * a remnant banked only because it cleared the line by a millimetre is worth almost nothing. That is
     * what stops the search deliberately producing the least useful bankable piece it can - with a
     * thousand iterations it WILL find that arrangement if the threshold is a cliff.
     *
     * SQUARENESS, which has no 1D counterpart and is the whole reason this is not just the 1D curve with
     * area substituted for length. Two remnants of 720,000mm2 - one 1,200 x 600, one 2,400 x 300 - are not
     * worth the same. The first holds a 600mm square part; the second holds nothing over 300 in one
     * direction, and every job it ever serves will be a strip. Under a square root again, so the penalty
     * is real but not punitive: that 2,400 x 300 keeps about 35% of what the square would, not 12%.
     *
     * THE CAP IS BOUNDED BY purchase_cost_weight for the 1D reason, and the 1D note on retention() is the
     * place that argument is made properly. Short version: retained value rises faster than area, so if
     * its gradient reaches the cost of buying, the nest starts buying plate in order to rack it. 0.6
     * against 1.0 leaves room.
     *
     * THE SQUARENESS EXPONENT IS THE NUMBER IN THIS FILE MOST IN NEED OF A FABRICATOR'S OPINION. It is
     * the difference between a rack of useful plate and a rack of strips, and nothing here measured it.
     */
    public function retention(int $widthMm, int $heightMm): float
    {
        if (! $this->isBanked($widthMm, $heightMm)) {
            return 0.0;
        }

        $area = $widthMm * $heightMm;

        $span = max(1, $this->referenceAreaMm2 - $this->minAreaMm2);
        $position = max(0.0, min(1.0, ($area - $this->minAreaMm2) / $span));

        $squareness = min($widthMm, $heightMm) / max(1, max($widthMm, $heightMm));

        return $this->setting('offcut_retention_cap') * sqrt($position) * sqrt($squareness);
    }

    /**
     * What a remnant this size is worth sitting on the rack, as an area of fresh plate.
     *
     * Zero for anything not banked. This is the quantity every inventory decision is measured against:
     * drawing a piece gives up its value, banking a remnant earns it back, and the difference is what the
     * nest actually cost the yard.
     *
     * Valuing pieces and differencing them - rather than charging a remnant for how small it is - matters
     * most at the bottom of the rack, and the 1D note on inventoryValueMm() makes the case. A 600 x 600
     * cut down to 600 x 500 has lost very little because it was worth very little; a full sheet cut down
     * to three quarters has given up far more.
     */
    public function inventoryValueMm2(int $widthMm, int $heightMm): float
    {
        return $widthMm * $heightMm * $this->retention($widthMm, $heightMm);
    }

    /**
     * What opening this piece for this part looks like it will cost, before the rest of the plan is known.
     *
     * The 2D counterpart of NestingCostModel::openOffcutCost(), and carrying the same caveat: it is a
     * LOCAL estimate used to order the candidates, not the decision. The decision is cost() over a
     * finished plan, and the search tries many - see TwoDimensionalNesting, which is where this gets
     * called and where the real ranking happens.
     *
     * A rack piece is already paid for, so opening it costs the inventory value it gives up plus the
     * labour of fetching it. A bought sheet costs money.
     *
     * @param  array{width: int, height: int}  $piece
     * @param  array{width: int, height: int}  $part
     */
    public function openCost(array $piece, array $part, bool $fromRack): float
    {
        $area = $piece['width'] * $piece['height'];

        if (! $fromRack) {
            return $this->setting('purchase_cost_weight') * $this->mm2ToCost($area)
                + $this->minutesToCost($this->sheetHandlingMinutes($area))
                + $this->minutesToCost($this->receiveMinutes($area));
        }

        /*
         * The best case for what survives: the part taken out of one corner, leaving the larger of the two
         * rectangles a single guillotine cut would leave. An estimate, and knowingly optimistic - the real
         * plan will put more parts in and leave less - but it is the same optimism for every rack
         * candidate, which is all an ordering needs.
         */
        $survivor = max(
            $this->inventoryValueMm2($piece['width'] - $part['width'], $piece['height']),
            $this->inventoryValueMm2($piece['width'], $piece['height'] - $part['height']),
        );

        return $this->mm2ToCost($this->inventoryValueMm2($piece['width'], $piece['height']) - $survivor)
            + $this->minutesToCost($this->offcutDrawMinutes($area));
    }

    /**
     * What a finished plan costs.
     *
     * Term for term the 1D model's cost(), which is the point - if the philosophy carries into 2D, it
     * carries here or nowhere. The 1D method's comments make each argument at length; these are the
     * same arguments in the same order.
     *
     * @param  array<int, array{width: int, height: int, fromRack: bool, banked: array<int, array{width: int, height: int}>}>  $pieces
     *                                                                                                                                 every sheet bought and every remnant drawn, with what each one put back on the rack
     * @param  int  $scrapMm2  solid plate destroyed, wherever it came from
     * @param  int  $kerfMm2  area turned into dust by the torch
     * @param  float  $cutLengthMm  total cut line travelled
     * @param  int  $unmadeParts  parts nothing in the plan could hold
     */
    public function cost(
        array $pieces,
        int $scrapMm2,
        int $kerfMm2,
        float $cutLengthMm,
        int $unmadeParts,
    ): float {
        //1) Make the parts. Nothing below can buy its way past a part the workshop does not get
        $cost = $unmadeParts * self::UNMADE_PART_PENALTY;

        $purchasedMm2 = 0;

        foreach ($pieces as $piece) {
            $area = $piece['width'] * $piece['height'];

            /*
             * 2) Plate bought, at its landed price. The only line the business actually pays money out on,
             *    and what keeps the nest from buying its way out of cutting into inventory.
             */
            if (! $piece['fromRack']) {
                $purchasedMm2 += $area;
                $cost += $this->setting('purchase_cost_weight') * $this->mm2ToCost($area);
            }

            /*
             * 4) Inventory value given up, netted against what went back.
             *
             *    A drawn remnant gives up its own value and earns back whatever it leaves behind; a bought
             *    sheet gives up nothing and earns back everything it racks. In 2D the "earns back" side is
             *    a sum rather than a single drop, which is the whole of the difference.
             *
             *    A remnant consumed down to nothing is charged twice over on purpose - once for the steel
             *    in the skip at (3) below, and once for the piece of inventory that no longer exists.
             *    Those are two separate losses: the metal, and the option of having had a piece that size
             *    on the rack.
             */
            if ($piece['fromRack']) {
                $cost += $this->mm2ToCost($this->inventoryValueMm2($piece['width'], $piece['height']));

                //5) And the rack labour it stops costing. A mark retired is a piece nobody walks past again
                $cost -= $this->minutesToCost($this->offcutRackMinutes($area));
            }

            foreach ($piece['banked'] as $banked) {
                $bankedArea = $banked['width'] * $banked['height'];

                $cost -= $this->mm2ToCost($this->inventoryValueMm2($banked['width'], $banked['height']));

                /*
                 * 5) The labour a racked remnant costs over its life. Weighed against what it is worth
                 *    just above, this is the rule the whole model turns on: a remnant is worth keeping
                 *    only while it is worth more than the labour of keeping it.
                 */
                $cost += $this->minutesToCost($this->offcutRackMinutes($bankedArea));
            }

            /*
             * 6) The labour of the job itself. A bought sheet is charged twice and the two are different
             *    jobs: receiving it off the truck against the docket and into the rack, and then getting
             *    it onto the table. Receiving is what a piece already in the yard has had done to it and
             *    never pays again.
             */
            $cost += $this->minutesToCost(
                $piece['fromRack']
                    ? $this->offcutDrawMinutes($area)
                    : $this->sheetHandlingMinutes($area) + $this->receiveMinutes($area),
            );
        }

        /*
         * 2a) The fixed cost of buying at all: the paperwork for an order, and the truck turning up with
         *     whatever is on it. Charged once, on whether this plan buys - not on how much. What it
         *     separates is buying from NOT buying, which is the comparison it exists for.
         */
        if ($purchasedMm2 > 0) {
            $cost += $this->orderOverheadCost();
        }

        /*
         * 3) Plate destroyed, wherever it came from, and never discounted by what the piece it came off
         *    was carried at. Solid offcut is credited back at the scrap rate because the bin pays; kerf
         *    gets nothing, because it leaves as dust.
         */
        $cost += $this->netScrapCost($scrapMm2);
        $cost += $this->mm2ToCost($kerfMm2);

        //6, continued) Every metre the torch travelled
        $cost += $this->minutesToCost($this->cutMinutes($cutLengthMm));

        return $cost;
    }
}
