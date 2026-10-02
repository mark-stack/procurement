<?php

namespace App\Formatters;

use App\Enums\SupplierGroupEnums;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Project;
use App\Models\User;
use App\Services\BatchStages;
use App\Services\FabricationDeadlineQuoting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What /dashboard says above the upload form: where every live job stands, and what is waiting on
 * somebody.
 *
 * Both halves are read off the same two things - the live projects of this business and the step each
 * active batch is on (App\Services\BatchStages) - so the pills, the project list and the actions can
 * never contradict each other, and none of them can contradict the board.
 *
 * Deliberately cheap, because this is where login lands. Nothing here walks a material list in PHP:
 * one query fetches the live projects with the two counts the page needs, and each active batch costs
 * a handful of bounded queries. In particular nothing here asks for Business::projectsReadyForBatching()
 * or projectsRequiringClarification(), which run a price book match per material row of every project
 * the business has ever had - that is the board's price for drawing cards, not a price for a summary.
 */
class DashboardFormatter
{
    /**
     * The four steps, in the order the board draws them.
     */
    public const string NESTING = 'NESTING';

    /**
     * @return array{pipeline: list<array<string, mixed>>, projects: list<array<string, mixed>>, actions: list<array<string, mixed>>}
     */
    public function summary(Business $business, User $user): array
    {
        $staged = (new BatchStages)->forBusiness($business);

        $live = $this->liveProjects($business, $this->stageByProject($staged));

        return [
            'pipeline' => $this->pipeline($live, $staged),
            'projects' => $this->projectRows($live, $user),
            'actions' => $this->actions($live, $staged, $user),
        ];
    }

    /**
     * Which step each batched project is on, and the batch that put it there.
     *
     * Read off the batches rather than off the projects, because the step is the batch's property, not
     * the project's. A project whose pieces somehow span two batches takes the furthest step of the
     * two, which is the order this walks them in.
     *
     * @param  array{QUOTING: Collection<int, Batch>, ORDERING: Collection<int, Batch>, DELIVERING: Collection<int, Batch>}  $staged
     * @return array<int, array{stage: string, batch: Batch}>
     */
    private function stageByProject(array $staged): array
    {
        $stageByProject = [];

        foreach ($staged as $stage => $batches) {
            foreach ($batches as $batch) {
                foreach ($batch->pieces()->distinct()->pluck('project_id') as $projectId) {
                    $stageByProject[(int) $projectId] = ['stage' => $stage, 'batch' => $batch];
                }
            }
        }

        return $stageByProject;
    }

    /**
     * Every live project of this business that has a material list, with the step it is on.
     *
     * A project is on the Nesting step when no piece of it has been batched - which is the board's
     * Nesting column (its cards and its unfinished imports both), plus the one shape that falls
     * through both of those lists: a project whose material rows matched no product at all, so it has
     * no pieces to nest and no partial match to clarify. That project is on no column of the board,
     * which is exactly why it belongs here - the dashboard is the screen nothing is allowed to hide
     * from, and the action list says what is wrong with it.
     *
     * A project with batched pieces and no active batch is on a batch that has been marked done, so it
     * is a past project and is left out.
     *
     * Returned as rows rather than models carrying a step of their own: the step is the batch's
     * property, and writing one onto a Project would be writing an attribute the model would then try
     * to persist.
     *
     * @param  array<int, array{stage: string, batch: Batch}>  $stageByProject
     * @return Collection<int, array{project: Project, stage: string, batch: Batch|null}>
     */
    private function liveProjects(Business $business, array $stageByProject): Collection
    {
        return Project::query()
            ->select([
                'id',
                'name',
                'reference',
                'user_id',
                'created_by_user_id',
                'date_fabrication_begins',
                'date_materials_required',
                'tentative',
            ])
            ->with(['user:id,name', 'createdBy:id,name'])
            //Named for the page: the lines on this job that nothing can be bought for
            ->withCount(['rawMaterialQuotes', 'rawMaterialQuotes as unmatched_rows_count' => fn (Builder $rows) => $rows->whereDoesntHave('piece')])
            ->withExists(['pieces as has_batched_piece' => fn (Builder $pieces) => $pieces->whereNotNull('batch_id')])
            ->thisBusiness($business)
            ->where('archive', false)
            //A project with no material list at all has nothing to report and nothing to do
            ->has('rawMaterialQuotes')
            ->get()
            ->map(function (Project $project) use ($stageByProject) {
                $staged = $stageByProject[$project->id] ?? null;

                return [
                    'project' => $project,
                    'stage' => $staged['stage'] ?? ($project->has_batched_piece ? null : self::NESTING),
                    'batch' => $staged['batch'] ?? null,
                ];
            })
            //Done batches: past projects, and the past projects page is where those are read
            ->filter(fn (array $row) => $row['stage'] !== null)
            ->values();
    }

