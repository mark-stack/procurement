<?php

namespace App\Services;

use App\Enums\ScrapSourceEnums;
use App\Models\Business;
use App\Models\Project;
use App\Models\Scrap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What a business has destroyed, by month, by section and by job.
 *
 * All three come out of the scraps table. Nothing here nests anything, re-reads a nested_state or
 * asks the cost model a question - which is the whole point of the table existing. The figures a
 * yield objective is measured against have to be readable in the time it takes to draw a page, for a
 * period somebody chooses, long after the batches involved have been closed.
 *
 * Three queries, whatever the period: the scrap rows themselves, the cuts behind them, and the names
 * of the projects those cuts belong to. The grouping is done in PHP rather than in SQL because
 * grouping by month is the one thing that is not portable between the mysql this runs on and the
 * sqlite the tests run on, and a report that is only exercised against one of the two is a report
 * that breaks in the other. The rows are narrow and a drop is at most a handful per batch, so the
 * cost is in the index on scrapped_at, not in the loop.
 */
class ScrapReport
{
    /**
     * How far back the default window reaches.
     *
     * Twelve months because the question this answers is "is it getting better", and a quarter is too
     * short to see past one awkward job - a single batch of odd lengths can double a month.
     */
    public const DEFAULT_MONTHS = 12;

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     totals: array<string, mixed>,
     *     by_source: array<int, array<string, mixed>>,
     *     by_month: array<int, array<string, mixed>>,
     *     by_category: array<int, array<string, mixed>>,
     *     by_project: array<int, array<string, mixed>>,
     * }
     */
    public function forBusiness(Business $business, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $to ??= now()->endOfMonth();
        $from ??= $to->copy()->subMonthsNoOverflow(self::DEFAULT_MONTHS - 1)->startOfMonth();

        $rows = Scrap::query()
            ->ofBusiness($business)
            ->scrappedBetween($from, $to)
            ->get([
                'id', 'source', 'scrapped_at', 'product_category', 'product_derived_label',
                'length', 'weight_kg', 'value', 'recovered_value', 'bar_id', 'offcut_id',
                /*
                 * Whether this row's mass was the catalogue's or the business default. Every figure
                 * on the row is derived from it, so it is what lets the totals below say how much
                 * of themselves rests on an assumption - see tally().
                 */
                'kg_per_m_estimated',
            ]);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $this->tally($rows->all()),
            'by_source' => $this->bySource($rows->all()),
            'by_month' => $this->byMonth($rows->all(), $from, $to),
            'by_category' => $this->byCategory($rows->all()),
            'by_project' => $this->byProject($rows->all()),
        ];
    }

    /**
     * Every month in the window, including the ones with nothing in them.
     *
     * The empty months are the point. A trend drawn only through the months that had scrap in them
     * reads as a steady line whatever happened in between, and "we scrapped nothing in March" is a
     * result rather than a gap.
     *
     * @param  array<int, Scrap>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function byMonth(array $rows, Carbon $from, Carbon $to): array
    {
        $months = [];

        for ($month = $from->copy()->startOfMonth(); $month <= $to; $month->addMonthNoOverflow()) {
            $months[$month->format('Y-m')] = [];
        }

        foreach ($rows as $row) {
            $key = $row->scrapped_at->format('Y-m');

            //A row outside the walk above cannot happen - the query is bounded by the same two dates
            $months[$key][] = $row;
        }

        $report = [];

        foreach ($months as $key => $monthRows) {
            $report[] = ['month' => $key] + $this->tally($monthRows);
        }

        return $report;
    }

    /**
     * @param  array<int, Scrap>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function bySource(array $rows): array
    {
        $report = [];

        foreach (ScrapSourceEnums::cases() as $source) {
            $of = array_filter($rows, fn (Scrap $row): bool => $row->source === $source);

            $report[] = [
                'source' => $source->value,
                'label' => $source->label(),
                'hint' => $source->hint(),
            ] + $this->tally(array_values($of));
        }

        return $report;
    }

    /**
     * @param  array<int, Scrap>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function byCategory(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row->product_category][] = $row;
        }

        $report = [];

        foreach ($grouped as $category => $categoryRows) {
            $report[] = ['product_category' => $category] + $this->tally($categoryRows);
        }

        //Heaviest first: the section that is costing the most is the one worth doing something about
        usort($report, fn (array $a, array $b): int => $b['weight_kg'] <=> $a['weight_kg']);

        return $report;
    }

    /**
     * Scrap put against the jobs it was cut alongside.
     *
     * A drop is not any one project's. A 9,000mm bar carrying parts for three jobs leaves one 600mm
     * end, and there is no honest way to say which of the three left it there - the bar was opened
     * for all of them and the packing is what decided the remainder. So it is divided between them in
     * proportion to the steel each took off that bar, which is the only split that adds back up to
     * the whole and does not depend on the order the parts happen to be listed in.
     *
     * Two kinds of row never reach a project, and are reported as not reaching one rather than being
     * spread over the jobs that were running at the time:
     *
     *  - A cleanout. The steel was banked, outlived the job that produced it, and was written off as
     *    a decision about the rack - see App\Enums\ScrapSourceEnums.
     *  - A drop off a bar nothing can name. Backfilled rows from batches older than bars.batch_id
     *    have no bar to read cuts from; the weight is real and the job behind it is not recoverable.
     *
     * @param  array<int, Scrap>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function byProject(array $rows): array
    {
        $barIds = [];
        $offcutIds = [];

        foreach ($rows as $row) {
            if (! $row->source->attributableToProject()) {
                continue;
            }

            if ($row->bar_id !== null) {
                $barIds[] = $row->bar_id;
            } elseif ($row->offcut_id !== null) {
                $offcutIds[] = $row->offcut_id;
            }
        }

        $sharesByBar = $this->cutSharesBy('bar_id', array_unique($barIds));
        $sharesByOffcut = $this->cutSharesBy('offcut_id', array_unique($offcutIds));

        /** @var array<int|string, array<string, float|int>> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            $shares = [];

            if ($row->source->attributableToProject()) {
                $shares = $row->bar_id !== null
                    ? ($sharesByBar[$row->bar_id] ?? [])
                    : ($sharesByOffcut[$row->offcut_id] ?? []);
            }

            /*
             * Nothing to divide it between. Keyed under a project id of null rather than dropped, so
             * the per-project table and the headline total are the same number of kilograms.
             */
            if ($shares === []) {
                $this->addShare($grouped, null, $row, 1.0);

                continue;
            }

            $total = array_sum($shares);

            foreach ($shares as $projectId => $cutLength) {
                $this->addShare($grouped, $projectId, $row, $total > 0 ? $cutLength / $total : 0.0);
            }
        }

        $names = Project::query()
            ->whereIn('id', array_filter(array_keys($grouped), fn ($key): bool => $key !== ''))
            ->pluck('name', 'id');

        $report = [];

        foreach ($grouped as $projectId => $totals) {
            $report[] = [
                'project_id' => $projectId === '' ? null : (int) $projectId,
                /*
                 * A project that has since been deleted still has steel in this table. Named for what
                 * it is rather than left blank, which would read as the unattributed row.
                 */
                'project' => $projectId === ''
                    ? 'Not attributable to a job'
                    : ($names[$projectId] ?? 'A deleted project'),
            ] + $this->rounded($totals);
        }

        usort($report, fn (array $a, array $b): int => $b['weight_kg'] <=> $a['weight_kg']);

        return $report;
    }

    /**
     * How much steel each project took off each bar, or off each offcut.
     *
     * One query for the lot. Walking $scrap->bar->cuts per row would be two queries a row, on a page
     * whose whole reason for existing is that it does not have to work that hard.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<int, float>>  source id => project id => millimetres cut
     */
    private function cutSharesBy(string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('cuts')
            ->join('pieces', 'pieces.id', '=', 'cuts.piece_id')
            ->whereIn('cuts.'.$column, $ids)
            ->groupBy('cuts.'.$column, 'pieces.project_id')
            ->get([
                'cuts.'.$column.' as source_id',
                'pieces.project_id as project_id',
                DB::raw('sum(cuts.length) as cut_length'),
            ]);

        $shares = [];

        foreach ($rows as $row) {
            $shares[(int) $row->source_id][(int) $row->project_id] = (float) $row->cut_length;
        }

        return $shares;
    }

    /**
     * Add one row's share to a project's running totals.
     *
     * @param  array<int|string, array<string, float|int>>  $grouped
     */
    private function addShare(array &$grouped, int|string|null $projectId, Scrap $row, float $share): void
    {
        //'' rather than null: PHP array keys cannot be null, and a null cast to one silently becomes ''
        $key = $projectId === null ? '' : $projectId;

        $grouped[$key] ??= ['pieces' => 0, 'length_mm' => 0.0, 'weight_kg' => 0.0, 'estimated_weight_kg' => 0.0, 'value' => 0.0, 'recovered_value' => 0.0, 'net_loss' => 0.0];

        /*
         * Counted as a whole piece against every project it is shared with, while the weight and the
         * money are divided. A drop is one physical offcut in a skip however many jobs were on the bar
         * - there is no two thirds of a piece of steel - so the counts across this table deliberately
         * do not add up to the headline count, and the kilograms do.
         */
        $grouped[$key]['pieces']++;
        $grouped[$key]['length_mm'] += $row->length * $share;
        $grouped[$key]['weight_kg'] += $row->weight_kg * $share;

        if ($row->kg_per_m_estimated) {
            $grouped[$key]['estimated_weight_kg'] += $row->weight_kg * $share;
        }

        $grouped[$key]['value'] += $row->value * $share;
        $grouped[$key]['recovered_value'] += $row->recovered_value * $share;
        $grouped[$key]['net_loss'] += $row->netLoss() * $share;
    }

    /**
     * Add up a set of scrap rows.
     *
     * carried_value is deliberately not among them. It is on a cleanout row and null on a nest
     * drop, so a column summing it would be adding up a figure that only half the rows have and
     * presenting the result beside totals that every row contributed to. It answers a question
     * about one piece - what the rack still thought this was worth when somebody gave up on it -
     * and that question does not add up across a quarter.
     *
     * estimated_weight_kg IS among them, and it is not a second measurement of scrap. It is how much
     * of the figure beside it was worked out from a mass nobody recorded: the cost model falls back
     * to the business default when the catalogue has no kg/m for a section (see
     * Services\NestingCostModel), and that default is one flat number for light angle and heavy
     * beam alike. A month whose weight is entirely estimated is not a wrong figure, but it is a
     * different kind of figure to one read off real sections, and a yield objective set against it
     * would be measuring the default rather than the yard. Carried through the per-project split
     * the same way the weight is, so the shares still add back up.
     *
     * @param  array<int, Scrap>  $rows
     * @return array<string, mixed>
     */
    private function tally(array $rows): array
    {
        $totals = [
            'pieces' => count($rows),
            'length_mm' => 0.0,
            'weight_kg' => 0.0,
            'estimated_weight_kg' => 0.0,
            'value' => 0.0,
            'recovered_value' => 0.0,
            'net_loss' => 0.0,
        ];

        foreach ($rows as $row) {
            $totals['length_mm'] += $row->length;
            $totals['weight_kg'] += $row->weight_kg;
            $totals['value'] += $row->value;
            $totals['recovered_value'] += $row->recovered_value;
            $totals['net_loss'] += $row->netLoss();

            if ($row->kg_per_m_estimated) {
                $totals['estimated_weight_kg'] += $row->weight_kg;
            }
        }

        return $this->rounded($totals);
    }

    /**
     * Rounded on the way out, and only on the way out.
     *
     * Every figure is summed at full precision first. Rounding each row to the cent before adding it
     * up puts the error in the total rather than in the last decimal of it, and on a per-project split
     * - where one drop is divided three ways - it is how the three shares stop adding back up to the
     * drop. Millimetres go to one place for the same reason the money goes to two: it is a reading,
     * not an exact quantity.
     *
     * @param  array<string, float|int>  $totals
     * @return array<string, float|int>
     */
    private function rounded(array $totals): array
    {
        return [
            'pieces' => $totals['pieces'],
            'length_mm' => round($totals['length_mm'], 1),
            'weight_kg' => round($totals['weight_kg'], 1),
            'estimated_weight_kg' => round($totals['estimated_weight_kg'], 1),
            'value' => round($totals['value'], 2),
            'recovered_value' => round($totals['recovered_value'], 2),
            'net_loss' => round($totals['net_loss'], 2),
        ];
    }
}
