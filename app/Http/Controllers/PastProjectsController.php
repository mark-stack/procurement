<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Piece;
use App\Models\Project;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class PastProjectsController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): Response
    {
        $business = auth()->user()->business;

        $pastBatches = $business->batches()
            ->with('user:id,name')
            ->inactive()
            ->latest()
            ->get();

        $batchIds = $pastBatches->pluck('id');
        $projectsByBatch = $this->projectsByBatch($batchIds);
        $projectManagersByBatch = $this->projectManagersByBatch($batchIds);
        $ordersQtyByBatch = $this->ordersQtyByBatch($batchIds);

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
                'ordersQty' => $ordersQtyByBatch->get($pastBatch->id, 0),
            ];
        }

        return Inertia::render('PastProjectsIndex', [
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
     * How many orders each batch actually needed, keyed by batch id.
     *
     * Counted as unique supplier categories, the way BatchService::totalOrdersQty defines it: an Order
     * row is firstOrCreate'd for every supplier group the moment someone opens the quote screen, so a
     * plain orders count reported drafts nobody ever placed. The join drops orders with no quote,
     * which carry no supplier category - the same ones that method skips.
     *
     * @return Collection<int, int>
     */
    private function ordersQtyByBatch(Collection $batchIds): Collection
    {
        return Order::query()
            ->join('quotes', 'quotes.id', '=', 'orders.quote_id')
            ->whereIn('orders.batch_id', $batchIds)
            ->groupBy('orders.batch_id')
            ->selectRaw('orders.batch_id as batch_id, count(distinct quotes.supplier_category) as qty')
            ->pluck('qty', 'batch_id');
    }
}
