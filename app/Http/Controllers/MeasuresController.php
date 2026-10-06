<?php

namespace App\Http\Controllers;

use App\Models\Business;
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
     * Yield, scrap and on-time delivery, by month, for one business.
     *
     * A read and nothing else: every figure on this page was written down by the event it measures -
     * a nest being saved, a drop going in the skip, a delivery being marked in - and there is
     * nothing here for anybody to type. See App\Services\BatchMeasurements, which is the only thing
     * that writes the first and the third.
     *
     * Admin-only, and so the business is a route parameter: an admin reads a customer's figures from
     * the users list, and reading their own account's would answer a question nobody asked. Missing,
     * it falls back to the caller's own business the way the nesting algorithm page does - which is
     * what keeps a bare /measures answering rather than 404ing.
     *
     * Nothing scopes the report beyond that parameter. It does not need to: every series it reads
     * hangs off the business it is handed - see App\Services\MeasuresReport - and the gate on the
     * route is the whole of the authorization.
     */
    public function __invoke(Request $request, MeasuresReport $report, ?Business $business = null): Response
    {
        //instanceof rather than ?? alone: businessOf() is the one that knows how to refuse an
        //account with no business at all, and it answers with a 403 rather than a 500
        if (! $business instanceof Business) {
            $business = $this->businessOf($request);
        }

        $months = (int) $request->query('months', (string) MeasuresReport::DEFAULT_MONTHS);

        //Anything else in the query string falls back rather than reaching the report as a range
        if (! in_array($months, self::WINDOWS, true)) {
            $months = MeasuresReport::DEFAULT_MONTHS;
        }

        $to = now()->endOfMonth();
        $from = $to->copy()->subMonthsNoOverflow($months - 1)->startOfMonth();

        return Inertia::render('MeasuresIndex', [
            /*
             * Whose figures these are, said on the page. An admin reading one customer's yield beside
             * another's has nothing else on the screen to tell the two apart, and the window buttons
             * need the id to stay on the business they were pressed on.
             */
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
                'domain' => $business->domain,
            ],
            'report' => $report->forBusiness($business, $from, $to),
            'months' => $months,
            'windows' => self::WINDOWS,
        ]);
    }
}