    /**
     * The four pills.
     *
     * Business-wide counts, like the board's column badges - a batch is quoted and ordered for the
     * whole business at once, so "mine" is not a meaningful way to count one.
     *
     * @param  Collection<int, array{project: Project, stage: string, batch: Batch|null}>  $live
     * @param  array{QUOTING: Collection<int, Batch>, ORDERING: Collection<int, Batch>, DELIVERING: Collection<int, Batch>}  $staged
     * @return list<array<string, mixed>>
     */
    private function pipeline(Collection $live, array $staged): array
    {
        $nesting = $live->where('stage', self::NESTING);

        return [
            [
                'key' => self::NESTING,
                'label' => 'Nesting',
                //Projects, not batches: nothing in this step has been batched yet
                'count' => $nesting->count(),
                'unit' => 'project',
                'hint' => 'Material lists waiting to be bought for. The longer they wait, the more the nest has to work with.',
            ],
            [
                'key' => BatchStages::QUOTING,
                'label' => 'Quoting',
                'count' => $staged[BatchStages::QUOTING]->count(),
                'unit' => 'batch',
                'hint' => 'Nested batches out with your suppliers for pricing.',
            ],
            [
                'key' => BatchStages::ORDERING,
                'label' => 'Ordering',
                'count' => $staged[BatchStages::ORDERING]->count(),
                'unit' => 'batch',
                'hint' => 'At least one order placed, with material still to buy.',
            ],
            [
                'key' => BatchStages::DELIVERING,
                'label' => 'Delivering',
                'count' => $staged[BatchStages::DELIVERING]->count(),
                'unit' => 'batch',
                'hint' => 'Everything ordered, now waiting on the yard.',
            ],
        ];
    }

    /**
     * One row per live job: where it is, whose it is, and when the shop needs it.
     *
     * Yours first - your own jobs, then the ones you uploaded for a colleague - and within each group
     * the nearest fabrication date first. A project with no fabrication date sorts last rather than
     * first: it is the one shape that will never be auto-quoted, and the action list is where that is
     * raised, so it does not also get to head the list of urgent work.
     *
     * @param  Collection<int, array{project: Project, stage: string, batch: Batch|null}>  $live
     * @return list<array<string, mixed>>
     */
    private function projectRows(Collection $live, User $user): array
    {
        $sorted = $live->sortBy(function (array $row) use ($user) {
            $project = $row['project'];

            return [
                $project->user_id === $user->id ? 0 : ($project->created_by_user_id === $user->id ? 1 : 2),
                $project->date_fabrication_begins === null
                    ? PHP_INT_MAX
                    : Carbon::parse($project->date_fabrication_begins)->timestamp,
                $project->name,
            ];
        });

        $rows = [];

        foreach ($sorted as $row) {
            $project = $row['project'];

            $rows[] = [
                'id' => $project->id,
                'name' => $project->name,
                'reference' => $project->reference,
                'stage' => $row['stage'],
                'batchId' => $row['batch']?->id,
                'rows' => $project->raw_material_quotes_count,
                'unmatchedRows' => $project->unmatched_rows_count,
                'dateFabricationBegins' => $project->date_fabrication_begins,
                'dateMaterialsRequired' => $project->date_materials_required,
                'tentative' => (bool) $project->tentative,
                'mine' => $project->user_id === $user->id,
                //Named only when it is not yours, so a board of one person's work says nothing twice
                'projectManagerName' => $project->user_id === $user->id ? null : $project->user?->name,
                'uploadedByName' => $project->created_by_user_id === null ? null : $project->createdBy?->name,
            ];
        }

        return $rows;
    }

