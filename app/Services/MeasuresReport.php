<?php

namespace App\Services;

use App\Models\BatchMeasurement;
use App\Models\Business;
use Illuminate\Support\Carbon;

/**
 * The three things a steel shop can set an objective on, month by month: how much of the steel it
 * handled became a part, how much it destroyed, and how often the material was there on the day.
 *
 * Nothing here nests anything, decodes a nested_state or asks the cost model a question. Yield and
 * delivery are read off batch_measurements, where they were written at the moment they happened, and
 * scrap is read off Services\ScrapReport, which reads the scraps table for the same reason. That is
 * what makes this a series rather than a recalculation: the figures below are what the application
 * told somebody at the time, and they do not move when a setting, a catalogue row or a fabrication
 * date is edited afterwards.
 *
 * Four queries, whatever the window - one for the measurements, three for the scrap.
 *
 * THE MONTHS ARE NOT ONE EVENT. A batch's yield belongs to the month it was nested and its delivery
 * to the month the steel arrived, and those are frequently different months - which is why one row
 * is read under two dates rather than being filed under one. A month's yield says how well the work
 * started that month was planned; its delivery figure says how much of what was promised for that
 * month turned up. Adding them to one column would be a number about neither.
 */
class MeasuresReport
{
    /**
     * How far back the default window reaches. Twelve months, matching Services\ScrapReport, because
     * the scrap column here IS that report and a trend drawn over two different windows is two
     * trends.
     */
    public const int DEFAULT_MONTHS = ScrapReport::DEFAULT_MONTHS;

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     yield: array<string, float|int>,
     *     delivery: array<string, float|int|null>,
     *     scrap: array<string, mixed>,
     *     provenance: array<string, int>,
     *     by_month: array<int, array<string, mixed>>,
     * }
     */
    public function forBusiness(Business $business, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $to ??= now()->endOfMonth();
        $from ??= $to->copy()->subMonthsNoOverflow(self::DEFAULT_MONTHS - 1)->startOfMonth();

        /*
         * Every row that touches the window on either of its two dates, in one query. A batch nested
         * in February and delivered in April is one row that belongs in both months, under a
         * different heading in each.
         */
        $rows = BatchMeasurement::query()
            ->ofBusiness($business)
            ->where(fn ($query) => $query
                ->whereBetween('nested_at', [$from, $to])
                ->orWhereBetween('delivered_on', [$from->toDateString(), $to->toDateString()]))
            ->get();

        $nested = $rows->filter(fn (BatchMeasurement $row): bool => $row->nested_at !== null
            && $row->nested_at->betweenIncluded($from, $to));

        $delivered = $rows->filter(fn (BatchMeasurement $row): bool => $row->delivered_on !== null
            && $row->delivered_on->betweenIncluded($from->copy()->startOfDay(), $to->copy()->endOfDay()));

        $scrap = (new ScrapReport)->forBusiness($business, $from, $to);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'yield' => $this->yield($nested->all()),
            'delivery' => $this->delivery($delivered->all()),
            //The scrap report's own headline, not a second count of the same steel
            'scrap' => $scrap['totals'],
            'provenance' => $this->provenance($nested->all()),
            'by_month' => $this->byMonth($nested->all(), $delivered->all(), $scrap['by_month'], $from, $to),
        ];
    }

    /**
     * Every month in the window, including the ones nothing happened in.
     *
     * The empty months are the point, for the reason ScrapReport gives: a trend drawn only through
     * the months that had work in them reads as a steady line whatever happened in between.
     *
     * @param  array<int, BatchMeasurement>  $nested
     * @param  array<int, BatchMeasurement>  $delivered
     * @param  array<int, array<string, mixed>>  $scrapByMonth
     * @return array<int, array<string, mixed>>
     */
    private function byMonth(array $nested, array $delivered, array $scrapByMonth, Carbon $from, Carbon $to): array
    {
        $months = [];

        for ($month = $from->copy()->startOfMonth(); $month <= $to; $month->addMonthNoOverflow()) {
            $months[$month->format('Y-m')] = ['nested' => [], 'delivered' => []];
        }

        foreach ($nested as $row) {
            $months[$row->nested_at->format('Y-m')]['nested'][] = $row;
        }

        foreach ($delivered as $row) {
            $months[$row->delivered_on->format('Y-m')]['delivered'][] = $row;
        }

        //Keyed so a month with no scrap in it still finds the zeros the scrap report wrote for it
        $scrap = [];
        foreach ($scrapByMonth as $row) {
            $scrap[$row['month']] = $row;
        }

        $report = [];

        foreach ($months as $key => $rows) {
            $report[] = ['month' => $key]
                + $this->yield($rows['nested'])
                + $this->delivery($rows['delivered'])
                + [
                    //The one column that is somebody else's figure, named so it reads as one
                    'scrap_weight_kg' => $scrap[$key]['weight_kg'] ?? 0,
                    'scrap_pieces' => $scrap[$key]['pieces'] ?? 0,
                ];
        }

        return $report;
    }

    /**
     * How much of the steel these nests handled became a part.
     *
     * SUMMED FIRST AND DIVIDED ONCE. Averaging the per-batch percentages would give a two-cut job
     * the same weight in the month as one that bought six tonnes, which is how a month of ordinary
     * work reads as a bad one because somebody nested a handrail on the 3rd.
     *
     * Both denominators are kept: efficiency is struck against what the nest CONSUMED (a bought bar
     * at its full length, an offcut at only what it gave up - see NestingFormatter::materialConsumed)
     * and the destroyed share against every millimetre handled. The two answer different questions
     * and the difference between them is the steel that went back on the rack.
     *
     * @param  array<int, BatchMeasurement>  $rows
     * @return array<string, float|int>
     */
    private function yield(array $rows): array
    {
        $totals = [
            'batches' => count($rows),
            'purchased_mm' => 0.0,
            'offcut_mm' => 0.0,
            'consumed_mm' => 0.0,
            'used_mm' => 0.0,
            'reusable_mm' => 0.0,
            'kerf_mm' => 0.0,
            'scrap_mm' => 0.0,
        ];

        foreach ($rows as $row) {
            $totals['purchased_mm'] += $row->purchased_mm;
            $totals['offcut_mm'] += $row->offcut_mm;
            $totals['consumed_mm'] += $row->consumed_mm;
            $totals['used_mm'] += $row->used_mm;
            $totals['reusable_mm'] += $row->reusable_mm;
            $totals['kerf_mm'] += $row->kerf_mm;
            $totals['scrap_mm'] += $row->scrap_mm;
        }

        $handled = $totals['purchased_mm'] + $totals['offcut_mm'];
        $destroyed = $totals['scrap_mm'] + $totals['kerf_mm'];

        return [
            'batches' => $totals['batches'],
            'purchased_mm' => round($totals['purchased_mm'], 1),
            'offcut_mm' => round($totals['offcut_mm'], 1),
            'consumed_mm' => round($totals['consumed_mm'], 1),
            'used_mm' => round($totals['used_mm'], 1),
            'reusable_mm' => round($totals['reusable_mm'], 1),
            'kerf_mm' => round($totals['kerf_mm'], 1),
            'scrap_mm' => round($totals['scrap_mm'], 1),
            /*
             * Null rather than zero for a month that nested nothing. Zero per cent yield is a real
             * and terrible reading, and a month nobody worked in must not be drawn as one.
             */
            'efficiency' => $totals['consumed_mm'] > 0
                ? round($totals['used_mm'] / $totals['consumed_mm'] * 100, 1)
                : null,
            'effective_efficiency' => $handled > 0
                ? round(($handled - $destroyed) / $handled * 100, 1)
                : null,
        ];
    }

    /**
     * How often the steel was there on the day it was wanted.
     *
     * Three counts rather than one percentage, because the third is what keeps the first honest.
     * A batch whose jobs name no fabrication date promised nothing, so it is neither on time nor
     * late; counting those as successes is how an on-time figure reaches 100% on a yard that has
     * never once been measured against a date.
     *
     * @param  array<int, BatchMeasurement>  $rows
     * @return array<string, float|int|null>
     */
    private function delivery(array $rows): array
    {
        $onTime = 0;
        $late = 0;
        $unpromised = 0;
        $daysLate = [];

        foreach ($rows as $row) {
            if ($row->days_late === null) {
                $unpromised++;

                continue;
            }

            if ($row->days_late <= 0) {
                $onTime++;

                continue;
            }

            $late++;
            $daysLate[] = $row->days_late;
        }

        $measured = $onTime + $late;

        return [
            'deliveries' => count($rows),
            'on_time' => $onTime,
            'late' => $late,
            'unpromised' => $unpromised,
            //Null where nothing in the month was measurable, for the reason the yield above is
            'on_time_rate' => $measured > 0 ? round($onTime / $measured * 100, 1) : null,
            //The worst one, not the average: an objective is missed by its outliers
            'worst_days_late' => $daysLate === [] ? null : max($daysLate),
        ];
    }

    /**
     * How much of this window's work can say what it was costed on.
     *
     * Reported rather than quietly mixed in. A batch nested before the settings were retained carries
     * a cost struck against whatever the business's figures were when the row was written, which is a
     * valuation rather than a record - see Services\NestingSettings. The millimetres and the
     * percentages beside it are unaffected either way: those are measurements of steel, not of money.
     *
     * @param  array<int, BatchMeasurement>  $rows
     * @return array<string, int>
     */
    private function provenance(array $rows): array
    {
        $costed = 0;
        $retained = 0;

        foreach ($rows as $row) {
            if ($row->cost === null) {
                continue;
            }

            $costed++;

            if ($row->cost_from_retained_settings) {
                $retained++;
            }
        }

        return [
            'costed' => $costed,
            'costed_on_retained_settings' => $retained,
        ];
    }
}
