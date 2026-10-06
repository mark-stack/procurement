<?php

namespace App\Services;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Nesting plate, with the same philosophy as the bar nesting: what is left over is marked and racked if
 * it is a worthwhile piece, and binned if it is not.
 *
 * A PROOF OF CONCEPT. It writes nothing, reads no database, and is reachable only from the admin "2D
 * Nesting" page. It exists to settle one question before anything is built on it - does the argument the
 * 1D nesting is built on survive the second dimension - and the answer it gives is "mostly, and here is
 * exactly where it does not".
 *
 * WHAT CARRIES OVER UNCHANGED
 *
 *   - A whole plan is built end to end and scored in dollars, and many plans are built and the cheapest
 *     kept. The decision is never a greedy rule; the greedy rule only builds the first candidate. This is
 *     NestingFormatter::meterageAlgorithm()'s structure exactly.
 *   - The rack is drawn on and bought from in the SAME plan. The 1D nesting learned this the hard way:
 *     running the offcut pass first, outside the search, spent shelf stock on cuts the bar being bought
 *     anyway had room for. A 2D version of that mistake is worse, because a sheet has more room to waste.
 *   - A remnant is worth a share of fresh plate, that share is what the labour of keeping it is weighed
 *     against, and the share curve is concave so that a remnant which only just qualifies is worth almost
 *     nothing. See TwoDimensionalNestingCost::retention().
 *
 * WHAT DOES NOT, AND IS THE POINT OF THE EXERCISE
 *
 *   - "Worthwhile" needs three tests rather than one threshold, because area alone banks slivers. See
 *     TwoDimensionalNestingCost::isBanked().
 *   - A bar has one drop. A sheet has as many remnants as the cut plan leaves, each judged separately,
 *     so one opened piece can put several marks on the rack or none.
 *   - The cuts are GUILLOTINE cuts - edge to edge of whatever is being cut, every time. This is a
 *     deliberate restriction and the reason the whole thing hangs together: a guillotine cut leaves
 *     rectangles, and only a rectangle can be marked, measured, racked and found again. True free-form
 *     nesting packs parts tighter and leaves an unholdable shape behind, which is a better answer to
 *     "how little plate do I buy today" and no answer at all to "what is on the rack". The 1D nesting
 *     never had to make this choice, because every remnant of a bar is a bar.
 *
 * ALGORITHM, per candidate plan:
 *
 *   A) Parts sorted biggest-area first, so the awkward ones get first claim (1D sorts longest first)
 *   B) Each part goes in the best free rectangle across every piece already open - "best" being the
 *      tightest fit, in either orientation unless the part is grain-locked
 *   C) When nothing open holds it, a piece is opened: a remnant off the rack, or a sheet bought. Each
 *      option is priced by TwoDimensionalNestingCost::openCost() and the cheapest taken
 *   D) Placing a part splits its free rectangle in two with one guillotine cut, and the split direction
 *      is chosen on which pair of rectangles is worth more on the rack - so the offcut philosophy is
 *      applied at every cut, not just at the end
 *   E) Leftover rectangles separated only by a kerf are merged back together, reclaiming the kerf. A cut
 *      between two pieces of waste is a cut nobody makes
 *   F) Each surviving rectangle is banked or binned on the three-part test
 *
 * Every candidate is scored by TwoDimensionalNestingCost::cost() and the cheapest is returned.
 */
class TwoDimensionalNesting
{
    /**
     * What a plan is run under when a caller does not say.
     *
     * No database column backs any of these - see the note on TwoDimensionalNestingCost. The two
     * thresholds are the 2D replacement for businesses.scrap_threshold_mm and are the numbers a real
     * yard would argue about first: 250mm because nothing useful comes out of a narrower strip, and
     * 0.16m2 (a 400 x 400 square) because below that the marking, recording and walking costs more than
     * the steel is worth.
     *
     * @var array<string, int>
     */
    public const array DEFAULTS = [
        'kerf_mm' => 4,
        'min_offcut_side_mm' => 250,
        'min_offcut_area_mm2' => 160_000,
        'iterations' => 150,
    ];

