<?php

namespace App\Http\Controllers;

use App\Services\MeasuresReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MeasuresController extends Controller
{
    /**
     * The windows the page offers.
     *
     * The scrap report's, to the figure, because the scrap column on this page is that report - see
     * App\Http\Controllers\ScrapController for why these are months rather than dates.
     *
     * @var array<int, int>
     */
    private const array WINDOWS = [3, 6, 12, 24];

    /**
     * Yield, scrap and on-time delivery, by month.
     *
     * Index only. Nothing on this page is a form: every figure on it was written down by the event
     * it measures - a nest being saved, a drop going in the skip, a delivery being marked in - and
     * there is nothing here for anybody to type. See App\Services\BatchMeasurements, which is the
     * only thing that writes the first and the third.
     *
     * A read, so it sits behind no gate beyond the billing one the whole group is behind: a lapsed
     * account can still look at last year's figures, which is exactly the account most likely to
     * want to.
     */
    public function index(Request $request, MeasuresReport $report): Response
    {
        $business = $this->businessOf($request);

        $months = (int) $request->query('months', (string) MeasuresReport::DEFAULT_MONTHS);

        //Anything else in the query string falls back rather than reaching the report as a range
        if (! in_array($months, self::WINDOWS, true)) {
            $months = MeasuresReport::DEFAULT_MONTHS;
        }

        $to = now()->endOfMonth();
        $from = $to->copy()->subMonthsNoOverflow($months - 1)->startOfMonth();

        return Inertia::render('MeasuresIndex', [
            'report' => $report->forBusiness($business, $from, $to),
            'months' => $months,
            'windows' => self::WINDOWS,
        ]);
    }
}
