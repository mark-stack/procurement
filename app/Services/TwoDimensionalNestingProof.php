<?php

namespace App\Services;

use App\Formatters\UniqueLetterIDGenerator;

/**
 * Three plate lifecycles, run through the 2D nesting, with everything a reader needs to check the answer.
 *
 * The companion to Services\NestingProof, built the same way and for the same reason: a page that merely
 * drew a nest would be a picture, and the thing actually in question is whether the offcut philosophy
 * survives into two dimensions. So the scenarios are fixed, the plans are produced by the live engine when
 * the page loads, and the checks each one returns are real assertions about that run.
 *
 * NOTHING HERE IS A RE-IMPLEMENTATION. Every plan comes out of Services\TwoDimensionalNesting::nest(), and
 * the rule deciding which remnants get a mark is the engine's own three-part test - this class never
 * decides it, it only reads which rectangles came back banked.
 *
 * WHAT IS NOT REAL. Everything, in the sense that matters: there is no plate nesting in the application,
 * no table of sheet remnants, no migration, and no screen a customer can reach. The rack here is carried
 * in memory from one job to the next, as it is in the 1D proof, and the settings are pinned rather than
 * read off anybody's business - which is what lets the page answer the same way on every installation, so
 * the only thing that can change an answer is a change to the algorithm.
 *
 * THE SECOND SCENARIO IS THE ONE WORTH READING. The first and third are the 1D proof's arguments re-run on
 * plate, and they pass, which is the good news. The second is the argument that does NOT carry over: it
 * produces a remnant with half a square metre of perfectly good steel in it that is still worth nothing,
 * because a 180mm strip holds no part anybody asked for. A 1D threshold cannot express that, and the whole
 * reason this is a proof of concept rather than a feature is that somebody has to decide what the real
 * numbers are.
 */
class TwoDimensionalNestingProof
{
    /**
     * The settings every scenario is run under.
     *
     * Fixed rather than read off the viewing admin's business, because a proof that moves when somebody
     * edits a coefficient proves nothing - and because none of these are business settings yet. There is
     * no column behind any of them. See the note on TwoDimensionalNestingCost.
     */
    public const int KERF_MM = 4;

    /** Nothing narrower than this is worth racking, however long it is. */
    public const int MIN_OFFCUT_SIDE_MM = 250;

    /** Nor anything smaller than this, however square. 0.16m2 - a 400 x 400 piece. */
    public const int MIN_OFFCUT_AREA_MM2 = 160_000;

    /** What this imaginary shop can buy, for every scenario. */
    public const array STOCK_SHEETS = [
        ['width' => 2400, 'height' => 1200],
        ['width' => 3000, 'height' => 1500],
    ];

    /**
     * Candidate plans per job.
     *
     * Lower than the 1D nesting's iteration count on purpose. A 2D candidate is far more work to build -
     * every part is tried against every free rectangle on every open piece, in two orientations - and this
     * page runs a dozen nests on every request. The figure is stated on the page so a reader knows how
     * hard the search looked.
     */
    public const int ITERATIONS = 120;

    /**
     * 6mm plate: 6 x 7.85kg per square metre per millimetre of thickness.
     *
     * One thickness for all three scenarios, so the dollars on the page are comparable between them.
     */
    public const float KG_PER_M2 = 47.1;

    private readonly TwoDimensionalNesting $engine;

    /**
     * Every piece of plate the scenario being run has put on the rack, keyed by its mark.
     *
     * Scenario-scoped state, held here rather than threaded through a dozen signatures - run() clears it
     * before each one, so the three never see each other's marks. The same arrangement NestingProof uses.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $ledger = [];

    /** Marks for the scenario being run, in a fixed order. Reset alongside the ledger. */
    private \Generator $marks;

    /** Ids for the rack, which is what a drawn piece is matched back to. Reset alongside the ledger. */
    private int $nextRackId = 1;

    public function __construct()
    {
        $this->engine = new TwoDimensionalNesting;
        $this->marks = $this->markSequence();
    }