    /**
     * What is waiting on somebody, most urgent first.
     *
     * Two kinds of item, and the difference is who may act:
     *
     *  - A batch item is anybody's in the business. Quoting, ordering and recording a delivery are
     *    business-wide acts on a batch that carries several people's projects.
     *  - A project item is only raised for the people who may actually do it - its manager, or the
     *    colleague who uploaded the list for them (Project::isManagedBy). Raising "finish this material
     *    list" against a colleague who would be refused by the upload gate is raising a dead end.
     *
     * @param  Collection<int, array{project: Project, stage: string, batch: Batch|null}>  $live
     * @param  array{QUOTING: Collection<int, Batch>, ORDERING: Collection<int, Batch>, DELIVERING: Collection<int, Batch>}  $staged
     * @return list<array<string, mixed>>
     */
    private function actions(Collection $live, array $staged, User $user): array
    {
        $actions = [
            ...$this->nestingActions($live, $user),
            ...$this->quotingActions($staged[BatchStages::QUOTING], $live),
            ...$this->orderingActions($staged[BatchStages::ORDERING], $live),
            ...$this->deliveringActions($staged[BatchStages::DELIVERING], $live),
        ];

        //Overdue above due above the rest, and otherwise the order each group was built in
        $rank = ['overdue' => 0, 'due' => 1, 'open' => 2];

        return collect($actions)
            ->sortBy(fn (array $action, int $index) => [$rank[$action['severity']], $index])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array{project: Project, stage: string, batch: Batch|null}>  $live
     * @return list<array<string, mixed>>
     */
    private function nestingActions(Collection $live, User $user): array
    {
        $actions = [];
        $board = route('projects.index');

        /*
         * Material lines nothing can be bought for.
         *
         * Raised whatever step the project is on, because a batched project keeps them: nesting takes
         * the pieces it has, and a row that matched no product never became one - see
         * Project::everyOrderableRowOrdered, which is why those rows do not hold the deadline chasing
         * open. They do mean part of the job has not been bought, and the only place to fix it is the
         * material list.
         */
        foreach ($live as $row) {
            $project = $row['project'];

            if ($project->unmatched_rows_count < 1 || ! $project->isManagedBy($user)) {
                continue;
            }

            $lines = $project->unmatched_rows_count;

            $actions[] = [
                'key' => 'unmatched-'.$project->id,
                'severity' => 'due',
                'title' => $lines === 1
                    ? 'One material line on "'.$project->name.'" is not matched to a product'
                    : $lines.' material lines on "'.$project->name.'" are not matched to a product',
                'detail' => 'Nothing can be quoted, ordered or cut for '.($lines === 1 ? 'it' : 'them')
                    .' until the line is confirmed against your price book.',
                'actionLabel' => 'Open the material list',
                'href' => $board,
            ];
        }

        /*
         * A project that will wait in the Nesting column for good.
         *
         * Quoting starts five days before fabrication does (App\Services\FabricationDeadlineQuoting),
         * and that is the only thing that moves a project along without somebody pressing the button -
         * so one with no fabrication date is a job nothing will ever come and collect. Only possible
         * for projects created before the date was asked for.
         */
        $nesting = $live->where('stage', self::NESTING)->pluck('project');

        foreach ($nesting as $project) {
            if ($project->date_fabrication_begins !== null || ! $project->isManagedBy($user)) {
                continue;
            }

            $actions[] = [
                'key' => 'no-fabrication-date-'.$project->id,
                'severity' => 'due',
                'title' => '"'.$project->name.'" has no fabrication start date',
                'detail' => 'Quoting starts automatically five days before fabrication begins, so without'
                    .' a date this job waits in Nesting until somebody notices.',
                'actionLabel' => 'Add the date',
                'href' => $board,
            ];
        }

        /*
         * The column is inside the window and still has not been batched.
         *
         * The hourly sweep presses "Start quoting" for the business, so normally nobody sees this. It
         * is here for when the sweep cannot: a read-only account, a trigger project whose manager has
         * been deleted, or an hour that has not come round yet. Only shown to somebody who owns a
         * project in the column, because PrerequisiteConditions::startQuoting refuses anybody else -
         * see condition 2 - and an action that can only answer 403 is worse than none.
         */
        $trigger = (new FabricationDeadlineQuoting)->triggerProject($nesting);

        if ($trigger && $nesting->where('user_id', $user->id)->isNotEmpty()) {
            $begins = Carbon::parse($trigger->date_fabrication_begins);

            $actions[] = [
                'key' => 'start-quoting',
                'severity' => $begins->isPast() ? 'overdue' : 'due',
                'title' => 'Fabrication on "'.$trigger->name.'" begins '.$begins->toFormattedDateString()
                    .' and its materials have not been quoted',
                'detail' => 'Starting quoting nests everything in Nesting into one batch - '
                    .$this->projectCount($nesting->count()).' right now.',
                'actionLabel' => 'Start quoting',
                'href' => $board,
            ];
        }

        return $actions;
    }

    /**
     * @param  Collection<int, Batch>  $batches
     * @param  Collection<int, array{project: Project, stage: string, batch: Batch|null}>  $live
     * @return list<array<string, mixed>>
     */
    private function quotingActions(Collection $batches, Collection $live): array
    {
        $actions = [];

        foreach ($batches as $batch) {
            $quotes = $batch->quotes()->get(['id', 'quote_sent', 'quoted_price']);

            $sent = $quotes->where('quote_sent', true);
            $unpriced = $sent->whereNull('quoted_price');

            /*
             * Three things a batch in this step can be waiting for, in the order they happen. A quote
             * row exists for every supplier in the group from the moment the modal is first opened, so
             * "has quotes" is not "has asked anybody" - quote_sent is.
             */
            if ($sent->isEmpty()) {
                $actions[] = $this->batchAction(
                    $batch,
                    $live,
                    'quote-requests',
                    'Quote requests have not gone out yet',
                    'The batch is nested and the draft emails are ready - one per supplier.',
                    'Open quotes',
                );

                continue;
            }

            if ($unpriced->isNotEmpty()) {
                $actions[] = $this->batchAction(
                    $batch,
                    $live,
                    'record-prices',
                    $unpriced->count() === 1
                        ? 'One quote has no price recorded against it'
                        : $unpriced->count().' quotes have no price recorded against them',
                    'Record what came back and the batch can be ordered.',
                    'Record the quotes',
                );

                continue;
            }

            $actions[] = $this->batchAction(
                $batch,
                $live,
                'place-first-order',
                'Every quote is priced - nothing has been ordered',
                'Pick the supplier and mark the order placed.',
                'Open orders',
            );
        }

        return $actions;
    }

    /**
     * @param  Collection<int, Batch>  $batches
     * @param  Collection<int, array{project: Project, stage: string, batch: Batch|null}>  $live
     * @return list<array<string, mixed>>
     */
    private function orderingActions(Collection $batches, Collection $live): array
    {
        $actions = [];

        foreach ($batches as $batch) {
            $unsent = $batch->orders()->where('order_sent', false)->count();

            $actions[] = $this->batchAction(
                $batch,
                $live,
                'remaining-orders',
                $unsent === 1
                    ? 'One supplier order on this batch has not been placed'
                    : ($unsent > 1
                        ? $unsent.' supplier orders on this batch have not been placed'
                        : 'Part of this batch has not been ordered'),
                /*
                 * The generic wording covers the case where every order row that exists has been sent
                 * and material is still unordered - a supplier whose order row has not been drafted
                 * yet, or a line that never matched a product. Both are real, and both are answered on
                 * the same screen.
                 */
                'Steel the shop is waiting on has not been bought yet.',
                'Open orders',
            );
        }

        return $actions;
    }

    /**
     * @param  Collection<int, Batch>  $batches
     * @param  Collection<int, array{project: Project, stage: string, batch: Batch|null}>  $live
     * @return list<array<string, mixed>>
     */
    private function deliveringActions(Collection $batches, Collection $live): array
    {
        $actions = [];

        foreach ($batches as $batch) {
            $awaiting = $batch->orders()
                ->where('order_sent', true)
                ->where('is_delivered', false)
                ->count();

            if ($awaiting > 0) {
                $actions[] = $this->batchAction(
                    $batch,
                    $live,
                    'record-delivery',
                    $awaiting === 1
                        ? 'One order on this batch has not been booked in'
                        : $awaiting.' orders on this batch have not been booked in',
                    'Record the delivery when the steel arrives, with its docket and certificates.',
                    'Open orders',
                );

                continue;
            }

            /*
             * Delivered steel with no traceability. The same test the board's card applies, down to
             * accepting either form of certificate - see Order::scopeMissingMaterialCerts - because a
             * merchant who emails the PDF and never quotes a number leaves the steel just as traceable.
             */
            $missingCerts = $batch->orders()
                ->where('order_sent', true)
                ->where('is_delivered', true)
                ->whereRelation('quote', 'supplier_category', '=', SupplierGroupEnums::STEEL_MERCHANT->value)
                ->missingMaterialCerts()
                ->exists();

            if ($missingCerts) {
                $actions[] = $this->batchAction(
                    $batch,
                    $live,
                    'material-certs',
                    'Steel has been delivered without material certificates',
                    'The batch cannot be closed until every delivery carries its cert number or file.',
                    'Add certificates',
                );

                continue;
            }

            $actions[] = $this->batchAction(
                $batch,
                $live,
                'move-to-done',
                'Everything on this batch is in',
                'Move it to done and its projects become past projects.',
                'Open the batch',
            );
        }

        return $actions;
    }

    /**
     * One action about one batch, labelled with the jobs on it and the nearest first cut.
     *
     * The href opens the quotes and orders modal for this batch straight away - the same deep link the
     * fabrication deadline emails use - so the action is one tap from the thing it asks for rather than
     * from a board the user then has to search.
     *
     * @param  Collection<int, array{project: Project, stage: string, batch: Batch|null}>  $live
     * @return array<string, mixed>
     */
    private function batchAction(
        Batch $batch,
        Collection $live,
        string $key,
        string $title,
        string $detail,
        string $actionLabel,
    ): array {
        $onBatch = $live
            ->filter(fn (array $row) => $row['batch']?->id === $batch->id)
            ->pluck('project');

        $earliest = $onBatch
            ->pluck('date_fabrication_begins')
            ->filter()
            ->map(fn ($date) => Carbon::parse($date))
            ->min();

        return [
            'key' => $key.'-'.$batch->id,
            /*
             * Urgency comes off the steel's own deadline, not off which step it is on. A batch whose
             * first cut has passed is late whatever it is waiting for; one with a fortnight in hand is
             * simply work in progress.
             */
            'severity' => match (true) {
                $earliest === null => 'open',
                $earliest->isPast() => 'overdue',
                $earliest->lessThanOrEqualTo(Carbon::now()->addDays(FabricationDeadlineQuoting::DAYS_BEFORE_FABRICATION)) => 'due',
                default => 'open',
            },
            'title' => $title,
            'detail' => $detail,
            'actionLabel' => $actionLabel,
            //The board, with the quotes/orders modal for this batch already open
            'href' => route('projects.index', ['quotes' => $batch->id]),
            'batchId' => $batch->id,
            /*
             * Whose work is on it. projectRows() names the same jobs, and a batch is several people's
             * work at once - so the action has to say which jobs it is about.
             */
            'projectNames' => $onBatch->pluck('name')->values()->all(),
            'dateFabricationBegins' => $earliest?->toDateString(),
        ];
    }

    private function projectCount(int $count): string
    {
        return $count === 1 ? 'one project' : $count.' projects';
    }
}
