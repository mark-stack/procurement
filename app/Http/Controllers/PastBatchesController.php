<?php

namespace App\Http\Controllers;

use App\Formatters\SupplierFormatter;
use App\Models\Business;
use App\Models\Piece;
use App\Models\Project;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class PastBatchesController extends Controller
{
    /**
     * The parts a set of pieces comes to, summed in SQL.
     *
     * NestingIndexController's, to the character - the two pages print this number under the same
     * button, and a batch that said "148 cuts" while it was live must not say something else once it
     * is closed. See that constant for why actual_qty is cast and why the rows are not counted.
     */
    private const string CUT_SUM = 'sum(cast(actual_qty as decimal(14,4)))';

    /**
     * Handle the incoming request.
     */
    public function __invoke(): Response
    {
        $business = auth()->user()->business;

        $pastBatches = $business->batches()
            /*
             * The measurement alongside the batcher, because the one thing this page could never say
             * about a finished job was how it went. One row per batch, loaded in one query with the
             * rest - what it carries was written when the nest was saved and when the steel landed,
             * so nothing here is re-nested to produce it. See App\Services\BatchMeasurements.
             */
            ->with(['user:id,name', 'measurement'])
            ->inactive()
            ->latest()
            ->get();

        $batchIds = $pastBatches->pluck('id');
        $projectsByBatch = $this->projectsByBatch($batchIds);
        $projectManagersByBatch = $this->projectManagersByBatch($batchIds);
        /*
         * The two numbers under the card's buttons, which are the Nesting card's own - see
         * NestingIndex.vue's cutLabel() and categoryLabel(). A closed batch's card reads the same as
         * a live one, so it has to carry the same figures, and both are bulk lookups like everything
         * else on this page.
         */
        $cutCountByBatch = $this->cutCountByBatch($batchIds);
        $categoryCountByBatch = $this->categoryCountByBatch($batchIds, $business);

        /*
         * This list only ever grows - the per-batch lookups this used to make were
         * nine queries a row with nothing to cap the row count. Everything above is resolved in bulk,
         * so the page is a fixed number of queries however many batches it lists.
         */
        $pastBatchesWithExtraData = [];
        foreach ($pastBatches as $pastBatch) {
            $pastBatchesWithExtraData[] = [
                'id' => $pastBatch->id,
                'createdAt' => $pastBatch->created_at->diffForHumans(),
                /*
                 * The owners of the projects in the batch, not $pastBatch->user - that is whoever
                 * pressed "Start quoting", which sweeps in every colleague's project that was ready
                 * at the time. A batch spanning two project managers was labelled with one name,
                 * and it was the batcher's rather than either manager's.
                 *
                 * A joined string rather than the owners themselves: the table prints one line, and
                 * shipping the project's user is what this screen was trimmed back from.
                 */
                'projectManagers' => $projectManagersByBatch->get($pastBatch->id, '-'),
                //Who nested it. A different question, so it gets a line of its own
                'batchedBy' => $pastBatch->user->name,
                'projects' => $projectsByBatch->get($pastBatch->id, collect()),
                'cutCount' => $cutCountByBatch->get($pastBatch->id, 0),
                'categoryCount' => $categoryCountByBatch->get($pastBatch->id, 0),
                /*
                 * What this batch measured, and nulls where it measured nothing.
                 *
                 * The cost is the figure the nest was PICKED on, read back out of the measurement
                 * rather than struck again here - the coefficients behind it are editable, so a
                 * batch re-costed on this page would answer "what would this job cost today" to a
                 * reader asking what it cost. costRetained says whether those coefficients were
                 * kept with the nest; a batch from before they were still shows a figure, and the
                 * card says which kind of figure it is rather than printing both alike.
                 *
                 * unmadeCuts is why a batch that was nested can still have no cost: the figure the
                 * search ranks an unplaceable cut with is a billion dollars and is not money. The
                 * card says what it is instead, which is the more useful thing anyway.
                 */
                'cost' => $pastBatch->measurement?->cost,
                'costRetained' => (bool) $pastBatch->measurement?->cost_from_retained_settings,
                //Cast rather than coalesced: a batch with no measurement has nothing unplaced, and
                //null casts to 0 - where "?? 0" on the right of a nullsafe reads to PHPStan as a
                //null check that cannot fire
                'unmadeCuts' => (int) $pastBatch->measurement?->unmade_cuts,
                'efficiency' => $pastBatch->measurement?->efficiency,
                //Null for a batch delivered before deliveries were measured, or one that promised no date
                'daysLate' => $pastBatch->measurement?->days_late,
                'deliveredOn' => $pastBatch->measurement?->delivered_on?->toDateString(),
            ];
        }

        return Inertia::render('PastBatchesIndex', [
            'pastBatches' => $pastBatchesWithExtraData,
        ]);
    }

    /**
     * The id and name of each batch's projects, keyed by batch id.
     *
     * Just what the table labels each row with. Batch::projects() walks the
     * rawMaterialQuotes/piece/quote/order tree that ProjectResource needs, and this screen reads
     * nothing off it but the name - it was 93% of the response and carried every project's owner and
     * raw material quotes to the browser with it.
     *
     * @return Collection<int, Collection<int, Project>>
     */
    private function projectsByBatch(Collection $batchIds): Collection
    {
        $pieceRows = Piece::query()
            ->select(['batch_id', 'project_id'])
            ->whereIn('batch_id', $batchIds)
            ->distinct()
            ->get();

        $projects = Project::query()
            ->select(['id', 'name'])
            ->whereIn('id', $pieceRows->pluck('project_id')->unique())
            ->get()
            ->keyBy('id');

        return $pieceRows
            ->groupBy('batch_id')
            ->map(fn (Collection $rows) => $rows
                ->map(fn (Piece $row) => $projects->get($row->project_id))
                ->filter()
                ->values()
            );
    }

    /**
     * The distinct owners of each batch's projects, as one readable line, keyed by batch id.
     *
     * Resolved in bulk alongside everything else on this screen - the list only grows, and a
     * per-row lookup here would be the nine-queries-a-row problem the rest of it was trimmed back
     * from. The names are joined here rather than sent as a list because the table prints a line.
     *
     * @return Collection<int, string>
     */
    private function projectManagersByBatch(Collection $batchIds): Collection
    {
        return Piece::query()
            ->join('projects', 'projects.id', '=', 'pieces.project_id')
            ->join('users', 'users.id', '=', 'projects.user_id')
            ->whereIn('pieces.batch_id', $batchIds)
            ->distinct()
            ->get(['pieces.batch_id as batch_id', 'users.name as name'])
            ->groupBy('batch_id')
            ->map(fn (Collection $rows) => $rows
                ->pluck('name')
                ->unique()
                ->sort()
                ->values()
                ->join(', ', ' and ')
            );
    }

    /**
     * How many parts each batch came to, keyed by batch id - the BOM button's second line.
     *
     * The pieces' quantities summed rather than the piece rows counted, because a piece is a line of
     * demand: one of them with an actual_qty of 12 is twelve cuts off the saw. One grouped query for
     * the page, like every other lookup here.
     *
     * @return Collection<int, int>
     */
    private function cutCountByBatch(Collection $batchIds): Collection
    {
        return Piece::query()
            ->whereIn('batch_id', $batchIds)
            ->groupBy('batch_id')
            ->selectRaw('batch_id, '.self::CUT_SUM.' as cuts')
            ->pluck('cuts', 'batch_id')
            ->map(fn ($cuts) => (int) round((float) $cuts));
    }

    /**
     * How many merchants each batch was bought from, keyed by batch id - the Material order line.
     *
     * The supplier groups this batch's material falls into, which is the number of blocks the modal
     * that button opens draws (BatchOrderListController groups the stock the same way). Read off the
     * material rather than off the Order rows, which is what this page used to count: a batch bought
     * over the phone and marked ordered by hand has no order row and every one of those cards claimed
     * nothing had been bought for it.
     *
     * A pair per batch per product category, which is a handful of rows however big the jobs are.
     *
     * @return Collection<int|string, int<0, max>>
     */
    private function categoryCountByBatch(Collection $batchIds, Business $business): Collection
    {
        $formatter = new SupplierFormatter;
        $supplierGroups = $formatter->supplierGroups($business);

        return Piece::query()
            ->select(['batch_id', 'product_category'])
            ->whereIn('batch_id', $batchIds)
            ->distinct()
            ->get()
            ->groupBy('batch_id')
            ->map(fn (Collection $rows): int => count($formatter->groupsFor(
                $rows->pluck('product_category')->all(),
                $supplierGroups,
            )));
    }
}