    /**
     * Every scenario, run.
     *
     * @return array<int, array<string, mixed>>
     */
    public function scenarios(): array
    {
        return array_map(fn (array $definition) => $this->run($definition), $this->definitions());
    }

    /**
     * The settings the scenarios run under, for the page to state alongside them.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'kerfMm' => self::KERF_MM,
            'minOffcutSideMm' => self::MIN_OFFCUT_SIDE_MM,
            'minOffcutAreaMm2' => self::MIN_OFFCUT_AREA_MM2,
            'stockSheets' => self::STOCK_SHEETS,
            'iterations' => self::ITERATIONS,
            'kgPerM2' => self::KG_PER_M2,
            'materialCostPerTonne' => TwoDimensionalNestingCost::DEFAULTS['material_cost_per_tonne'],
            'labourRatePerHour' => TwoDimensionalNestingCost::DEFAULTS['labour_rate_per_hour'],
            'retentionCap' => TwoDimensionalNestingCost::DEFAULTS['offcut_retention_cap'],
        ];
    }

    /** What every scenario is nested under. */
    private function engineSettings(): array
    {
        return [
            'kerf_mm' => self::KERF_MM,
            'min_offcut_side_mm' => self::MIN_OFFCUT_SIDE_MM,
            'min_offcut_area_mm2' => self::MIN_OFFCUT_AREA_MM2,
            'iterations' => self::ITERATIONS,
            'kg_per_m2' => self::KG_PER_M2,
        ];
    }

    /**
     * The three scenarios, as inputs only.
     *
     * No outcome is written down here. Every number the page shows is computed from these by run(), which
     * is what makes the checks assertions rather than decoration.
     *
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            /*
             * The 1D proof's first scenario, on plate. One sheet, cut down over four jobs, each one sized
             * to come out of what the one before it left - so the rack holds one piece at a time and every
             * decision is visible.
             */
            [
                'key' => 'generations',
                'title' => 'One sheet, four generations',
                'claim' => 'A single 3,000 x 1,500 sheet covers four separate jobs. Each job is cut out of what the one before it left on the rack, so the sheet becomes a remnant, then a remnant of that remnant, then a remnant of THAT - and the chain stops itself when what is left will not hold a part worth keeping.',
                'watch' => 'The mark in the "racked" list at the end of one step is the piece drawn in the next.',
                'plate' => '6mm plate',
                'projects' => [
                    1 => ['letter' => 'A', 'name' => 'Base plates'],
                    2 => ['letter' => 'B', 'name' => 'Gussets'],
                    3 => ['letter' => 'C', 'name' => 'Cleats'],
                    4 => ['letter' => 'D', 'name' => 'Shims'],
                ],
                'openingRack' => [],
                'jobs' => [
                    [
                        'name' => 'Nothing on the rack',
                        'note' => 'Six base plates, nothing in the yard. A full 3,000 x 1,500 sheet is bought and the parts come out of it three across and two deep, which leaves a tall band down one side - comfortably over both thresholds, so it is marked and racked rather than binned. The shallow strip along the top is not, and goes in the bin.',
                        'lines' => [['project' => 1, 'width' => 700, 'height' => 700, 'qty' => 6]],
                    ],
                    [
                        'name' => 'A second job, weeks later',
                        'note' => 'Two gussets. Buying a sheet for half a square metre of parts would mean freight, receiving, the paperwork of an order and a sheet nobody asked for leaning against the wall. The band on the rack covers it, and both pieces left behind still clear the thresholds - so one remnant becomes two, and the whole job costs a fraction of what step 1 did.',
                        'lines' => [['project' => 2, 'width' => 420, 'height' => 600, 'qty' => 2]],
                    ],
                    [
                        'name' => 'A third job',
                        'note' => 'Two cleats, cut out of a second-generation piece - steel that was already a drop off a drop. What is left this time is mostly too small to be worth a mark: one third-generation piece goes back on the rack and the rest is binned.',
                        'lines' => [['project' => 3, 'width' => 300, 'height' => 400, 'qty' => 2]],
                    ],
                    [
                        'name' => 'The last of it',
                        'note' => 'Two shims, out of the third-generation piece. Nothing behind them is worth a mark, so this branch of the chain ends here - because the steel ran out, not because anything counted generations. The yield on this step is the lowest on the page and that is honest: the last of a piece is always the worst of it.',
                        'lines' => [['project' => 4, 'width' => 260, 'height' => 300, 'qty' => 2]],
                    ],
                ],
            ],

