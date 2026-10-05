<?php

namespace App\Http\Controllers;

use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\Business;
use App\Models\MaterialListFile;
use App\Models\Project;
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

        /*
         * And who runs each of those jobs, for the column beside the project's name.
         *
         * The project manager - Project::$user_id - rather than whoever put the material list on the
         * system for them, which the files list above the table already names per upload. A batch is
         * several jobs bought as one, so "whose steel is this row" is the question somebody reading it
         * is actually asking, and on a card carrying four colleagues' work the project name alone does
         * not answer it.
         *
         * Read off the relation both branches above already eager-load (Batch::projects and
         * Business::projectsReadyForBatching both load user), so this costs nothing. Null where that
         * account has since been deleted, which the table prints as an empty cell - the same thing the
         * files list does with an uploader who has gone.
         */
        $projectManagers = $projects->mapWithKeys(
            fn (Project $project) => [$project->id => $project->user?->name]
        );

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
                $projectManagers->get($rawMaterialQuote->project_id),
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
                /*
                 * The spreadsheets behind those rows. Only the open batch's can be taken off again -
                 * see files() - but every batch lists them, because "which revision is this steel
                 * off" is a question asked of a batch long after it has been ordered.
                 */
                'files' => $this->files($projectNames),
                /*
                 * And how much of the table no file accounts for. Everything imported before uploads
                 * started being kept is in here, and so are the example lists, which never came off a
                 * spreadsheet. Said out loud: a files list that does not add up to the table below it
                 * otherwise reads as a files list with something missing from it.
                 */
                'rowsWithoutFile' => $rawMaterialQuotes
                    ->filter(fn (RawMaterialQuote $row) => $row->material_list_file_id === null)
                    ->count(),
            ],
        ]);
    }

    /**
     * The uploads behind this batch's materials, newest first.
     *
     * One query for the files and one for their rows, rather than walking back from each material
     * row: a file is listed even when every row it produced has since been deleted one at a time, and
     * the only honest answer to "what did this upload leave on the batch" is then zero.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $projectNames
     * @return array<int, array<string, mixed>>
     */
    private function files(\Illuminate\Support\Collection $projectNames): array
    {
        $user = auth()->user();

        $materialListFiles = MaterialListFile::query()
            ->with(['user', 'rawMaterialQuotes.piece.order', 'rawMaterialQuotes.piece.quotes'])
            ->whereIn('project_id', $projectNames->keys())
            ->orderByDesc('id')
            ->get();

        /*
         * Whose material lists this person may change, asked once for the whole batch rather than per
         * file. The same question the per-project BOM asks before drawing its delete checkboxes - the
         * project's manager, or whoever uploaded for them - because this is the same job done to a
         * whole upload at once. See Project::isManagedBy.
         */
        $projectsManaged = Project::query()
            ->whereIn('id', $projectNames->keys())
            ->managedBy($user->id)
            ->pluck('id')
            ->all();

        return $materialListFiles->map(function (MaterialListFile $materialListFile) use ($projectNames, $projectsManaged) {
            /*
             * Both read off the rows already loaded above rather than through the model's own
             * committedRows()/batchedRows(), which each run a query: this is a list, and a batch
             * carrying a dozen uploads would be two dozen round trips to draw it.
             */
            $committed = $materialListFile->rawMaterialQuotes
                ->filter(fn (RawMaterialQuote $row) => $row->status() !== null);

            $batched = $materialListFile->rawMaterialQuotes
                ->filter(fn (RawMaterialQuote $row) => $row->piece?->batch_id !== null);

            return [
                'id' => $materialListFile->id,
                'filename' => $materialListFile->original_filename,
                'project' => $projectNames->get($materialListFile->project_id, ''),
                'size_bytes' => $materialListFile->size_bytes,
                'rowCount' => $materialListFile->rawMaterialQuotes->count(),
                //The name the uploader goes by, or nothing where that account has since been deleted
                'uploadedBy' => $materialListFile->user?->name,
                'uploadedAt' => $materialListFile->created_at?->toIso8601String(),
                //A row whose file could not be stored still lists; there is just nothing to open
                'downloadable' => $materialListFile->isDownloadable(),
                'deletable' => $committed->isEmpty()
                    && $batched->isEmpty()
                    && in_array($materialListFile->project_id, $projectsManaged, true),
                //Why not, in the words the modal prints under the file. Null when it can be deleted
                'undeletableReason' => $this->undeletableReason(
                    $materialListFile,
                    $committed,
                    $batched,
                    $projectsManaged,
                ),
            ];
        })->all();
    }

    /**
     * Why this upload cannot be taken off, in the order the reasons actually bite.
     *
     * The steel first, then the batch, then who is asking. A row that has been quoted or ordered is
     * a commitment to a supplier, which is a stronger thing to say than that its batch has been
     * nested - and a colleague looking at somebody else's job is told whose it is rather than that
     * they lack a permission.
     *
     * @param  \Illuminate\Support\Collection<int, RawMaterialQuote>  $committed
     * @param  \Illuminate\Support\Collection<int, RawMaterialQuote>  $batched
     * @param  array<int, int>  $projectsManaged
     */
    private function undeletableReason(
        MaterialListFile $materialListFile,
        \Illuminate\Support\Collection $committed,
        \Illuminate\Support\Collection $batched,
        array $projectsManaged,
    ): ?string {
        if ($committed->isNotEmpty()) {
            //Named, not counted: "which four" is the next question and the answer is right here
            $names = $committed->take(3)
                ->map(fn (RawMaterialQuote $row) => $row->description)
                ->implode(', ');

            $andMore = $committed->count() > 3
                ? ' and '.($committed->count() - 3).' more'
                : '';

            return $committed->count() === 1
                ? 'Already quoted or ordered: '.$names.'. Removing the file would take that with it.'
                : $committed->count().' of these materials are already quoted or ordered ('
                    .$names.$andMore.'), so this file cannot be removed.';
        }

        if ($batched->isNotEmpty()) {
            return 'This file\'s materials have been nested into a batch, so they are being bought '
                .'as part of it.';
        }

        if (! in_array($materialListFile->project_id, $projectsManaged, true)) {
            return 'Only the project manager, or whoever uploaded it, can remove this file.';
        }

        return null;
    }

    /**
     * One material row, as the table prints it.
     *
     * The same fields the per-project BOM draws, plus the project it came from and who runs it -
     * those two columns are the reason this table exists, since a batch is several jobs bought as one.
     *
     * The assembly mark the per-project BOM prints is deliberately not among them. It is a reference
     * into the drawing the material was taken off, which is a thing read while working on that one
     * job - and this table is read to find out whose steel is on a shared batch, where the column was
     * spending its width on a mark nobody could place without first knowing the project anyway. The
     * per-project BOM still prints it (BomEditModal).
     *
     * @return array<string, mixed>
     */
    private function row(
        string $projectName,
        ?string $projectManager,
        RawMaterialQuote $rawMaterialQuote,
        Business $business,
    ): array {
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
            //Null where the manager's account has gone; the table draws that as an empty cell
            'project_manager' => $projectManager,
            'description' => $rawMaterialQuote->description,
            'product_label' => ($match && $match['status'] === 'EXACT')
                ? ($match['decodedOption']['product_derived_label'] ?? null)
                : null,
            'nesting_algo' => $nestingLabels[0] ?? null,
            'length_required' => $rawMaterialQuote->length_required,
            'width_required' => $rawMaterialQuote->width_required,
            'sub_qty' => $rawMaterialQuote->sub_qty,
            'status' => $rawMaterialQuote->status(),
        ];
    }
}