    /**
     * Nest one set of parts.
     *
     * @param  array<int, array{width: int, height: int, letter?: string, piece_id?: int, rotatable?: bool}>  $parts
     *                                                                                                               one entry per physical part, already expanded out of any quantity
     * @param  array<int, array{width: int, height: int}>  $stockSheets  what can be bought, in unlimited quantity
     * @param  array<int, array{id: int, unique_mark: string, width: int, height: int, generation?: int}>  $rack
     *                                                                                                           what is on the offcut rack, each of which can be drawn once
     * @param  array<string, mixed>  $settings  overrides for DEFAULTS and TwoDimensionalNestingCost::DEFAULTS
     * @return array<string, mixed>
     */
    public function nest(array $parts, array $stockSheets, array $rack, array $settings = []): array
    {
        $kerf = max(0, (int) ($settings['kerf_mm'] ?? self::DEFAULTS['kerf_mm']));
        $iterations = max(0, (int) ($settings['iterations'] ?? self::DEFAULTS['iterations']));

        $cost = $this->costModel($stockSheets, $settings);

        //A) Parts biggest first. This is the order every candidate is built in
        $ordered = $this->sortParts($parts);

        //Stock sizes in a fixed order, so a plan cannot depend on how the caller happened to list them
        $stockSheets = $this->sortSheets($stockSheets);

        //And the rack, for the same reason - see NestingFormatter::sortOffcutInventory
        $rack = $this->sortRack($rack);

        /*
         * Deterministic randomness, exactly as the 1D nesting does it. The same inputs must always produce
         * the same plan, or the nest somebody approves on screen is not the nest that gets cut.
         */
        $randomizer = new Randomizer(new Mt19937($this->seed($ordered, $stockSheets, $rack, $settings)));

        /*
         * C) Candidates. The greedy one goes first, so an unbeaten search falls back to it.
         */
        $best = $this->buildPlan($ordered, $stockSheets, $rack, $cost, $kerf, null, null);
        $tried = 1;

        /*
         * One candidate per buyable sheet size, every purchase forced to that size.
         *
         * The 1D nesting needs this because its random search draws each bar's length independently and so
         * reliably misses the textbook "all one length" answer. The same hole is here and is deeper: with
         * three sheet sizes and twelve opens there are 531,441 mixes and the search sees a hundred of them.
         */
        foreach ($stockSheets as $sheet) {
            $candidate = $this->buildPlan($ordered, $stockSheets, $rack, $cost, $kerf, null, $sheet);
            $tried++;

            if ($candidate['cost'] < $best['cost']) {
                $best = $candidate;
            }
        }

        /*
         * And the random ones, which vary the part order, the split direction and which piece gets opened.
         * Varying only one of the three is what made the 1D iteration count buy nothing for a while.
         */
        for ($i = 0; $i < $iterations; $i++) {
            $candidate = $this->buildPlan($ordered, $stockSheets, $rack, $cost, $kerf, $randomizer, null);
            $tried++;

            if ($candidate['cost'] < $best['cost']) {
                $best = $candidate;
            }
        }

        $best['candidatesTried'] = $tried;
        $best['settings'] = [
            'kerfMm' => $kerf,
            'minOffcutSideMm' => (int) ($settings['min_offcut_side_mm'] ?? self::DEFAULTS['min_offcut_side_mm']),
            'minOffcutAreaMm2' => (int) ($settings['min_offcut_area_mm2'] ?? self::DEFAULTS['min_offcut_area_mm2']),
            'iterations' => $iterations,
        ];

        return $best;
    }

    /**
     * The cost model every candidate is ranked by.
     *
     * Built once and shared, so they are all ranked on the same terms - and so the open chooser inside
     * buildPlan() values the rack the same way the finished plan is valued.
     *
     * @param  array<int, array{width: int, height: int}>  $stockSheets
     * @param  array<string, mixed>  $settings
     */
    private function costModel(array $stockSheets, array $settings): TwoDimensionalNestingCost
    {
        //A full sheet is what a remnant's value is measured against. The largest, where several are buyable
        $reference = 0;
        foreach ($stockSheets as $sheet) {
            $reference = max($reference, $sheet['width'] * $sheet['height']);
        }

        return new TwoDimensionalNestingCost(
            settings: $settings,
            kgPerM2: (float) ($settings['kg_per_m2'] ?? 47.1),
            minSideMm: (int) ($settings['min_offcut_side_mm'] ?? self::DEFAULTS['min_offcut_side_mm']),
            minAreaMm2: (int) ($settings['min_offcut_area_mm2'] ?? self::DEFAULTS['min_offcut_area_mm2']),
            referenceAreaMm2: max(1, $reference),
        );
    }

