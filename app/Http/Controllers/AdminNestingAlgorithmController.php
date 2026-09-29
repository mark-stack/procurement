<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\NestingCostModel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminNestingAlgorithmController extends Controller
{
    /**
     * A reference stock length to draw the retention curve against.
     *
     * The curve's shape depends on what the nest could have bought, which varies per product. 12,000mm is
     * the cap most businesses buy to, so it is what the page explains the curve with - the page says so.
     */
    private const REFERENCE_LENGTH_MM = 12000;

    /**
     * Sections the worked examples are costed on.
     *
     * The whole point of pricing in dollars is that light and heavy steel answer the same question
     * differently, so the examples have to show both.
     */
    private const LIGHT_KG_PER_M = 5.7;

    private const HEAVY_KG_PER_M = 90.0;

    /**
     * A spread of real sections, for the labour table.
     *
     * Chosen to span the range a fabricator actually buys: light angle where a remnant is worth almost
     * nothing against the labour of keeping it, through to a heavy beam where it is worth a great deal.
     */
    private const SECTIONS = [
        '65x65x6 EA' => 5.7,
        '150PFC' => 17.7,
        '200PFC' => 22.9,
        '500UB' => 90.0,
    ];

    /**
     * Explain how nesting chooses a plan, with this business's own settings and worked examples.
     *
     * Everything on the page is computed by the real NestingCostModel rather than written out alongside
     * it, so it cannot drift from what nesting actually does: change a coefficient and the examples,
     * the curve and the invariant check all move with it.
     */
    public function __invoke(Request $request, ?Business $business = null): Response
    {
        /*
         * The settings are per business, so the page needs one to explain. Falling back to a fresh model
         * rather than erroring means the route still answers for an admin whose own account has no
         * business attached - and a new Business carries the documented defaults, which is exactly what
         * a page about the defaults wants to show.
         *
         * instanceof rather than ?? alone: the relation is typed as a bare Model, so without narrowing it
         * here every method below takes one on trust.
         */
        if (! $business instanceof Business) {
            $ownBusiness = $request->user()?->business;

            $business = $ownBusiness instanceof Business ? $ownBusiness : new Business();
        }

        return Inertia::render('AdminNestingAlgorithm', [
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
                'isUnsaved' => ! $business->exists,
            ],
            'settings' => $this->settings($business),
            'referenceLengthMm' => self::REFERENCE_LENGTH_MM,
            /*
             * Per section, because the dollar figures are what the page is for and they move a long way
             * with the section. The table under the chart comes with each curve so the two cannot
             * disagree about which section is being shown.
             */
            'sectionCurves' => $this->sectionCurves($business),
            'labourBySection' => $this->labourBySection($business),
            'workedExamples' => $this->workedExamples($business),
            'invariant' => $this->invariant($business),
            'sections' => [
                'light' => self::LIGHT_KG_PER_M,
                'heavy' => self::HEAVY_KG_PER_M,
            ],
        ]);
    }

    /**
     * The dials, with what each one actually buys.
     *
     * @return array<int, array<string, mixed>>
     */
    private function settings(Business $business): array
    {
        return [
            [
                'key' => 'scrap_threshold_mm',
                'label' => 'Scrap threshold',
                'value' => (int) $business->scrap_threshold_mm,
                'unit' => 'mm',
                'blurb' => 'An offcut shorter than this is destroyed. At or over it, the offcut is banked and gets a mark. This is the only setting that changes what physically happens in the yard - everything below only changes which plan is chosen.',
            ],
            [
                'key' => 'kerf_mm',
                'label' => 'Saw kerf',
                'value' => (int) $business->kerf_mm,
                'unit' => 'mm',
                'blurb' => 'What the blade turns to swarf on every cut. Band saw around 2mm, cold saw around 3mm, plasma wider. At 0 the nest assumes cuts take no material, and the last piece off a tightly packed bar comes up undersize.',
            ],
            [
                'key' => 'labour_rate_per_hour',
                'label' => 'Labour rate',
                'value' => (float) $business->labour_rate_per_hour,
                'unit' => '$/hour',
                'blurb' => 'What an hour on the shop floor costs, all in. Every handling figure below is a duration, and this is what turns it into money so it can be weighed against steel.',
            ],
            [
                'key' => 'material_cost_per_tonne',
                'label' => 'Steel price',
                'value' => (float) $business->material_cost_per_tonne,
                'unit' => '$/tonne',
                'blurb' => 'Delivered price. Everything the nest does to a millimetre of steel is priced through this and the section\'s mass per metre.',
            ],
            [
                'key' => 'scrap_recovery_rate',
                'label' => 'Scrap recovery',
                'value' => (float) $business->scrap_recovery_rate,
                'unit' => 'of new price',
                'blurb' => 'What the bin pays back on steel that is cut off and binned. Applies to solid offcuts only - saw kerf leaves as swarf mixed with coolant and whatever else was cut that day, which is not what a merchant weighs in, so kerf earns nothing.',
            ],
            [
                'key' => 'default_kg_per_m',
                'label' => 'Fallback mass per metre',
                'value' => (float) $business->default_kg_per_m,
                'unit' => 'kg/m',
                'blurb' => 'Used only when the product carries no kg/m of its own. Mass drives both what the steel is worth and how long it takes to handle, so without it the nest cannot tell light angle from a heavy beam on either count.',
            ],
            [
                'key' => 'cut_base_minutes',
                'label' => 'Saw cut, base',
                'value' => (float) $business->cut_base_minutes,
                'unit' => 'min',
                'blurb' => 'Setup and marking off for one cut, before the section itself is counted.',
            ],
            [
                'key' => 'cut_minutes_per_kg_per_m',
                'label' => 'Saw cut, per kg/m',
                'value' => (float) $business->cut_minutes_per_kg_per_m,
                'unit' => 'min per kg/m',
                'blurb' => 'Blade time, scaled by how much section it has to get through. A cut is a cut whatever the length of the piece, but a 500UB web is not a 65mm angle.',
            ],
            [
                'key' => 'offcut_draw_base_minutes',
                'label' => 'Fetch an offcut, base',
                'value' => (float) $business->offcut_draw_base_minutes,
                'unit' => 'min',
                'blurb' => 'Finding one offcut in the rack and reading its mark. Higher than taking a new bar off the lift, because a new bar does not have to be found or trusted first.',
            ],
            [
                'key' => 'bar_handling_base_minutes',
                'label' => 'Fetch a new bar, base',
                'value' => (float) $business->bar_handling_base_minutes,
                'unit' => 'min',
                'blurb' => 'Taking one newly bought bar off the lift and getting it to the saw.',
            ],
            [
                'key' => 'offcut_rack_base_minutes',
                'label' => 'Keep an offcut, base',
                'value' => (float) $business->offcut_rack_base_minutes,
                'unit' => 'min',
                'blurb' => 'The labour one more piece on the rack will cost over its life: writing the mark, recording it, and moving it about before it is finally used or scrapped. The only entry here that is not a single physical operation, and the one that stops the yard filling with stubs.',
            ],
            [
                'key' => 'move_minutes_per_tonne',
                'label' => 'Moving, per tonne',
                'value' => (float) $business->move_minutes_per_tonne,
                'unit' => 'min/tonne',
                'blurb' => 'Added to every move, by the mass of the piece being moved. A 6m angle is carried by one person; a 12m 500UB at over a tonne is a crane, slings and a second pair of hands.',
            ],
            [
                'key' => 'offcut_retention_cap',
                'label' => 'Retention cap',
                'value' => (float) $business->offcut_retention_cap,
                'unit' => null,
                'blurb' => 'The most of its value an offcut can keep by going on the rack, reached when the offcut is as long as a full stock length. Well under 1.0 on purpose: an offcut is deferred value that has to be found, verified and trusted again.',
            ],
            [
                'key' => 'purchase_cost_weight',
                'label' => 'Purchase weight',
                'value' => (float) $business->purchase_cost_weight,
                'unit' => null,
                'blurb' => 'How heavily steel actually bought counts against a plan. At 1.0 a dollar of purchase costs a dollar. Raise it to buy leaner still.',
            ],
        ];
    }

    /**
     * What the labour actually comes to, per section, and the length at which a remnant stops paying for
     * the labour of keeping it.
     *
     * This is the table that answers the question the model exists for. Going to lengths over $9 of equal
     * angle is never worth it; a beam worth thousands can carry a good deal of extra handling first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function labourBySection(Business $business): array
    {
        $rows = [];

        foreach (self::SECTIONS as $label => $kgPerM) {
            $model = new NestingCostModel($business, $kgPerM, self::REFERENCE_LENGTH_MM);

            //A full stock length of this section, as the piece being moved
            $barKg = $model->mmToKg(self::REFERENCE_LENGTH_MM);
            $floor = $model->worthRackingFromMm();

            $rows[] = [
                'label' => $label,
                'kgPerM' => $kgPerM,
                'costPerMetre' => round($model->mmToCost(1000), 2),
                'scrapIncomePerMetre' => round($model->scrapIncome(1000), 2),
                'barCost' => round($model->mmToCost(self::REFERENCE_LENGTH_MM), 2),
                'barKg' => round($barKg),
                'cutMinutes' => round($model->cutMinutes(), 1),
                'cutCost' => round($model->minutesToCost($model->cutMinutes()), 2),
                'drawMinutes' => round($model->offcutDrawMinutes(self::REFERENCE_LENGTH_MM), 1),
                'drawCost' => round($model->minutesToCost($model->offcutDrawMinutes(self::REFERENCE_LENGTH_MM)), 2),
                'barMinutes' => round($model->barHandlingMinutes(self::REFERENCE_LENGTH_MM), 1),
                'barHandlingCost' => round($model->minutesToCost($model->barHandlingMinutes(self::REFERENCE_LENGTH_MM)), 2),
                //Null means no offcut of this section ever pays for its own keep
                'worthRackingFromMm' => $floor,
                'worthRackingValue' => $floor === null ? null : round($model->mmToCost($floor), 2),
            ];
        }

        return $rows;
    }

    /**
     * One curve per section: what an offcut of each length is worth, and what keeping it will cost.
     *
     * Sampled from the model rather than reimplemented in the page, so the curve drawn is the curve used.
     * Each curve starts below the scrap threshold, so the step up out of scrap is visible.
     *
     * Both series are dollars on one axis. The point where they cross is the length at which a remnant
     * starts paying for its own keep, and it moves a long way with the section - which is the whole reason
     * the page lets you pick one. Retention itself is section-independent (it is a share of value, not an
     * amount), so it is carried on each point for the table rather than plotted.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sectionCurves(Business $business): array
    {
        $threshold = (int) $business->scrap_threshold_mm;

        //A little before the threshold, so the jump out of scrap is visible
        $from = max(0, $threshold - 500);
        $steps = 90;
        $span = self::REFERENCE_LENGTH_MM - $from;

        $curves = [];

        foreach (self::SECTIONS as $label => $kgPerM) {
            $model = new NestingCostModel($business, $kgPerM, self::REFERENCE_LENGTH_MM);

            $points = [];
            for ($i = 0; $i <= $steps; $i++) {
                $length = (int) round($from + ($span * $i / $steps));
                $banked = $model->isBanked($length);

                $points[] = [
                    'lengthMm' => $length,
                    'retention' => round($model->retention($length), 4),
                    'valueMm' => round($model->inventoryValueMm($length), 1),
                    'banked' => $banked,
                    //What the offcut retains as inventory
                    'worth' => round($model->mmToCost($model->inventoryValueMm($length)), 2),
                    //What it will cost in labour over its life on the rack. Nothing below the threshold,
                    //because an offcut that short never goes on the rack at all
                    'keepCost' => $banked
                        ? round($model->minutesToCost($model->offcutRackMinutes($length)), 2)
                        : 0.0,
                ];
            }

            $floor = $model->worthRackingFromMm();

            $curves[] = [
                'label' => $label,
                'kgPerM' => $kgPerM,
                'costPerMetre' => round($model->mmToCost(1000), 2),
                'points' => $points,
                //Where the two series cross, so the page can mark it rather than guess at it
                'worthRackingFromMm' => $floor,
                'worthRackingValue' => $floor === null ? null : round($model->mmToCost($floor), 2),
                'rows' => $this->dropTable($business, $model),
            ];
        }

        return $curves;
    }

    /**
     * The curve at lengths worth naming, as the table view of the chart.
     *
     * The pair either side of the threshold is the one to look at: it is where the old ranking had a
     * cliff, and where a thousand search iterations were being spent landing just past it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dropTable(Business $business, NestingCostModel $model): array
    {
        $threshold = (int) $business->scrap_threshold_mm;

        $lengths = [
            $threshold - 1 => 'Just under the threshold - destroyed',
            $threshold + 1 => 'Just over it - banked, and worth almost nothing',
            2500 => 'A short offcut',
            4000 => 'A usable length',
            6000 => 'Half a long bar',
            self::REFERENCE_LENGTH_MM => 'As good as stock',
        ];

        $rows = [];
        foreach ($lengths as $length => $note) {
            $length = (int) $length;

            if ($length < 0) {
                continue;
            }

            /*
             * The two figures side by side are the decision: what the remnant retains, against the labour
             * it will cost over its life. A row where the labour is the larger of the two is a remnant not
             * worth producing.
             */
            $rows[] = [
                'lengthMm' => $length,
                'banked' => $model->isBanked($length),
                'retentionPct' => round($model->retention($length) * 100, 1),
                'valueMm' => round($model->inventoryValueMm($length)),
                'worth' => round($model->mmToCost($model->inventoryValueMm($length)), 2),
                'keepCost' => $model->isBanked($length)
                    ? round($model->minutesToCost($model->offcutRackMinutes($length)), 2)
                    : null,
                //What binning it instead would cost, after the scrap bin pays its share back
                'binCost' => round($model->netScrapCost($length), 2),
                'scrapIncome' => round($model->scrapIncome($length), 2),
                'note' => $note,
            ];
        }

        return $rows;
    }

    /**
     * Decisions costed both ways, on a light and a heavy section.
     *
     * These are the cases the model exists to get right, and the pair of answers is the argument for
     * pricing in dollars: same settings, opposite conclusions, because what the steel in a piece is worth
     * rises far faster with the section than the labour of dealing with it does.
     *
     * @return array<int, array<string, mixed>>
     */
    private function workedExamples(Business $business): array
    {
        $threshold = (int) $business->scrap_threshold_mm;

        return [
            [
                'title' => 'A 700mm cut, with a 1,500mm stub and a 12,000mm offcut on the rack',
                'question' => 'Retire the stub, or nibble the long length?',
                'options' => [
                    $this->costOption(
                        $business,
                        'Cut the 1,500mm stub',
                        'Bins the 800mm left over, and the stub is gone for good. The 12,000mm stays whole.',
                        ['offcutDraws' => [['source' => 1500, 'drop' => 0]], 'scrapMm' => 800, 'cuts' => 1],
                    ),
                    $this->costOption(
                        $business,
                        'Cut the 12,000mm offcut',
                        'Destroys nothing and banks 11,300mm. The rack still holds two pieces and the stub is no closer to being used.',
                        ['offcutDraws' => [['source' => 12000, 'drop' => 11300]], 'cuts' => 1],
                    ),
                ],
            ],
            [
                'title' => 'Two 9,000mm bars for 4,300 + 4,300 + 3,700 + 3,700',
                'question' => 'Same two bars either way. Which pairing?',
                'options' => [
                    $this->costOption(
                        $business,
                        'Pair like with like',
                        'Leaves 400mm of scrap and one 1,600mm offcut worth having.',
                        [
                            'purchasedMm' => 18000,
                            'scrapMm' => 400,
                            'barsOpened' => [['length' => 9000, 'drop' => 400], ['length' => 9000, 'drop' => 1600]],
                            'cuts' => 4,
                        ],
                    ),
                    $this->costOption(
                        $business,
                        'Pair 4,300 with 3,700',
                        'Destroys nothing at all - and leaves two '.number_format($threshold).'mm offcuts, both sitting at the bottom of the curve, both taking a mark and a lifetime of handling.',
                        [
                            'purchasedMm' => 18000,
                            'barsOpened' => [
                                ['length' => 9000, 'drop' => $threshold],
                                ['length' => 9000, 'drop' => $threshold],
                            ],
                            'cuts' => 4,
                        ],
                    ),
                ],
            ],
            [
                'title' => 'A 5,000mm and a 900mm cut, one 12,000mm bar to buy, an 1,800mm offcut on the rack',
                'question' => 'Is the offcut worth fetching when the bar has the room for free?',
                'options' => [
                    $this->costOption(
                        $business,
                        'Both cuts from the bar',
                        'Nothing destroyed and nothing fetched. Leaves a 6,100mm offcut, and the 1,800mm one stays on the rack.',
                        [
                            'purchasedMm' => 12000,
                            'barsOpened' => [['length' => 12000, 'drop' => 6100]],
                            'cuts' => 2,
                        ],
                    ),
                    $this->costOption(
                        $business,
                        'Take the 900 off the offcut',
                        'Same bar bought, and it keeps a longer 7,000mm offcut - but it costs a trip to the rack and bins the 900mm left of the offcut.',
                        [
                            'purchasedMm' => 12000,
                            'scrapMm' => 900,
                            'offcutDraws' => [['source' => 1800, 'drop' => 0]],
                            'barsOpened' => [['length' => 12000, 'drop' => 7000]],
                            'cuts' => 2,
                        ],
                    ),
                ],
            ],
        ];
    }

    /**
     * One option in a worked example, costed on both a light and a heavy section.
     *
     * @param  array<string, mixed>  $nest
     * @return array<string, mixed>
     */
    private function costOption(Business $business, string $label, string $blurb, array $nest): array
    {
        $costs = [];
        foreach (['light' => self::LIGHT_KG_PER_M, 'heavy' => self::HEAVY_KG_PER_M] as $weight => $kgPerM) {
            $model = new NestingCostModel($business, $kgPerM, self::REFERENCE_LENGTH_MM);

            $costs[$weight] = round($model->cost(
                purchasedMm: $nest['purchasedMm'] ?? 0,
                scrapMm: $nest['scrapMm'] ?? 0,
                kerfMm: $nest['kerfMm'] ?? 0,
                offcutDraws: $nest['offcutDraws'] ?? [],
                barsOpened: $nest['barsOpened'] ?? [],
                cuts: $nest['cuts'] ?? 0,
                unmadeCuts: 0,
            ), 2);
        }

        return [
            'label' => $label,
            'blurb' => $blurb,
            'costs' => $costs,
        ];
    }

    /**
     * The one constraint between two of the dials that has to hold.
     *
     * Retained value is V(L) = cap x L x sqrt((L-t)/(ref-t)), so dV/dL rises to 1.5 x cap at L = ref. If
     * that marginal value reaches the purchase weight then buying one more millimetre of bar and racking
     * it pays for itself, and the nest starts buying steel in order to bank it. Surfaced here because the
     * two settings are individually reasonable and only wrong together.
     *
     * @return array<string, mixed>
     */
    private function invariant(Business $business): array
    {
        $marginal = 1.5 * (float) $business->offcut_retention_cap;
        $purchase = (float) $business->purchase_cost_weight;

        return [
            'marginalRetainedValue' => round($marginal, 3),
            'purchaseCostWeight' => round($purchase, 3),
            'holds' => $marginal < $purchase,
        ];
    }
}
