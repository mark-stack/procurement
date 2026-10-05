<?php

namespace App\Http\Controllers;

use App\Services\ScrapReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ScrapController extends Controller
{
    /**
     * The windows the page offers.
     *
     * Months rather than dates. Nobody asks "what did we scrap between the 3rd of March and the 19th
     * of June"; they ask whether this quarter was worse than the last one, and a window that always
     * ends at the end of this month is the one that can be compared with the one before it.
     *
     * @var array<int, int>
     */
    private const WINDOWS = [3, 6, 12, 24];

    /**
     * Everything this business has destroyed, and what it cost.
     *
     * The other six resource verbs that used to be here are gone with the empty bodies they had.
     * Scrap is written by the two events that destroy steel - a nest leaving a drop, and somebody
     * weighing in dead stock (see Services\ScrapLedger) - and neither of them is a form on this page,
     * so there was nothing for create, store, edit, update or destroy to do. An unimplemented action
     * answers with a blank 200 rather than a 404, which reads as a page that is merely empty.
     *
     * This is a read, so it sits behind no further gate than the billing one the whole group is
     * behind: a lapsed account can still look at last year's figures, it just cannot nest anything new.
     */
    public function index(Request $request, ScrapReport $report): Response
    {
        $business = $this->businessOf($request);

        $months = (int) $request->query('months', (string) ScrapReport::DEFAULT_MONTHS);

        //Anything else in the query string falls back rather than reaching the report as a date range
        if (! in_array($months, self::WINDOWS, true)) {
            $months = ScrapReport::DEFAULT_MONTHS;
        }

        $to = now()->endOfMonth();
        $from = $to->copy()->subMonthsNoOverflow($months - 1)->startOfMonth();

        return Inertia::render('ScrapIndex', [
            'report' => $report->forBusiness($business, $from, $to),
            'months' => $months,
            'windows' => self::WINDOWS,
        ]);
    }
}