    /**
     * One complete plan: every part placed, every piece opened, everything left over judged.
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @param  array<int, array{width: int, height: int}>  $stockSheets
     * @param  array<int, array<string, mixed>>  $rack
     * @param  array{width: int, height: int}|null  $forcedSheet  when set, the only size this plan may buy
     * @return array<string, mixed>
     */
    private function buildPlan(
        array $parts,
        array $stockSheets,
        array $rack,
        TwoDimensionalNestingCost $cost,
        int $kerf,
        ?Randomizer $randomizer,
        ?array $forcedSheet,
    ): array {
        if ($randomizer instanceof Randomizer) {
            $parts = $this->jumble($parts, $randomizer);
        }

        $buyable = $forcedSheet === null ? $stockSheets : [$forcedSheet];

        $pieces = [];
        $available = $rack;
        $unmade = [];

        foreach ($parts as $part) {
            $slot = $this->bestSlot($pieces, $part, $kerf);

            if ($slot === null) {
                [$piece, $available] = $this->openPiece($part, $available, $buyable, $cost, $kerf, $randomizer);

                /*
                 * Nothing on the rack and nothing buyable holds this part. Recorded rather than dropped -
                 * the cost model charges heavily for it, which is what stops a plan looking cheap because
                 * it quietly left a part out. See TwoDimensionalNestingCost::UNMADE_PART_PENALTY.
                 */
                if ($piece === null) {
                    $unmade[] = $part;

                    continue;
                }

                $pieces[] = $piece;
                $slot = $this->bestSlot([array_key_last($pieces) => $piece], $part, $kerf);

                //Opened for this part and then unable to hold it would be a bug in fitsOnAxis, not an input
                if ($slot === null) {
                    $unmade[] = $part;
                    array_pop($pieces);

                    continue;
                }
            }

            $this->placeInto($pieces[$slot['pieceIndex']], $part, $slot, $kerf, $cost, $randomizer);
        }

        return $this->finalise($pieces, $unmade, $cost, $kerf);
    }

    /**
     * The best free rectangle anywhere for this part, or null if nothing open holds it.
     *
     * BEST AREA FIT: the rectangle that is left with the least to spare, tie-broken on the shorter of the
     * two leftover edges. The 1D nesting packs into "the shortest bar that holds it" for the same reason -
     * put a part in the tightest place that takes it, and the roomy places stay roomy.
     *
     * Both orientations are tried unless the part is grain-locked. Plate is usually isotropic, so rotating
     * is free; checker plate, a directional finish or a part that has to run with the roll direction is
     * not, and rotatable: false says so.
     *
     * @param  array<int, array<string, mixed>>  $pieces
     * @param  array<string, mixed>  $part
     * @return array{pieceIndex: int, freeIndex: int, rotated: bool}|null
     */
    private function bestSlot(array $pieces, array $part, int $kerf): ?array
    {
        $best = null;
        $bestScore = null;

        $orientations = [[$part['width'], $part['height'], false]];

        if (($part['rotatable'] ?? true) && $part['width'] !== $part['height']) {
            $orientations[] = [$part['height'], $part['width'], true];
        }

        foreach ($pieces as $pieceIndex => $piece) {
            foreach ($piece['free'] as $freeIndex => $free) {
                foreach ($orientations as [$width, $height, $rotated]) {
                    if (! $this->fits($width, $height, $free['width'], $free['height'], $kerf)) {
                        continue;
                    }

                    /*
                     * Smallest waste first, then the tightest single edge. Compared as a pair rather than
                     * as a combined number, because adding an area to a length compares nothing.
                     */
                    $score = [
                        $free['width'] * $free['height'] - $width * $height,
                        min($free['width'] - $width, $free['height'] - $height),
                    ];

                    if ($bestScore === null || $score < $bestScore) {
                        $bestScore = $score;
                        $best = ['pieceIndex' => $pieceIndex, 'freeIndex' => $freeIndex, 'rotated' => $rotated];
                    }
                }
            }
        }

        return $best;
    }