            /*
             * THE SCENARIO THIS PAGE EXISTS FOR.
             *
             * A job sized to leave a long, thin band across a full sheet: plenty of area, no usable width.
             * The 1D threshold is a single number and would bank this without hesitating, because by every
             * one-dimensional measure it is a large piece of steel.
             *
             * The parts are deliberately ordinary. Nothing here is a trick - this is what a run of 1,200mm
             * long brackets does to a 1,500mm sheet, and it is why plate shops end up with a wall of
             * strips nobody ever cuts.
             */
            [
                'key' => 'slivers',
                'title' => 'Area is not the test',
                'claim' => 'A sheet can be left with over half a square metre of flawless steel that is worth nothing, because it is 192mm wide and no part anybody has ever ordered is under 192mm. A threshold on area alone banks it. A threshold on area alone is how a rack fills with strips nobody ever cuts.',
                'watch' => 'Step 1 bins a 3,000 x 192 band - more than three times the minimum area - while step 2 racks a piece of similar size that is simply a better shape. Then the chain starts: steps 3 and 4 cut that piece, and the piece it left.',
                'plate' => '6mm plate',
                'projects' => [
                    1 => ['letter' => 'A', 'name' => 'Long brackets'],
                    2 => ['letter' => 'B', 'name' => 'End caps'],
                    3 => ['letter' => 'C', 'name' => 'Stiffeners'],
                    4 => ['letter' => 'D', 'name' => 'Pads'],
                ],
                'openingRack' => [],
                'jobs' => [
                    [
                        'name' => 'Long parts, nothing left worth keeping',
                        'note' => 'Two 2,900 x 650 brackets. They all but fill a 3,000 x 1,500 sheet in one direction and leave a band across it in the other. That band is over half a square metre of flawless plate - three times the minimum area - and it is 192mm wide, so nothing anybody has ordered comes out of it. It is binned, and the step is drawn with no racked piece at all rather than quietly counting the band as yield.',
                        'lines' => [['project' => 1, 'width' => 2900, 'height' => 650, 'qty' => 2]],
                    ],
                    [
                        'name' => 'The same test, the opposite answer',
                        'note' => 'Three end caps off a 2,400 x 1,200 sheet, and both halves of the argument land in one step. The 888 x 1,200 piece is racked. The 1,508 x 96 strip beside it is binned - and it is binned for width, not for area, which is exactly why the band in step 1 went the same way despite being four times its size. Area decides nothing here; the short side decides everything.',
                        'lines' => [['project' => 2, 'width' => 1100, 'height' => 500, 'qty' => 3]],
                    ],
                    [
                        'name' => 'Proving the point',
                        'note' => 'Two stiffeners, which the piece racked in step 2 holds comfortably - and which the larger band binned in step 1 could not have made one of. Both pieces this leaves behind are worth keeping, so the rack grows rather than shrinks.',
                        'lines' => [['project' => 3, 'width' => 480, 'height' => 320, 'qty' => 2]],
                    ],
                    [
                        'name' => 'And into the third generation',
                        'note' => 'Two pads, cut out of a piece that was itself cut out of a remnant. There is nothing special about it by this point - it is a rectangle of known size on the rack, and it is drawn because it is the cheapest place to put the parts.',
                        'lines' => [['project' => 4, 'width' => 350, 'height' => 300, 'qty' => 2]],
                    ],
                ],
            ],

