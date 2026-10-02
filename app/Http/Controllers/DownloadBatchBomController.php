<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\Business;
use App\Models\RawMaterialQuote;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class DownloadBatchBomController extends Controller
{
    /**
     * Every material row on a batch, across all of its projects.
     *
     * What the Nesting page's cards open, and deliberately a different question from
     * DownloadBomController, which answers for one project: this is read-only, so there is none of
     * the uploading, clarifying, custom products or row deleting that the per-project BOM exists for.
     * Those are per project by nature - a material list belongs to one job and only its owner may
     * change it - and a table spanning a whole batch is for reading what is on the job.
     *
     * With no batch in the url it answers for the batch that does not exist yet: everything waiting on
     * the board's Nesting column, which is what "Start quoting" would sweep into one batch.
     */
    public function __invoke(?Batch $batch = null): JsonResponse
    {
        $business = auth()->user()->business;

        if ($batch !== null) {
            Gate::authorize('owned', $batch);

            $projects = $batch->projects();
        } else {
            /*
             * The pending card's projects, asked the way the board asks (see NestingIndexController):
             * a project held at a price book clarification is not on that column, so it is not on this
             * table either.
             */
            $piecesReadyForBatching = (new NestingFormatter)->piecesReadyForBatching($business);

            $projects = $piecesReadyForBatching->isEmpty()
                ? new EloquentCollection
                : $business->projectsReadyForBatching($piecesReadyForBatching);
        }

        /*
         * The material rows asked for in one query rather than walked off each project, because the two
         * branches above hand back projects loaded to different depths - and status() reads the row's
         * piece, its order and its quotes, which is three queries a line without the eager load.
         *
         * By project, then by row, so the Project column reads as blocks rather than interleaving two
         * jobs' steel.
         */
        $projectNames = $projects->pluck('name', 'id');

        $rawMaterialQuotes = RawMaterialQuote::query()
            ->with('piece.order', 'piece.quotes')
            ->whereIn('project_id', $projectNames->keys())
            ->orderBy('project_id')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($rawMaterialQuotes as $rawMaterialQuote) {
            $rows[] = $this->row(
                $projectNames->get($rawMaterialQuote->project_id, ''),
                $rawMaterialQuote,
                $business,
            );
        }

        return response()->json([
            'batchBom' => [
                //Which card asked, so a late answer for one batch cannot be drawn into another's modal
                'batch_id' => $batch?->id,
                'projectCount' => $projects->count(),
                'rows' => $rows,
            ],
        ]);
    }

    /**
     * One material row, as the table prints it.
     *
     * The same fields the per-project BOM draws, plus the project it came from - that column is the
     * reason this table exists, since a batch is several jobs bought as one.
     *
     * @return array<string, mixed>
     */
    private function row(string $projectName, RawMaterialQuote $rawMaterialQuote, Business $business): array
    {
        $nestingFormatter = new NestingFormatter;

        /*
         * Which algorithm the row is measured by, because it decides what the length column means:
         * a bundled item is counted rather than measured, and an area one has a width as well.
         */
        $nestingLabels = $rawMaterialQuote->product_category
            ? $nestingFormatter->getNestingLabelsFromProductCategory($rawMaterialQuote->product_category)
            : null;

        /*
         * The matched product's label, where the price book matched exactly one. A partial match is
         * still a question for the owner to answer on the per-project BOM, and this table is not where
         * it gets answered - so it reads as unmatched here, the same as a row with no match at all.
         */
        $match = (new ProductService)->getProductMatchOptions($business, $rawMaterialQuote);

        return [
            'id' => $rawMaterialQuote->id,
            'project' => $projectName,
            'description' => $rawMaterialQuote->description,
            'product_label' => ($match && $match['status'] === 'EXACT')
                ? ($match['decodedOption']['product_derived_label'] ?? null)
                : null,
            'nesting_algo' => $nestingLabels[0] ?? null,
            'length_required' => $rawMaterialQuote->length_required,
            'width_required' => $rawMaterialQuote->width_required,
            'sub_qty' => $rawMaterialQuote->sub_qty,
            'assembly_mark' => $rawMaterialQuote->assembly_mark,
            'status' => $rawMaterialQuote->status(),
        ];
    }
}