    /**
     * Open a sheet or draw a remnant for a part nothing already open can hold.
     *
     * Every option that fits is priced by TwoDimensionalNestingCost::openCost() and the cheapest is taken -
     * which is NOT "the rack first". A remnant that would be destroyed to make one small part is more
     * expensive than the sheet that would have had room for it anyway, and the model says so. That is the
     * 1D "two on the rack, and the right one opens" behaviour, in two dimensions.
     *
     * A random candidate picks from the cheapest few instead of the cheapest, which is what lets the search
     * find the plans where a locally expensive open pays off later.
     *
     * @param  array<string, mixed>  $part
     * @param  array<int, array<string, mixed>>  $available  the rack, less whatever has already been drawn
     * @param  array<int, array{width: int, height: int}>  $buyable
     * @return array{0: array<string, mixed>|null, 1: array<int, array<string, mixed>>}
     */
    private function openPiece(
        array $part,
        array $available,
        array $buyable,
        TwoDimensionalNestingCost $cost,
        int $kerf,
        ?Randomizer $randomizer,
    ): array {
        $options = [];

        foreach ($available as $index => $offcut) {
            if (! $this->holds($part, $offcut, $kerf)) {
                continue;
            }

            $options[] = [
                'cost' => $cost->openCost($offcut, $part, fromRack: true),
                'rackIndex' => $index,
                'piece' => $this->newPiece(
                    $offcut['width'],
                    $offcut['height'],
                    fromRack: true,
                    mark: $offcut['unique_mark'] ?? null,
                    rackId: $offcut['id'] ?? null,
                    generation: (int) ($offcut['generation'] ?? 1),
                ),
            ];
        }

        foreach ($buyable as $sheet) {
            if (! $this->holds($part, $sheet, $kerf)) {
                continue;
            }

            $options[] = [
                'cost' => $cost->openCost($sheet, $part, fromRack: false),
                'rackIndex' => null,
                'piece' => $this->newPiece($sheet['width'], $sheet['height'], fromRack: false),
            ];
        }

        if ($options === []) {
            return [null, $available];
        }

        usort($options, fn (array $a, array $b) => $a['cost'] <=> $b['cost']);

        $pick = 0;

        if ($randomizer instanceof Randomizer && count($options) > 1) {
            //The cheapest three at most, so a random plan explores without going out of its mind
            $pick = $randomizer->getInt(0, min(2, count($options) - 1));
        }

        $chosen = $options[$pick];

        if ($chosen['rackIndex'] !== null) {
            //A physical piece, drawn once
            unset($available[$chosen['rackIndex']]);
        }

        return [$chosen['piece'], $available];
    }

