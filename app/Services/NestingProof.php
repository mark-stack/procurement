<?php

namespace App\Services;

use App\Formatters\NestingFormatter;
use App\Formatters\UniqueLetterIDGenerator;
use App\Models\Business;

/**
 * Three worked lifecycles, run through the real nesting algorithm, with everything a reader needs to
 * check the answer by hand.
 *
 * The admin "Nesting Algorithm" page explains what the cost model charges for. This one does the other
 * half: it shows the algorithm actually doing it, over several jobs in a row, and demonstrates that the
 * remnants it produces come back and get cut again - an offcut off a bar, an offcut off that offcut, and
 * an offcut off THAT. Offcuts-of-offcuts are the part of the lifecycle nothing else on the product draws,
 * because in the application they only ever appear one batch at a time.
 *
 * NOTHING HERE IS A RE-IMPLEMENTATION. Every plan on the page comes out of
 * NestingFormatter::meterageAlgorithm(), the same call BatchNestingController and SuggestedNestingController
 * make, and the two rules this class applies on top of it are the two Actions\Bar\CreateBarsAndOffcuts
 * applies when it writes the rows:
 *
 *   - a bar's drop becomes an offcut when it is at or over the scrap threshold
 *   - a drawn offcut's drop becomes an offcut-of-offcut when the algorithm reported a reusable length
 *
 * What is NOT real is the database. The rack is carried in memory from one job to the next rather than
 * written as Offcut rows, so the page can be opened on any installation, in any state, and answer the same
 * way. That is the whole point of it as proof: the scenarios are fixed, so the only thing that can change
 * an answer is a change to the algorithm.
 *
 * The checks each scenario returns are therefore real assertions about a real run, not decoration. If the
 * cost model stops conserving steel, or starts banking stubs under the threshold, the page says so in red
 * rather than quietly drawing something wrong.
 */
class NestingProof
{
    /**
     * The settings every scenario is run under.
     *
     * Fixed rather than read off the viewing admin's business, because a proof that moves when somebody
     * edits a coefficient proves nothing. They are the documented defaults, except for the kerf: the
     * column defaults to 0, and a nest with no kerf cannot show that the blade is accounted for.
     */
    public const SCRAP_THRESHOLD_MM = 1000;

    public const KERF_MM = 3;

    /** What this imaginary yard can buy, for every scenario. */
    public const STOCK_LENGTHS = [6000, 9000, 12000];

    /**
     * Cost coefficients, pinned for the same reason the settings above are.
     *
     * Only the ones the scenarios actually turn on are listed; everything else falls back to
     * NestingCostModel::DEFAULTS, which is where the documented values live.
     */
    private const COST_SETTINGS = [
        'labour_rate_per_hour' => 50.00,
        'material_cost_per_tonne' => 2000.00,
        'delivery_cost_per_tonne' => 150.00,
        'delivery_cost_per_order' => 0.00,
    ];

    /**
     * A business id for the seed only.
     *
     * NestingFormatter seeds its randomiser partly on the business id, so a null one would still be
     * deterministic - but it would tie these scenarios to "the business with no id", which is a detail of
     * how the page happens to build its model rather than something the proof should depend on.
     */
    private const SEED_BUSINESS_ID = 1;

    private readonly NestingFormatter $formatter;

    private readonly Business $business;

    /**
     * Every piece of steel the scenario being run has put on the rack, keyed by its mark.
     *
     * Held here rather than threaded through a dozen signatures by reference. It is scenario-scoped state:
     * run() clears it before each one, so the three never see each other's marks.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $ledger = [];

    /** Marks for the scenario being run, in a fixed order. Reset alongside the ledger. */
    private \Generator $marks;