            /*
             * The 1D proof's second and third scenarios, merged: a rack with a genuine choice on it, and a
             * job that nests several projects through one sheet. Its job is the bookkeeping - every part
             * still has to come back out attributed to the project that asked for it.
             */
            [
                'key' => 'rack',
                'title' => 'Three projects, one rack',
                'claim' => 'A real week: several projects nested together, remnants inherited from jobs that are long finished, and a choice on the rack that the cost model has to make rather than guess at. Every part still comes back out of the plan tagged with the project that asked for it.',
                'watch' => 'Step 1 buys and draws in the same plan. Step 2 is a genuine choice: several pieces on the rack hold the part, and they are not equally good places to put it.',
                'plate' => '6mm plate',
                'projects' => [
                    1 => ['letter' => 'A', 'name' => 'Walkway treads'],
                    2 => ['letter' => 'B', 'name' => 'Hopper liners'],
                    3 => ['letter' => 'C', 'name' => 'Pump mounts'],
                    4 => ['letter' => 'D', 'name' => 'Guard panels'],
                ],
                /*
                 * Two pieces left by jobs before this scenario starts. Every shop has them, and a proof
                 * that only ever started from an empty rack would never show one being inherited.
                 */
                'openingRack' => [
                    ['width' => 1500, 'height' => 800],
                    ['width' => 950, 'height' => 700],
                ],
                'jobs' => [
                    [
                        'name' => 'Three projects at once',
                        'note' => 'Treads, liners and mounts, nested together, and the plan does two things at once: it buys one sheet for the bulk of the work AND draws an inherited remnant for the part that fits it. Both happen inside a single priced plan rather than one pass after another - which is the mistake the 1D nesting made for a while, and spending shelf stock on parts a sheet being bought anyway had room for is a worse mistake on plate. Follow the letters: each part is tagged with the project that asked for it, which is what the cut list in the workshop is read off.',
                        'lines' => [
                            ['project' => 1, 'width' => 1200, 'height' => 400, 'qty' => 3],
                            ['project' => 2, 'width' => 800, 'height' => 600, 'qty' => 2],
                            ['project' => 3, 'width' => 350, 'height' => 350, 'qty' => 4],
                        ],
                    ],
                    [
                        'name' => 'The choice',
                        'note' => 'One 600 x 450 guard panel, and three pieces on the rack that could hold it. They are not equally good places to put it: one is a second-generation piece worth keeping whole, one is small enough that the panel would finish it off, and the model opens the one that leaves the yard best off rather than the first that fits or the tightest. The plan is priced in dollars, which is the only way "a piece retired for good" and "a bigger piece cut into" can be compared at all.',
                        'lines' => [['project' => 4, 'width' => 600, 'height' => 450, 'qty' => 1]],
                    ],
                    [
                        'name' => 'Cutting a remnant of a remnant',
                        'note' => 'Two more mounts, and the piece drawn for them is a second-generation one - steel that was already a drop off a drop. It is cut like anything else, and what it leaves goes back on the rack as a third generation.',
                        'lines' => [['project' => 3, 'width' => 350, 'height' => 350, 'qty' => 2]],
                    ],
                    [
                        'name' => 'No rule about generations',
                        'note' => 'One last liner, and the rack now holds pieces of three different generations. The one that gets drawn is a first-generation piece, not the deepest - because there is no rule about generations, only about what each decision costs. It is consumed to nothing, which retires its mark for good.',
                        'lines' => [['project' => 2, 'width' => 500, 'height' => 400, 'qty' => 1]],
                    ],
                ],
            ],
        ];
    }

    /**
     * Run one scenario end to end: every job in order, carrying the rack forward.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function run(array $definition): array
    {
        //Each scenario starts with an empty ledger and its own run of marks
        $this->ledger = [];
        $this->marks = $this->markSequence();
        $this->nextRackId = 1;

        $rack = [];
        $openingRackMm2 = 0;

        foreach ($definition['openingRack'] as $piece) {
            $rack[] = $this->bank($piece['width'], $piece['height'], generation: 1, parentMark: null, step: 0);
            $openingRackMm2 += $piece['width'] * $piece['height'];
        }

        //Part ids are what a placement is matched back to, so they have to be unique across the run
        $partId = 1;
        $steps = [];

        foreach ($definition['jobs'] as $index => $job) {
            $parts = [];

            foreach ($job['lines'] as $line) {
                for ($i = 0; $i < $line['qty']; $i++) {
                    $parts[] = [
                        'width' => $line['width'],
                        'height' => $line['height'],
                        'letter' => $definition['projects'][$line['project']]['letter'],
                        'piece_id' => $partId++,
                    ];
                }
            }

            $rackBefore = $rack;

            //The real thing, on the same call anything else would make
            $plan = $this->engine->nest($parts, self::STOCK_SHEETS, $rack, $this->engineSettings());

            [$pieces, $rack] = $this->applyToRack($plan, $rack, $index + 1);

            $steps[] = [
                'number' => $index + 1,
                'name' => $job['name'],
                'note' => $job['note'],
                'required' => $this->requiredLines($job['lines'], $definition['projects']),
                'rackBefore' => $this->rackView($rackBefore),
                'rackAfter' => $this->rackView($rack),
                'pieces' => $pieces,
                'unmadeParts' => count($plan['unmade']),
                'cost' => round($plan['cost'], 2),
                'utilisation' => $plan['utilisation'],
                'yield' => $plan['yield'],
                'boughtMm2' => $plan['totals']['boughtMm2'],
                'drawnMm2' => $plan['totals']['drawnMm2'],
                'bankedMm2' => $plan['totals']['bankedMm2'],
                'scrapMm2' => $plan['totals']['scrapMm2'],
                'kerfMm2' => $plan['totals']['kerfMm2'],
                'partsMm2' => $plan['totals']['partsMm2'],
                'candidatesTried' => $plan['candidatesTried'],
            ];
        }

        return [
            'key' => $definition['key'],
            'title' => $definition['title'],
            'claim' => $definition['claim'],
            'watch' => $definition['watch'],
            'plate' => $definition['plate'],
            'projects' => array_values(array_map(
                fn (array $project) => ['letter' => $project['letter'], 'name' => $project['name']],
                $definition['projects'],
            )),
            'steps' => $steps,
            'lineage' => $this->lineage(),
            'checks' => $this->checks($definition, $steps, $openingRackMm2),
        ];
    }

    /**
     * Turn one plan into the pieces the page draws, and move the rack on.
     *
     * The decision about which remnants are worth keeping is NOT made here. The engine has already made it
     * - each piece comes back with its leftovers already split into 'banked' and 'scrap' - and this method
     * only hands the banked ones a mark and puts them on the rack. Deciding it twice is how the 1D proof's
     * equivalent would have gone wrong, and the note on NestingProof::applyToRack() says so.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<int, array<string, mixed>>  $rack
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function applyToRack(array $plan, array $rack, int $step): array
    {
        $pieces = [];

        foreach ($plan['pieces'] as $piece) {
            $sourceMark = $piece['mark'];
            $generation = 0;

            if ($piece['fromRack']) {
                $generation = (int) $this->ledger[$sourceMark]['generation'];

                //Off the rack, whatever becomes of it
                $rack = array_values(array_filter(
                    $rack,
                    fn (array $item) => $item['id'] !== $piece['rackId'],
                ));

                $this->ledger[$sourceMark]['consumedInStep'] = $step;
            }

            //Every rectangle the engine judged worth keeping gets a mark and goes on the rack
            $banked = [];

            foreach ($piece['banked'] as $rect) {
                $child = $this->bank(
                    $rect['width'],
                    $rect['height'],
                    generation: $generation + 1,
                    parentMark: $sourceMark,
                    step: $step,
                );

                $rack[] = $child;

                $banked[] = $rect + ['mark' => $child['unique_mark'], 'generation' => $generation + 1];
            }

            $pieces[] = [
                'fromRack' => $piece['fromRack'],
                'mark' => $sourceMark,
                'generation' => $generation,
                'width' => $piece['width'],
                'height' => $piece['height'],
                'placements' => $piece['placements'],
                'banked' => $banked,
                'scrap' => $piece['scrap'],
                'areaMm2' => $piece['areaMm2'],
                'partsMm2' => $piece['partsMm2'],
                'kerfMm2' => $piece['kerfMm2'],
                'bankedMm2' => $piece['bankedMm2'],
                'scrapMm2' => $piece['scrapMm2'],
                'balance' => $piece['balance'],
            ];
        }

        return [$pieces, $rack];
    }

    /**
     * Put one new piece of plate on the rack and write it into the ledger.
     *
     * @return array<string, mixed>
     */
    private function bank(int $width, int $height, int $generation, ?string $parentMark, int $step): array
    {
        $mark = $this->marks->current();
        $this->marks->next();

        $this->ledger[$mark] = [
            'mark' => $mark,
            'widthMm' => $width,
            'heightMm' => $height,
            'areaMm2' => $width * $height,
            'generation' => $generation,
            'parentMark' => $parentMark,
            'bornInStep' => $step,
            'consumedInStep' => null,
        ];

        /*
         * Shaped as the engine reads a rack piece - id, unique_mark, width, height and generation are what
         * TwoDimensionalNesting::openPiece() takes off these rows, and sortRack() orders on area and id.
         */
        return [
            'id' => $this->nextRackId++,
            'unique_mark' => $mark,
            'width' => $width,
            'height' => $height,
            'generation' => $generation,
        ];
    }

    /**
     * Marks, in a fixed order.
     *
     * UniqueLetterIDGenerator::generate() reads the offcuts table and rolls at random, neither of which a
     * fixed scenario can use. codeFromIndex() is the same alphabet and the same code shape, counted rather
     * than drawn - so the marks look exactly like the ones a real nest stamps, and are the same on every
     * installation. Taken from NestingProof, which does this for the same reason.
     *
     * @return \Generator<int, string>
     */
    private function markSequence(): \Generator
    {
        $index = 0;

        while (true) {
            yield UniqueLetterIDGenerator::codeFromIndex($index, UniqueLetterIDGenerator::MIN_LENGTH);
            $index++;
        }
    }

    /**
     * The rack as the page lists it.
     *
     * @param  array<int, array<string, mixed>>  $rack
     * @return array<int, array<string, mixed>>
     */
    private function rackView(array $rack): array
    {
        return array_map(fn (array $piece) => [
            'mark' => $piece['unique_mark'],
            'widthMm' => $piece['width'],
            'heightMm' => $piece['height'],
            'areaMm2' => $piece['width'] * $piece['height'],
            'generation' => $piece['generation'],
            'parentMark' => $this->ledger[$piece['unique_mark']]['parentMark'],
        ], array_values($rack));
    }

    /**
     * What a job asked for, as a cut list.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, array<string, string>>  $projects
     * @return array<int, array<string, mixed>>
     */
    private function requiredLines(array $lines, array $projects): array
    {
        return array_map(fn (array $line) => [
            'widthMm' => $line['width'],
            'heightMm' => $line['height'],
            'qty' => $line['qty'],
            'letter' => $projects[$line['project']]['letter'],
            'project' => $projects[$line['project']]['name'],
        ], $lines);
    }

    /**
     * Every piece of plate the scenario put on the rack, and what became of it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lineage(): array
    {
        return array_values(array_map(fn (array $entry) => $entry + [
            'onRack' => $entry['consumedInStep'] === null,
        ], $this->ledger));
    }

    /**
     * The assertions the page reports, run against the scenario that just finished.
     *
     * These are the proof. Everything else is a drawing of a plan; these say whether the plan holds
     * together, and they are computed from the run rather than written down beside it - so a change to the
     * engine that breaks one turns the page red instead of silently redrawing.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function checks(array $definition, array $steps, int $openingRackMm2): array
    {
        //What was asked for, and what came off the table, as two sorted lists of sizes
        $asked = [];

        foreach ($definition['jobs'] as $job) {
            foreach ($job['lines'] as $line) {
                for ($i = 0; $i < $line['qty']; $i++) {
                    //Sorted within the pair, because a rotated part is the same part
                    $asked[] = $this->sizeKey($line['width'], $line['height']);
                }
            }
        }

        $made = [];
        $boughtMm2 = 0;
        $drawnMm2 = 0;
        $partsMm2 = 0;
        $kerfMm2 = 0;
        $bankedMm2 = 0;
        $scrapMm2 = 0;
        $unbalanced = 0;
        $overlaps = 0;
        $outOfBounds = 0;
        $misjudged = 0;
        $unmade = 0;
        $deepestGeneration = 0;
        $reusedDeep = 0;

        foreach ($steps as $step) {
            $unmade += (int) $step['unmadeParts'];

            foreach ($step['pieces'] as $piece) {
                $boughtMm2 += $piece['fromRack'] ? 0 : $piece['areaMm2'];
                $drawnMm2 += $piece['fromRack'] ? $piece['areaMm2'] : 0;
                $partsMm2 += $piece['partsMm2'];
                $kerfMm2 += $piece['kerfMm2'];
                $bankedMm2 += $piece['bankedMm2'];
                $scrapMm2 += $piece['scrapMm2'];

                if (! $piece['balance']['ok']) {
                    $unbalanced++;
                }

                foreach ($piece['placements'] as $placement) {
                    $made[] = $this->sizeKey($placement['askedWidth'], $placement['askedHeight']);
                }

                $overlaps += $this->overlapsOn($piece);
                $outOfBounds += $this->outOfBoundsOn($piece);
                $misjudged += $this->misjudgedOn($piece);

                /*
                 * A piece drawn off the rack that was itself a drop off a drop. This is the thing the page
                 * exists to show, and it is asserted rather than left to the balance checks - a scenario
                 * that merely banked remnants and never came back for them would pass everything else and
                 * prove nothing about the lifecycle.
                 */
                if ($piece['fromRack'] && $piece['generation'] >= 2) {
                    $reusedDeep++;
                }

                foreach ($piece['banked'] as $banked) {
                    $deepestGeneration = max($deepestGeneration, (int) $banked['generation']);
                }
            }
        }

        sort($asked);
        sort($made);

        $opened = $boughtMm2 + $drawnMm2;

        /*
         * Conservation. Every square millimetre opened became a part, dust, something on the rack, or
         * something in the bin - and the rack the scenario started with has to be in there too.
         */
        $accounted = $partsMm2 + $kerfMm2 + $bankedMm2 + $scrapMm2;

        return [
            [
                'label' => 'Every part asked for was made',
                'passed' => $asked === $made && $unmade === 0,
                'detail' => count($asked).' asked for, '.count($made).' placed'
                    .($unmade > 0 ? ', '.$unmade.' that nothing could hold' : '')
                    .($asked === $made ? '' : ' - and the two lists do not match'),
            ],
            [
                'label' => 'Every sheet and remnant balances',
                'passed' => $unbalanced === 0,
                'detail' => $unbalanced === 0
                    ? 'Parts + kerf + racked + binned came to the piece it all came off, on every one of them.'
                    : $unbalanced.' did not add up to the piece they came off.',
            ],
            [
                'label' => 'No two parts occupy the same steel',
                'passed' => $overlaps === 0,
                'detail' => $overlaps === 0
                    ? 'Every part, racked remnant and binned remnant is clear of every other one on its piece.'
                    : $overlaps.' overlapping pairs.',
            ],
            [
                'label' => 'Nothing was cut off the edge',
                'passed' => $outOfBounds === 0,
                'detail' => $outOfBounds === 0
                    ? 'Every rectangle drawn sits inside the piece it was cut from.'
                    : $outOfBounds.' rectangles fall outside their piece.',
            ],
            [
                'label' => 'No steel appeared or vanished',
                'passed' => $accounted === $opened,
                'detail' => $this->m2($opened).' opened ('.$this->m2($boughtMm2).' bought, '
                    .$this->m2($drawnMm2).' drawn off a rack that started at '.$this->m2($openingRackMm2).')'
                    .' = '.$this->m2($partsMm2).' of parts + '.$this->m2($kerfMm2).' of kerf + '
                    .$this->m2($bankedMm2).' racked + '.$this->m2($scrapMm2).' binned'
                    .($accounted === $opened ? '' : ' - which is '.$this->m2($accounted).', and should not be'),
            ],
            [
                'label' => 'The three-part test was applied to every remnant',
                'passed' => $misjudged === 0,
                'detail' => $misjudged === 0
                    ? 'Nothing under '.self::MIN_OFFCUT_SIDE_MM.'mm on a side or under '
                        .$this->m2(self::MIN_OFFCUT_AREA_MM2).' was racked, and nothing over both was binned.'
                    : $misjudged.' remnants were racked or binned against the rule.',
            ],
            [
                'label' => 'A remnant of a remnant was cut again',
                'passed' => $reusedDeep > 0,
                'detail' => $reusedDeep > 0
                    ? $reusedDeep.' piece'.($reusedDeep === 1 ? '' : 's').' drawn off the rack had already been a drop off a drop. Deepest generation reached: '.$deepestGeneration.'.'
                    : 'No piece was drawn that was itself cut from a remnant, so this run shows no lifecycle.',
            ],
        ];
    }

    /**
     * Overlapping pairs on one piece.
     *
     * Parts, racked remnants and binned remnants are all checked against each other, because they are all
     * claims on the same steel: a racked remnant that overlaps a part is a mark on metal that has already
     * been cut away, which is worse than a wrong number - it is a piece of inventory that does not exist.
     *
     * @param  array<string, mixed>  $piece
     */
    private function overlapsOn(array $piece): int
    {
        $rects = [...$piece['placements'], ...$piece['banked'], ...$piece['scrap']];
        $overlaps = 0;

        foreach ($rects as $a => $first) {
            foreach ($rects as $b => $second) {
                if ($a >= $b) {
                    continue;
                }

                $apart = $first['x'] >= $second['x'] + $second['width']
                    || $first['x'] + $first['width'] <= $second['x']
                    || $first['y'] >= $second['y'] + $second['height']
                    || $first['y'] + $first['height'] <= $second['y'];

                if (! $apart) {
                    $overlaps++;
                }
            }
        }

        return $overlaps;
    }

    /**
     * @param  array<string, mixed>  $piece
     */
    private function outOfBoundsOn(array $piece): int
    {
        $outside = 0;

        foreach ([...$piece['placements'], ...$piece['banked'], ...$piece['scrap']] as $rect) {
            if ($rect['x'] < 0
                || $rect['y'] < 0
                || $rect['x'] + $rect['width'] > $piece['width']
                || $rect['y'] + $rect['height'] > $piece['height']) {
                $outside++;
            }
        }

        return $outside;
    }

    /**
     * Remnants racked or binned against the rule.
     *
     * Reached by a different route from the engine's own decision - this applies the written rule to the
     * finished rectangles, where the engine asked TwoDimensionalNestingCost::isBanked() as it went - so a
     * disagreement is a real finding. The 1D proof checks the same thing for the same reason: a ">" on one
     * side of that line and a ">=" on the other banks inventory for steel the plan scored as scrap.
     *
     * @param  array<string, mixed>  $piece
     */
    private function misjudgedOn(array $piece): int
    {
        $wrong = 0;

        foreach ($piece['banked'] as $rect) {
            if (! $this->worthKeeping($rect['width'], $rect['height'])) {
                $wrong++;
            }
        }

        foreach ($piece['scrap'] as $rect) {
            if ($this->worthKeeping($rect['width'], $rect['height'])) {
                $wrong++;
            }
        }

        return $wrong;
    }

    /** The rule as written on the page, spelled out rather than delegated - see misjudgedOn(). */
    private function worthKeeping(int $width, int $height): bool
    {
        return min($width, $height) >= self::MIN_OFFCUT_SIDE_MM
            && max($width, $height) >= self::MIN_OFFCUT_SIDE_MM
            && ($width * $height) >= self::MIN_OFFCUT_AREA_MM2;
    }

    /** A part is the same part whichever way up it was cut, so sizes compare sorted. */
    private function sizeKey(int $width, int $height): string
    {
        return min($width, $height).'x'.max($width, $height);
    }

    private function m2(int|float $mm2): string
    {
        return number_format($mm2 / 1_000_000, 3).'m2';
    }
}