    /**
     * Put the part in, and cut the rectangle it went into in two.
     *
     * ONE GUILLOTINE CUT IN EACH DIRECTION, and the order they are made in decides what is left. Cutting
     * across the full width first leaves a full-width strip above and a stub beside the part; cutting down
     * the full height first leaves a full-height strip beside and a stub above. Same part, same material,
     * two quite different racks.
     *
     * The choice is made on what the two rectangles would be WORTH, through the same retention curve the
     * finished plan is scored by - so "keep one usable piece rather than two stubs" is applied at every
     * cut rather than only at the end. It is a local judgement: these rectangles may be cut into again,
     * and a rectangle worth a lot now may be worth nothing by the time the plan finishes. That is what
     * the search over many candidates is for.
     *
     * @param  array<string, mixed>  $piece
     * @param  array<string, mixed>  $part
     * @param  array{pieceIndex: int, freeIndex: int, rotated: bool}  $slot
     */
    private function placeInto(
        array &$piece,
        array $part,
        array $slot,
        int $kerf,
        TwoDimensionalNestingCost $cost,
        ?Randomizer $randomizer,
    ): void {
        $free = $piece['free'][$slot['freeIndex']];

        $width = $slot['rotated'] ? $part['height'] : $part['width'];
        $height = $slot['rotated'] ? $part['width'] : $part['height'];

        [$kerfX, $restX] = $this->splitAxis($free['width'], $width, $kerf);
        [$kerfY, $restY] = $this->splitAxis($free['height'], $height, $kerf);

        /*
         * Across first: a full-width rectangle above the part, and whatever is beside it in the part's own
         * row. Down first: a full-height rectangle beside the part, and whatever is above it in the part's
         * own column.
         *
         * Both conserve area exactly - part + kerf + the two rectangles is the rectangle they came out of -
         * which is what makes the balance check on the page a real check rather than a restatement.
         */
        $across = [
            'kerfMm2' => $free['width'] * $kerfY + $kerfX * $height,
            'cutMm' => ($kerfY > 0 ? $free['width'] : 0) + ($kerfX > 0 ? $height : 0),
            'rects' => [
                ['x' => $free['x'], 'y' => $free['y'] + $height + $kerfY, 'width' => $free['width'], 'height' => $restY],
                ['x' => $free['x'] + $width + $kerfX, 'y' => $free['y'], 'width' => $restX, 'height' => $height],
            ],
        ];

        $down = [
            'kerfMm2' => $kerfX * $free['height'] + $width * $kerfY,
            'cutMm' => ($kerfX > 0 ? $free['height'] : 0) + ($kerfY > 0 ? $width : 0),
            'rects' => [
                ['x' => $free['x'] + $width + $kerfX, 'y' => $free['y'], 'width' => $restX, 'height' => $free['height']],
                ['x' => $free['x'], 'y' => $free['y'] + $height + $kerfY, 'width' => $width, 'height' => $restY],
            ],
        ];

        if ($randomizer instanceof Randomizer) {
            $split = $randomizer->getInt(0, 1) === 0 ? $across : $down;
        } else {
            $split = $this->rackValue($across['rects'], $cost) >= $this->rackValue($down['rects'], $cost)
                ? $across
                : $down;
        }

        //The part itself
        $piece['placements'][] = [
            'x' => $free['x'],
            'y' => $free['y'],
            'width' => $width,
            'height' => $height,
            'letter' => $part['letter'] ?? null,
            'pieceId' => $part['piece_id'] ?? null,
            'rotated' => $slot['rotated'],
            'askedWidth' => $part['width'],
            'askedHeight' => $part['height'],
        ];

        $piece['kerfMm2'] += $split['kerfMm2'];
        $piece['cutLengthMm'] += $split['cutMm'];

        //The rectangle it went into is gone, replaced by whatever the cuts left of it
        unset($piece['free'][$slot['freeIndex']]);

        foreach ($split['rects'] as $rect) {
            if ($rect['width'] > 0 && $rect['height'] > 0) {
                $piece['free'][] = $rect;
            }
        }

        $piece['free'] = array_values($piece['free']);
    }

    /**
     * What a set of rectangles would be worth on the rack, as an area of fresh plate.
     *
     * @param  array<int, array{x: int, y: int, width: int, height: int}>  $rects
     */
    private function rackValue(array $rects, TwoDimensionalNestingCost $cost): float
    {
        $value = 0.0;

        foreach ($rects as $rect) {
            $value += $cost->inventoryValueMm2($rect['width'], $rect['height']);
        }

        return $value;
    }

    /**
     * Whether a part of this size goes into a rectangle of that size.
     *
     * The part either finishes flush with the edge, or there has to be room for the torch to get past it.
     * This is the 1D "unused >= cut + kerf" test, said once per axis - and allowing the flush case matters
     * far more in 2D, because a part the full width of a sheet is an ordinary thing to cut and a part the
     * full length of a bar is not.
     */
    private function fits(int $partWidth, int $partHeight, int $freeWidth, int $freeHeight, int $kerf): bool
    {
        return $this->fitsOnAxis($partWidth, $freeWidth, $kerf)
            && $this->fitsOnAxis($partHeight, $freeHeight, $kerf);
    }

    private function fitsOnAxis(int $part, int $free, int $kerf): bool
    {
        if ($part > $free) {
            return false;
        }

        $remainder = $free - $part;

        return $remainder === 0 || $remainder >= $kerf;
    }

    /**
     * One axis of a cut: what the torch takes, and what is left behind it.
     *
     * @return array{0: int, 1: int} kerf consumed, then the remainder
     */
    private function splitAxis(int $free, int $part, int $kerf): array
    {
        $remainder = $free - $part;

        //Flush with the edge: no cut on this axis, so no kerf and nothing left
        if ($remainder === 0) {
            return [0, 0];
        }

        return [$kerf, $remainder - $kerf];
    }

    /**
     * Whether a piece of plate this size could hold the part at all, in either orientation.
     *
     * The same test bestSlot() applies, kerf and all, rather than a looser "does it go in". A piece opened
     * on the looser test can turn out not to hold the part once the torch needs room, and the part would
     * then be reported as unmakeable while a sheet that would have taken it went unconsidered.
     */
    private function holds(array $part, array $piece, int $kerf): bool
    {
        if ($this->fits($part['width'], $part['height'], $piece['width'], $piece['height'], $kerf)) {
            return true;
        }

        return ($part['rotatable'] ?? true)
            && $this->fits($part['height'], $part['width'], $piece['width'], $piece['height'], $kerf);
    }