    public function __construct()
    {
        $this->formatter = new NestingFormatter;
        $this->business = $this->business();
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
            'scrapThresholdMm' => self::SCRAP_THRESHOLD_MM,
            'kerfMm' => self::KERF_MM,
            'stockLengths' => self::STOCK_LENGTHS,
            'labourRatePerHour' => self::COST_SETTINGS['labour_rate_per_hour'],
            'materialCostPerTonne' => self::COST_SETTINGS['material_cost_per_tonne'],
            'deliveryCostPerTonne' => self::COST_SETTINGS['delivery_cost_per_tonne'],
            'nestingIterations' => (int) config('env.nesting_iterations'),
        ];
    }

    /**
     * The business every scenario is nested for.
     *
     * Never saved. Business carries the nesting defaults in $attributes, so an unsaved instance is a
     * complete, documented set of settings - which is exactly what a fixed scenario wants.
     */
    private function business(): Business
    {
        $business = new Business(array_merge(self::COST_SETTINGS, [
            'name' => 'Proof',
            'scrap_threshold_mm' => self::SCRAP_THRESHOLD_MM,
            'kerf_mm' => self::KERF_MM,
        ]));

        $business->id = self::SEED_BUSINESS_ID;

        return $business;
    }

    /**
     * The three scenarios, as inputs only.
     *
     * Each is a section, an opening rack, and a run of jobs that arrive one after another - which is what
     * a fabricator's week actually looks like, and the only way an offcut-of-offcut can be shown at all.
     * No outcome is written down here: every number the page shows is computed from these by run().
     *
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            /*
             * One bar, cut down over four jobs until there is nothing left of it worth keeping.
             *
             * This is the chain in its purest form, and the reason the jobs get smaller: each one is sized
             * to fit in what the one before it left behind, so the rack never holds more than one piece and
             * every decision is visible. The last job is deliberately a hair too big for a clean finish -
             * it takes the fourth generation down to 85mm, which is under the threshold, so the chain ends
             * by scrapping itself rather than by running out of jobs.
             */
            [
                'key' => 'generations',
                'title' => 'One bar, four generations',
                'claim' => 'A single 12,000mm bar covers four separate jobs. Each job cuts into what the one before it left on the rack, so the bar becomes an offcut, then an offcut of that offcut, then an offcut of THAT - and the chain stops itself when the last remnant falls under the scrap threshold.',
                'watch' => 'The mark in the "banked" block of one step is the piece drawn off the rack in the next.',
                'section' => ['label' => '150PFC', 'kgPerM' => 17.7],
                'projects' => [
                    1 => ['letter' => 'A', 'name' => 'Mezzanine beams'],
                    2 => ['letter' => 'B', 'name' => 'Handrail posts'],
                    3 => ['letter' => 'C', 'name' => 'Cleat stock'],
                    4 => ['letter' => 'D', 'name' => 'Brace ends'],
                ],
                'openingRack' => [],
                'jobs' => [
                    [
                        'name' => 'Nothing on the rack',
                        'note' => 'The yard is empty, so the whole job is bought. Two 4,500mm cuts will not fit a 9,000mm bar once the saw has taken its 3mm, so a 12,000mm bar is opened and 2,994mm is left over - comfortably over the threshold, so it is marked and racked rather than binned.',
                        'lines' => [['project' => 1, 'length' => 4500, 'qty' => 2]],
                    ],
                    [
                        'name' => 'A second job, weeks later',
                        'note' => 'One 1,200mm post. Buying a 6,000mm bar for it would mean freight, receiving, the paperwork of an order and 4,800mm of steel nobody asked for. The remnant on the rack covers it, and what is left of the remnant is still over the threshold - so the offcut produces an offcut.',
                        'lines' => [['project' => 2, 'length' => 1200, 'qty' => 1]],
                    ],
                    [
                        'name' => 'A third job',
                        'note' => 'A 600mm cleat. The rack now holds a second-generation piece, and it is drawn in turn. The 1,188mm left is still worth keeping, so a third generation goes back on the rack.',
                        'lines' => [['project' => 3, 'length' => 600, 'qty' => 1]],
                    ],
                    [
                        'name' => 'The last of it',
                        'note' => 'An 1,100mm brace end. It fits the third-generation piece almost exactly, and the 85mm left behind is far under the threshold - so nothing is marked, the 85mm is binned, and the rack is empty again. The chain ended because the steel ran out, not because anything counted generations.',
                        'lines' => [['project' => 4, 'length' => 1100, 'qty' => 1]],
                    ],
                ],
            ],

            /*
             * Two remnants on the rack and one cut that fits either: the choice the cost model exists to
             * make. Opening the shorter piece would bin what was left of it; opening the longer one keeps
             * a usable length. The nest is scored in dollars, so it can weigh "a stub retired for good"
             * against "a long piece cut into", and here the long piece wins and becomes the second
             * generation.
             */
            [
                'key' => 'rack',
                'title' => 'Two on the rack, and the right one opens',
                'claim' => 'With two remnants available and one cut that fits either, the algorithm does not take the first that fits, or the tightest. It prices both and opens the one that leaves the yard better off - then does it again a job later with what that left behind.',
                'watch' => 'Step 3 has a genuine choice. Both pieces on the rack hold a 700mm cut; only one of them is still worth something afterwards.',
                'section' => ['label' => '65x65x6 EA', 'kgPerM' => 5.93],
                'projects' => [
                    1 => ['letter' => 'A', 'name' => 'Platform framing'],
                    2 => ['letter' => 'B', 'name' => 'Gantry rail'],
                    3 => ['letter' => 'C', 'name' => 'Kickplate clips'],
                    4 => ['letter' => 'D', 'name' => 'Ladder rungs'],
                    5 => ['letter' => 'E', 'name' => 'Walkway stringer'],
                ],
                'openingRack' => [],
                'jobs' => [
                    [
                        'name' => 'Framing, from new steel',
                        'note' => 'Four 2,600mm lengths. A 12,000mm bar holds all four and leaves 1,588mm, which clears the threshold and goes on the rack.',
                        'lines' => [['project' => 1, 'length' => 2600, 'qty' => 4]],
                    ],
                    [
                        'name' => 'One long cut',
                        'note' => 'A single 6,500mm rail. Nothing on the rack is anywhere near long enough, so a bar is bought - the 9,000mm, because a 12,000mm would only mean buying 3,000mm more to leave on the floor. The 2,497mm drop is racked, and the yard now holds two remnants of very different value.',
                        'lines' => [['project' => 2, 'length' => 6500, 'qty' => 1]],
                    ],
                    [
                        'name' => 'The choice',
                        'note' => 'One 700mm clip. It fits both pieces on the rack. Taking it out of the 1,588mm would leave 885mm - under the threshold, so that piece would be destroyed to make one small clip. Taking it out of the 2,497mm leaves 1,794mm, which is still a useful length. The second is cheaper, and it is what gets opened.',
                        'lines' => [['project' => 3, 'length' => 700, 'qty' => 1]],
                    ],
                    [
                        'name' => 'And again',
                        'note' => 'A 500mm rung. The rack holds the untouched 1,588mm and the second-generation 1,794mm. Both still clear the threshold after the cut, so this time the cheaper draw is the shorter piece - there is no rule about generations, only about what each decision costs.',
                        'lines' => [['project' => 4, 'length' => 500, 'qty' => 1]],
                    ],
                    [
                        'name' => 'Cutting an offcut of an offcut',
                        'note' => 'A 1,700mm stringer. Only one piece in the yard is long enough, and it is a second-generation remnant - steel that was already a drop off a drop. It is cut like anything else, and the 91mm behind it is binned.',
                        'lines' => [['project' => 5, 'length' => 1700, 'qty' => 1]],
                    ],
                ],
            ],

            /*
             * The busy case: several projects nested together, an inherited rack, and heavy steel.
             *
             * Its job is the bookkeeping. Cuts for three projects share one bar, two inherited remnants are
             * consumed in the same step, and every cut still has to come back out attributed to the project
             * that asked for it - which is what the letters on the drawings are, and what a batch's cut
             * list is read off in the workshop.
             */
            [
                'key' => 'mixed',
                'title' => 'Four projects, one rack, heavy steel',
                'claim' => 'A real week: several projects nested together, remnants inherited from jobs that are long finished, and a section where steel is expensive enough that the model works hard to avoid destroying any. Every cut still comes back out of the nest tagged with the project that asked for it.',
                'watch' => 'Step 1 puts three projects through one bar and empties the inherited rack in the same pass. Follow the letters.',
                'section' => ['label' => '310UB46', 'kgPerM' => 46.2],
                'projects' => [
                    1 => ['letter' => 'A', 'name' => 'Warehouse portal'],
                    2 => ['letter' => 'B', 'name' => 'Mezzanine floor'],
                    3 => ['letter' => 'C', 'name' => 'Canopy'],
                    4 => ['letter' => 'D', 'name' => 'Plant deck'],
                ],
                /*
                 * Two pieces left by jobs before this scenario starts. Every yard has them, and a proof
                 * that only ever starts from an empty rack would never show one being inherited.
                 */
                'openingRack' => [2850, 1640],
                'jobs' => [
                    [
                        'name' => 'Three projects at once',
                        'note' => 'Two portal columns, two floor beams and a canopy rafter, nested together. One 9,000mm bar takes three of the five cuts; the other two come off the rack, emptying it. Both inherited pieces are consumed down below the threshold, which retires two marks for good.',
                        'lines' => [
                            ['project' => 1, 'length' => 3200, 'qty' => 2],
                            ['project' => 2, 'length' => 2400, 'qty' => 2],
                            ['project' => 3, 'length' => 1500, 'qty' => 1],
                        ],
                    ],
                    [
                        'name' => 'Two more projects',
                        'note' => 'Floor beams and deck rungs together. The rack is empty, so one 6,000mm bar carries all four cuts and leaves 1,588mm to be marked and racked.',
                        'lines' => [
                            ['project' => 2, 'length' => 1300, 'qty' => 2],
                            ['project' => 4, 'length' => 900, 'qty' => 2],
                        ],
                    ],
                    [
                        'name' => 'A short one',
                        'note' => 'One 500mm rung off the remnant. What is left still clears the threshold, so an offcut-of-offcut goes back on the rack rather than in the bin.',
                        'lines' => [['project' => 4, 'length' => 500, 'qty' => 1]],
                    ],
                    [
                        'name' => 'Finishing the canopy',
                        'note' => 'A 1,000mm canopy piece, out of the second-generation remnant. The 82mm left is swarf and a stub; both are written off, and the rack is empty again.',
                        'lines' => [['project' => 3, 'length' => 1000, 'qty' => 1]],
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
        $letters = array_map(fn (array $project) => $project['letter'], $definition['projects']);
        $spec = (object) ['kg_per_m' => $definition['section']['kgPerM']];

        //Each scenario starts with an empty ledger and its own run of marks
        $this->ledger = [];
        $this->marks = $this->markSequence();

        $rack = [];
        foreach ($definition['openingRack'] as $length) {
            $rack[] = $this->bank((int) $length, generation: 1, parentMark: null, step: 0);
        }

        $openingRackMm = array_sum($definition['openingRack']);

        //Piece ids are what the nest matches a drawn cut back to, so they have to be unique across the run
        $pieceId = 1;
        $steps = [];

        foreach ($definition['jobs'] as $index => $job) {
            $required = [];
            foreach ($job['lines'] as $line) {
                for ($i = 0; $i < $line['qty']; $i++) {
                    $required[] = [
                        'length' => $line['length'],
                        'project' => $line['project'],
                        'piece_id' => $pieceId++,
                    ];
                }
            }

            $rackBefore = $rack;

            //The real thing, on the same call the two nesting screens make
            $result = $this->formatter->meterageAlgorithm(
                $required,
                self::STOCK_LENGTHS,
                $rack,
                $letters,
                $this->business,
                $spec,
            );

            [$bars, $draws, $rack] = $this->applyToRack($result, $rack, $index + 1);

            $steps[] = [
                'number' => $index + 1,
                'name' => $job['name'],
                'note' => $job['note'],
                'required' => $this->requiredLines($job['lines'], $definition['projects']),
                'rackBefore' => $this->rackView($rackBefore),
                'rackAfter' => $this->rackView($rack),
                'bars' => $bars,
                'draws' => $draws,
                'unmadeCuts' => count($result['tooLong']),
                'cost' => round($result['cost'], 2),
                'effectiveEfficiency' => $result['effectiveEfficiency'],
                'purchasedMm' => $result['totals']['newStock']['total'],
                'drawnMm' => $result['totals']['oldStock']['total'],
                /*
                 * What the NEST counted as reusable and as destroyed, kept beside what this page actually
                 * racked and binned. The two are reached by different routes - the algorithm splits its own
                 * totals in summariseBars() and bestResultOffcuts(), while the page applies the test
                 * Actions\Bar\CreateBarsAndOffcuts applies when it writes rows - so a check that they agree
                 * is a real one. It is the disagreement that has bitten before: a ">" on one side of that
                 * line and a ">=" on the other banks an offcut record for steel the nest scored as scrap.
                 */
                'nestReusableMm' => (int) $result['totals']['newStock']['reusable'] + (int) $result['totals']['oldStock']['reusable'],
                'nestScrapMm' => (int) $result['totals']['newStock']['scrap'] + (int) $result['totals']['oldStock']['scrap'],
            ];
        }

        return [
            'key' => $definition['key'],
            'title' => $definition['title'],
            'claim' => $definition['claim'],
            'watch' => $definition['watch'],
            'section' => $definition['section'],
            'projects' => array_values(array_map(
                fn (array $project) => ['letter' => $project['letter'], 'name' => $project['name']],
                $definition['projects'],
            )),
            'steps' => $steps,
            'lineage' => $this->lineage(),
            'checks' => $this->checks($definition, $steps, $openingRackMm),
        ];
    }

    /**
     * Turn one nest into the bars and draws the page shows, and move the rack on.
     *
     * The two tests here - a bar's drop at or over the threshold, a drawn offcut reporting a reusable
     * length - are the two Actions\Bar\CreateBarsAndOffcuts applies when it decides which offcuts become
     * records. They have to stay the same tests, or the page would draw a rack the application would never
     * have written.
     *
     * @param  array<string, mixed>  $result
     * @param  array<int, array<string, mixed>>  $rack
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>}
     */
    private function applyToRack(array $result, array $rack, int $step): array
    {
        $bars = [];

        foreach ($result['utilisedBars'] as $consolidated) {
            /*
             * Expanded back out, one row per physical bar. Identical bars are consolidated into a "3 off"
             * row for ordering, but each of them is cut for real and each leaves its own offcut under its
             * own mark - see VisualNestingWithBars, which splits them for the same reason.
             */
            for ($i = 0; $i < $consolidated['count']; $i++) {
                $bar = $consolidated['result'];
                $drop = (int) $bar['unused'];
                $banked = $drop >= self::SCRAP_THRESHOLD_MM;

                $mark = null;
                if ($banked) {
                    $offcut = $this->bank($drop, generation: 1, parentMark: null, step: $step);
                    $rack[] = $offcut;
                    $mark = $offcut['unique_mark'];
                }

                $bars[] = $this->piece(
                    length: (int) $bar['bar_length'],
                    cuts: array_map(
                        fn (array $cut) => ['length' => (int) $cut['cutLength'], 'letter' => $cut['letter']],
                        $bar['pieces'],
                    ),
                    kerf: (int) $bar['kerf'],
                    drop: $drop,
                    banked: $banked,
                    bankedMark: $mark,
                );
            }
        }

        $draws = [];

        foreach ($result['bestResultOffcuts']['utilisedOffcutBars'] as $drawn) {
            $source = $drawn['sourceOffcut'];
            $sourceMark = $source['unique_mark'];
            $generation = (int) $this->ledger[$sourceMark]['generation'];

            $reusable = (int) $drawn['offcutFromOffcut']['reusableLength'];
            $banked = $reusable > 0;

            //Off the rack, whatever becomes of it
            $rack = array_values(array_filter(
                $rack,
                fn (array $piece) => $piece['id'] !== $source['offcutId'],
            ));

            $this->ledger[$sourceMark]['consumedInStep'] = $step;

            $childMark = null;
            if ($banked) {
                $child = $this->bank($reusable, $generation + 1, $sourceMark, $step);
                $rack[] = $child;
                $childMark = $child['unique_mark'];
            }

            $draws[] = $this->piece(
                length: (int) $source['offcutLength'],
                cuts: array_map(
                    fn (array $cut) => ['length' => (int) $cut['length'], 'letter' => $cut['letter']],
                    $source['cuts'],
                ),
                kerf: (int) $source['kerfLength'],
                drop: $reusable > 0 ? $reusable : (int) $drawn['scrap']['scrapLength'],
                banked: $banked,
                bankedMark: $childMark,
            ) + [
                'mark' => $sourceMark,
                'generation' => $generation,
                'childGeneration' => $banked ? $generation + 1 : null,
            ];
        }

        return [$bars, $draws, $rack];
    }

    /**
     * One bar or one drawn offcut, as the page draws it.
     *
     * Segments are percentages of the whole, so the drawing is to scale. The kerf is NOT a segment: at 3mm
     * in 12,000 it is a quarter of a pixel, and drawing it to scale would be drawing nothing. It is a line
     * between cuts and a number in the caption instead, which is also how the live nesting screens show it.
     *
     * @param  array<int, array{length: int, letter: string}>  $cuts
     * @return array<string, mixed>
     */
    private function piece(int $length, array $cuts, int $kerf, int $drop, bool $banked, ?string $bankedMark): array
    {
        $segments = [];

        foreach ($cuts as $cut) {
            $segments[] = [
                'kind' => 'cut',
                'lengthMm' => $cut['length'],
                'letter' => $cut['letter'],
                'pct' => round($cut['length'] / $length * 100, 4),
            ];
        }

        if ($drop > 0) {
            $segments[] = [
                'kind' => $banked ? 'banked' : 'scrap',
                'lengthMm' => $drop,
                'letter' => null,
                'pct' => round($drop / $length * 100, 4),
            ];
        }

        $cutMm = array_sum(array_column($cuts, 'length'));

        return [
            'lengthMm' => $length,
            'cuts' => $cuts,
            'cutMm' => $cutMm,
            'kerfMm' => $kerf,
            'dropMm' => $drop,
            'banked' => $banked,
            'bankedMark' => $bankedMark,
            'segments' => $segments,
            //Stated so a reader can add it up without reaching for a calculator
            'balance' => [
                'parts' => $cutMm,
                'kerf' => $kerf,
                'drop' => $drop,
                'total' => $cutMm + $kerf + $drop,
                'ok' => $cutMm + $kerf + $drop === $length,
            ],
        ];
    }

    /**
     * Put one new piece of steel on the rack and write it into the ledger.
     *
     * @return array<string, mixed>
     */
    private function bank(int $length, int $generation, ?string $parentMark, int $step): array
    {
        $mark = $this->marks->current();
        $this->marks->next();

        $this->ledger[$mark] = [
            'mark' => $mark,
            'lengthMm' => $length,
            'generation' => $generation,
            'parentMark' => $parentMark,
            'bornInStep' => $step,
            'consumedInStep' => null,
        ];

        /*
         * Shaped as the nest reads an offcut: NestingFormatter::openOffcut() takes id, unique_mark,
         * batch_from_id and length off these rows, and sortOffcutInventory() orders on length and id. The
         * extra keys travel along unread, which is what lets the page keep its own bookkeeping on the same
         * row the algorithm is handed.
         */
        return [
            'id' => count($this->ledger),
            'unique_mark' => $mark,
            'length' => $length,
            'batch_from_id' => $step,
            'generation' => $generation,
        ];
    }

    /**
     * Marks, in a fixed order.
     *
     * UniqueLetterIDGenerator::generate() reads the offcuts table and rolls at random, neither of which a
     * fixed scenario can use. codeFromIndex() is the same alphabet and the same code shape, counted rather
     * than drawn - so the marks look exactly like the ones a real nest stamps, and are the same on every
     * installation.
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
     * The rack as the page lists it, newest piece last.
     *
     * @param  array<int, array<string, mixed>>  $rack
     * @return array<int, array<string, mixed>>
     */
    private function rackView(array $rack): array
    {
        return array_map(fn (array $piece) => [
            'mark' => $piece['unique_mark'],
            'lengthMm' => $piece['length'],
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
            'lengthMm' => $line['length'],
            'qty' => $line['qty'],
            'letter' => $projects[$line['project']]['letter'],
            'project' => $projects[$line['project']]['name'],
        ], $lines);
    }

    /**
     * Every piece of steel the scenario put on the rack, and what became of it.
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
     * These are the proof. Everything else on the page is a drawing of a plan; these say whether the plan
     * holds together, and they are computed from the run rather than written down beside it - so a change
     * to the algorithm that breaks one turns the page red instead of silently redrawing.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function checks(array $definition, array $steps, int $openingRackMm): array
    {
        //What was asked for, and what came off the saw, as two sorted lists of lengths
        $asked = [];
        foreach ($definition['jobs'] as $job) {
            foreach ($job['lines'] as $line) {
                for ($i = 0; $i < $line['qty']; $i++) {
                    $asked[] = (int) $line['length'];
                }
            }
        }

        $made = [];
        $boughtMm = 0;
        $partsMm = 0;
        $kerfMm = 0;
        $scrapMm = 0;
        $unbalanced = 0;
        $misthresholded = 0;
        $unmade = 0;
        $bankedMm = 0;
        $nestReusableMm = 0;
        $nestScrapMm = 0;

        foreach ($steps as $step) {
            $unmade += (int) $step['unmadeCuts'];
            $nestReusableMm += (int) $step['nestReusableMm'];
            $nestScrapMm += (int) $step['nestScrapMm'];

            foreach ($step['bars'] as $bar) {
                $boughtMm += (int) $bar['lengthMm'];
            }

            //Bars and drawn offcuts are tallied identically - both are a length of steel that gave up cuts
            foreach ([...$step['bars'], ...$step['draws']] as $piece) {
                $tally = $this->tally($piece);

                $made = [...$made, ...$tally['made']];
                $partsMm += $tally['parts'];
                $kerfMm += $tally['kerf'];
                $scrapMm += $tally['scrap'];
                $bankedMm += $tally['banked'];
                $unbalanced += $tally['unbalanced'];
                $misthresholded += $tally['misthresholded'];
            }
        }

        sort($asked);
        sort($made);

        $closingRackMm = 0;
        $deepest = 0;
        foreach ($this->ledger as $entry) {
            $deepest = max($deepest, (int) $entry['generation']);

            if ($entry['consumedInStep'] === null) {
                $closingRackMm += (int) $entry['lengthMm'];
            }
        }

        $steelIn = $boughtMm + $openingRackMm;
        $steelOut = $partsMm + $kerfMm + $scrapMm + $closingRackMm;

        return [
            [
                'label' => 'Every cut the jobs asked for was made',
                'passed' => $asked === $made && $unmade === 0,
                'detail' => count($asked).' cuts asked for, '.count($made).' cut, none unplaceable.',
            ],
            [
                'label' => 'Every bar and every offcut balances',
                'passed' => $unbalanced === 0,
                'detail' => 'Parts + saw kerf + what is left equals the length of the piece it came off, on all '
                    .$this->pieceCount($steps).' of them.',
            ],
            [
                'label' => 'No steel appeared or vanished across the whole run',
                'passed' => $steelIn === $steelOut,
                'detail' => number_format($boughtMm).'mm bought + '.number_format($openingRackMm).'mm inherited = '
                    .number_format($partsMm).'mm of parts + '.number_format($kerfMm).'mm of kerf + '
                    .number_format($scrapMm).'mm binned + '.number_format($closingRackMm).'mm still racked.',
            ],
            [
                'label' => 'The scrap threshold was applied to every remnant',
                'passed' => $misthresholded === 0,
                'detail' => 'Nothing under '.number_format(self::SCRAP_THRESHOLD_MM).'mm was marked and racked; nothing at or over it was binned.',
            ],
            [
                /*
                 * The one check that compares two independent answers rather than restating one. See the
                 * note on nestReusableMm in run().
                 */
                'label' => 'What was racked is exactly what the nest scored as reusable',
                'passed' => $bankedMm === $nestReusableMm && $scrapMm === $nestScrapMm,
                'detail' => 'The nest costed '.number_format($nestReusableMm).'mm as reusable and '
                    .number_format($nestScrapMm).'mm as destroyed; the yard racked '.number_format($bankedMm)
                    .'mm and binned '.number_format($scrapMm).'mm.',
            ],
            [
                'label' => 'An offcut cut from an offcut was itself cut again',
                'passed' => $deepest >= 2,
                'detail' => $deepest >= 2
                    ? 'The chain reached generation '.$deepest.': '.$this->deepestChain($deepest).'.'
                    : 'No offcut-of-offcut was produced.',
            ],
        ];
    }

    /**
     * What one bar or one drawn offcut contributes to the checks.
     *
     * Returned rather than accumulated through a row of by-reference counters: there are six of them, they
     * are all ints, and a caller that passes them in the wrong order gets a proof that quietly measures the
     * wrong thing.
     *
     * @param  array<string, mixed>  $piece
     * @return array{made: array<int, int>, parts: int, kerf: int, scrap: int, banked: int, unbalanced: int, misthresholded: int}
     */
    private function tally(array $piece): array
    {
        $drop = max(0, (int) $piece['dropMm']);
        $banked = (bool) $piece['banked'];

        //Banked under the threshold, or binned at or over it: either way the threshold was not applied
        $misthresholded = $drop > 0 && $banked === ($drop < self::SCRAP_THRESHOLD_MM);

        return [
            'made' => array_map(fn (array $cut) => (int) $cut['length'], $piece['cuts']),
            'parts' => (int) $piece['cutMm'],
            'kerf' => (int) $piece['kerfMm'],
            'scrap' => $banked ? 0 : $drop,
            'banked' => $banked ? $drop : 0,
            'unbalanced' => $piece['balance']['ok'] ? 0 : 1,
            'misthresholded' => $misthresholded ? 1 : 0,
        ];
    }

    /**
     * How many bars and drawn offcuts the scenario produced, for the balance check to name.
     *
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function pieceCount(array $steps): int
    {
        $count = 0;

        foreach ($steps as $step) {
            $count += count($step['bars']) + count($step['draws']);
        }

        return $count;
    }

    /**
     * The marks of the deepest chain, oldest first, as "ABC → ABD → ABE".
     */
    private function deepestChain(int $deepest): string
    {
        $chain = [];

        foreach ($this->ledger as $entry) {
            if ($entry['generation'] !== $deepest) {
                continue;
            }

            while ($entry !== null) {
                array_unshift($chain, $entry['mark']);
                $entry = $entry['parentMark'] === null ? null : $this->ledger[$entry['parentMark']];
            }

            break;
        }

        return implode(' → ', $chain);
    }
}