    /**
     * An opened sheet or drawn remnant, before anything is cut out of it.
     *
     * @return array<string, mixed>
     */
    private function newPiece(
        int $width,
        int $height,
        bool $fromRack,
        ?string $mark = null,
        ?int $rackId = null,
        int $generation = 0,
    ): array {
        return [
            'fromRack' => $fromRack,
            'mark' => $mark,
            'rackId' => $rackId,
            'generation' => $generation,
            'width' => $width,
            'height' => $height,
            'placements' => [],
            'free' => [['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height]],
            'kerfMm2' => 0,
            'cutLengthMm' => 0.0,
        ];
    }

    /**
     * Judge what every piece has left, and price the plan.
     *
     * @param  array<int, array<string, mixed>>  $pieces
     * @param  array<int, array<string, mixed>>  $unmade
     * @return array<string, mixed>
     */
    private function finalise(array $pieces, array $unmade, TwoDimensionalNestingCost $cost, int $kerf): array
    {
        $totals = [
            'boughtMm2' => 0,
            'drawnMm2' => 0,
            'partsMm2' => 0,
            'kerfMm2' => 0,
            'bankedMm2' => 0,
            'scrapMm2' => 0,
        ];

        $cutLengthMm = 0.0;
        $priced = [];

        foreach ($pieces as $index => $piece) {
            //E) A cut between two pieces of waste is a cut nobody makes, so put them back together
            [$free, $reclaimed] = $this->mergeFree($piece['free'], $piece['placements'], $kerf);

            $piece['free'] = $free;
            $piece['kerfMm2'] -= $reclaimed;

            $banked = [];
            $scrap = [];

            //F) Marked and racked, or binned
            foreach ($free as $rect) {
                if ($cost->isBanked($rect['width'], $rect['height'])) {
                    $banked[] = $rect;
                } else {
                    $scrap[] = $rect;
                }
            }

            $partsMm2 = 0;
            foreach ($piece['placements'] as $placement) {
                $partsMm2 += $placement['width'] * $placement['height'];
            }

            $bankedMm2 = $this->area($banked);
            $scrapMm2 = $this->area($scrap);
            $area = $piece['width'] * $piece['height'];

            $piece['banked'] = $banked;
            $piece['scrap'] = $scrap;
            $piece['partsMm2'] = $partsMm2;
            $piece['bankedMm2'] = $bankedMm2;
            $piece['scrapMm2'] = $scrapMm2;
            $piece['areaMm2'] = $area;

            /*
             * Stated so a reader can add it up without reaching for a calculator, and so the page can go
             * red rather than quietly redraw if it ever stops holding. Parts, kerf, what was racked and
             * what was binned have to be the piece they all came off - exactly as a bar's cuts, kerf and
             * drop have to be the bar.
             */
            $piece['balance'] = [
                'parts' => $partsMm2,
                'kerf' => $piece['kerfMm2'],
                'banked' => $bankedMm2,
                'scrap' => $scrapMm2,
                'total' => $partsMm2 + $piece['kerfMm2'] + $bankedMm2 + $scrapMm2,
                'ok' => $partsMm2 + $piece['kerfMm2'] + $bankedMm2 + $scrapMm2 === $area,
            ];

            $totals[$piece['fromRack'] ? 'drawnMm2' : 'boughtMm2'] += $area;
            $totals['partsMm2'] += $partsMm2;
            $totals['kerfMm2'] += $piece['kerfMm2'];
            $totals['bankedMm2'] += $bankedMm2;
            $totals['scrapMm2'] += $scrapMm2;

            $cutLengthMm += $piece['cutLengthMm'];

            $priced[] = [
                'width' => $piece['width'],
                'height' => $piece['height'],
                'fromRack' => $piece['fromRack'],
                'banked' => $banked,
            ];

            $pieces[$index] = $piece;
        }

        return [
            'pieces' => array_values($pieces),
            'unmade' => $unmade,
            'totals' => $totals,
            'cutLengthMm' => $cutLengthMm,
            'cost' => $cost->cost(
                $priced,
                (int) $totals['scrapMm2'],
                (int) $totals['kerfMm2'],
                $cutLengthMm,
                count($unmade),
            ),
            /*
             * Two readings of the same plan, both reported.
             *
             * Utilisation is the share of the plate opened that became parts, which is the figure a
             * fabricator already knows. Yield is the share that was not DESTROYED - parts plus everything
             * racked - which is the figure this whole exercise is about, and the 2D counterpart of
             * NestingFormatter::shareNotDestroyed(). The gap between them is the rack.
             */
            'utilisation' => $this->share($totals['partsMm2'], $totals['boughtMm2'] + $totals['drawnMm2']),
            'yield' => $this->share(
                $totals['partsMm2'] + $totals['bankedMm2'],
                $totals['boughtMm2'] + $totals['drawnMm2'],
            ),
        ];
    }

    /**
     * Put back together the rectangles that are only separated by a cut nobody would make.
     *
     * A guillotine split charges kerf on both axes, because at the time it is made the algorithm does not
     * know whether either side will be cut into again. Where neither was - two leftovers sitting side by
     * side with a 4mm strip of nothing between them - that cut is imaginary, and leaving it in both
     * understates the rack and overstates the dust.
     *
     * Merging is only ever done where the two rectangles span the same extent on the shared edge AND
     * nothing is placed in the strip between them. The second test is what keeps it honest: without it
     * this would happily merge across a part and hand the yard a remnant with a hole in it.
     *
     * Area is conserved by construction - the reclaimed kerf becomes part of the merged rectangle and is
     * subtracted from the kerf total by the caller.
     *
     * @param  array<int, array{x: int, y: int, width: int, height: int}>  $free
     * @param  array<int, array<string, mixed>>  $placements
     * @return array{0: array<int, array{x: int, y: int, width: int, height: int}>, 1: int} the rectangles, and the kerf reclaimed
     */
    private function mergeFree(array $free, array $placements, int $kerf): array
    {
        $reclaimed = 0;
        $merged = true;

        while ($merged) {
            $merged = false;
            $free = array_values($free);

            foreach ($free as $a => $first) {
                foreach ($free as $b => $second) {
                    if ($a === $b) {
                        continue;
                    }

                    $union = $this->mergeable($first, $second, $kerf, $placements);

                    if ($union === null) {
                        continue;
                    }

                    $reclaimed += $union['width'] * $union['height']
                        - $first['width'] * $first['height']
                        - $second['width'] * $second['height'];

                    unset($free[$a], $free[$b]);
                    $free[] = $union;

                    $merged = true;
                    break 2;
                }
            }
        }

        return [array_values($free), $reclaimed];
    }

    /**
     * The one rectangle these two make, if they make one.
     *
     * @param  array{x: int, y: int, width: int, height: int}  $first
     * @param  array{x: int, y: int, width: int, height: int}  $second
     * @param  array<int, array<string, mixed>>  $placements
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    private function mergeable(array $first, array $second, int $kerf, array $placements): ?array
    {
        //Stacked: same left edge, same width, one directly above the other
        if ($first['x'] === $second['x'] && $first['width'] === $second['width']) {
            $gap = $second['y'] - ($first['y'] + $first['height']);

            if ($gap >= 0 && $gap <= $kerf) {
                $strip = [
                    'x' => $first['x'],
                    'y' => $first['y'] + $first['height'],
                    'width' => $first['width'],
                    'height' => $gap,
                ];

                if (! $this->occupied($strip, $placements)) {
                    return [
                        'x' => $first['x'],
                        'y' => $first['y'],
                        'width' => $first['width'],
                        'height' => $first['height'] + $gap + $second['height'],
                    ];
                }
            }
        }

        //Side by side: same bottom edge, same height, one directly beside the other
        if ($first['y'] === $second['y'] && $first['height'] === $second['height']) {
            $gap = $second['x'] - ($first['x'] + $first['width']);

            if ($gap >= 0 && $gap <= $kerf) {
                $strip = [
                    'x' => $first['x'] + $first['width'],
                    'y' => $first['y'],
                    'width' => $gap,
                    'height' => $first['height'],
                ];

                if (! $this->occupied($strip, $placements)) {
                    return [
                        'x' => $first['x'],
                        'y' => $first['y'],
                        'width' => $first['width'] + $gap + $second['width'],
                        'height' => $first['height'],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Whether any part overlaps this strip.
     *
     * A zero-width or zero-height strip overlaps nothing, which is the common case - two rectangles that
     * are already touching.
     *
     * @param  array{x: int, y: int, width: int, height: int}  $strip
     * @param  array<int, array<string, mixed>>  $placements
     */
    private function occupied(array $strip, array $placements): bool
    {
        if ($strip['width'] <= 0 || $strip['height'] <= 0) {
            return false;
        }

        foreach ($placements as $placement) {
            $apart = $placement['x'] >= $strip['x'] + $strip['width']
                || $placement['x'] + $placement['width'] <= $strip['x']
                || $placement['y'] >= $strip['y'] + $strip['height']
                || $placement['y'] + $placement['height'] <= $strip['y'];

            if (! $apart) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parts biggest first, and the order fully determined.
     *
     * Area descending, then the longer edge, then the shorter, then the part's own id - so two parts of
     * the same size never swap places because of how the caller happened to list them. The 1D nesting
     * sorts longest first for the same reason: the awkward parts get first claim on the material.
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @return array<int, array<string, mixed>>
     */
    private function sortParts(array $parts): array
    {
        usort($parts, function (array $a, array $b) {
            //Area, then the longer edge, then the shorter - all descending
            $size = [
                $b['width'] * $b['height'],
                max($b['width'], $b['height']),
                min($b['width'], $b['height']),
            ] <=> [
                $a['width'] * $a['height'],
                max($a['width'], $a['height']),
                min($a['width'], $a['height']),
            ];

            //Identical parts keep the order they were handed over in
            return $size !== 0 ? $size : ($a['piece_id'] ?? 0) <=> ($b['piece_id'] ?? 0);
        });

        return $parts;
    }

    /**
     * @param  array<int, array{width: int, height: int}>  $sheets
     * @return array<int, array{width: int, height: int}>
     */
    private function sortSheets(array $sheets): array
    {
        usort($sheets, fn (array $a, array $b) => [$a['width'] * $a['height'], $a['width']]
            <=> [$b['width'] * $b['height'], $b['width']]);

        return $sheets;
    }

    /**
     * Smallest remnant first, then by id.
     *
     * The same ordering NestingFormatter::sortOffcutInventory() applies, and for the same reason: the plan
     * must not depend on the order the database happened to hand the rack back in.
     *
     * @param  array<int, array<string, mixed>>  $rack
     * @return array<int, array<string, mixed>>
     */
    private function sortRack(array $rack): array
    {
        usort($rack, fn (array $a, array $b) => [$a['width'] * $a['height'], $a['id'] ?? 0]
            <=> [$b['width'] * $b['height'], $b['id'] ?? 0]);

        return $rack;
    }

    /**
     * Shuffle the build order a little, for a random candidate.
     *
     * Adjacent swaps rather than a full shuffle. Biggest-first is a good order and a plan that abandons it
     * entirely is almost always worse; what the search is looking for is the plan where two parts of
     * similar size go in the other way round, which is exactly what this produces.
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @return array<int, array<string, mixed>>
     */
    private function jumble(array $parts, Randomizer $randomizer): array
    {
        $count = count($parts);

        for ($i = 0; $i < $count - 1; $i++) {
            if ($randomizer->getInt(0, 2) === 0) {
                [$parts[$i], $parts[$i + 1]] = [$parts[$i + 1], $parts[$i]];
            }
        }

        return $parts;
    }

    /**
     * @param  array<int, array{width: int, height: int}>  $rects
     */
    private function area(array $rects): int
    {
        $area = 0;

        foreach ($rects as $rect) {
            $area += $rect['width'] * $rect['height'];
        }

        return $area;
    }

    private function share(int|float $part, int|float $whole): float
    {
        if ($whole <= 0) {
            return 0.0;
        }

        return round($part / $whole * 100, 2);
    }

    /**
     * A seed that depends on the inputs and nothing else.
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @param  array<int, array{width: int, height: int}>  $sheets
     * @param  array<int, array<string, mixed>>  $rack
     * @param  array<string, mixed>  $settings
     */
    private function seed(array $parts, array $sheets, array $rack, array $settings): int
    {
        $fingerprint = json_encode([$parts, $sheets, $rack, $settings]);

        return (int) hexdec(substr(md5((string) $fingerprint), 0, 8));
    }
}
