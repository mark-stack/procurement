<?php

namespace App\Http\Controllers;

use App\Formatters\KanbanFormatter;
use App\Formatters\NestingFormatter;
use App\Formatters\SupplierFormatter;
use App\Models\Business;
use App\Models\Order;
use App\Models\Piece;
use App\Models\Project;
use App\Services\BatchStages;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class NestingIndexController extends Controller
{
    /**
     * The parts a set of pieces comes to, summed in SQL.
     *
     * actual_qty is a varchar - the importer writes what the spreadsheet said - so it is cast rather
     * than left to the database to coerce, which on an unparseable value would silently read as zero
     * in the middle of a sum. A quantity with a fraction in it is rounded where it is read; a card
     * saying "11.5 cuts" would be describing something nobody can make.
     */
    private const string CUT_SUM = 'sum(cast(actual_qty as decimal(14,4)))';

    /**
     * Every live batch this business has, as one list, each card naming the column it is in.
     *
     * One card per column of the board, in the order the board runs: the Nesting column first, which is
     * a batch in waiting - everything ready to be nested, which "Start quoting" sweeps into one batch -
     * then the three columns of live batches. That first card is always there, empty or not, because it
     * is the only batch an upload can join; the page keeps it out of the "Only my projects" filter for
     * the same reason.
     *
     * Finished batches are deliberately not here. They are nested, bought and delivered: there is
     * nothing left on them to nest or to buy, which is every button a card carries, and the list of them
     * only grows - it would be the one part of the page with no ceiling, burying the handful of batches
     * somebody opened this page to work on. /past-projects is where a finished batch is read, and its
     * nesting is reachable from there.
     *
     * Which column a batch is in is read off the one place that answers it: App\Services\BatchStages.
     * Asking it a second way here would let this page and the board disagree about where a batch is -
     * and "done" is the one answer that keeps a batch off the page entirely. What the card's pill says
     * is a step further on; see milestoneOf().
     *
     * Every one of them is sent whatever the "Only my projects" switch is set to, and each card says
     * whether it is one of yours. The switch is a filter over a list the page already holds, so
     * flicking it costs nothing - and it is one more card per batch, not one more query per flick.
     */
    public function __invoke(): Response
    {
        $user = auth()->user();
        $business = $user->business;

        /*
         * Deliberately NOT BatchService::sortByUserAndLatest, which is how the board orders its
         * columns. That walks every batch the business has ever had and loads the material tree of
         * each one to find out whose projects are on it - far too much to pay for a list that prints
         * an id and a link. Latest first by id within each column, which is the half of that sort the
         * ids carry.
         */
        $staged = (new BatchStages)->forBusiness($business);

        /*
         * Which merchant buys what, for the third number on every card below - see
         * supplierGroupCount(). Asked once for the page: it walks the product implementations and the
         * business's plan, and the answer is the same for every card.
         */
        $supplierGroups = (new SupplierFormatter)->supplierGroups($business);

        $batches = [];

        /*
         * The column before a batch exists. No id to print: there is no row until quoting starts, and
         * it carries the one thing a batch in waiting has that a real batch does not - the day it stops
         * waiting, read off the same method the board's card uses.
         *
         * Empty is a card too; see below.
         */
        $pendingProjects = $this->pendingBatchProjects($business)->sortBy('id');

        /*
         * The staff of this business by id, for naming the manager of a job that is not yours - which
         * is what the heading of a card carrying none of your own work says. One map for the page: a
         * business is a handful of people, and the alternative is loading the manager of every project
         * on every card one at a time.
         */
        $staffNames = $business->users()->pluck('name', 'id');

        /*
         * Sent whether or not anything is waiting on it, which is the one card on this page that is
         * always drawn.
         *
         * It is the only batch an upload can still join - every other card is locked to further
         * material lists - so it is where somebody goes to put work on, and where they look for the
         * work they just put on. A card that appears only once there is something on it is missing
         * exactly when it is being looked for: on a first upload, and for anybody whose colleagues'
         * jobs are all already nested. The page's "+ Materials" button fills it, and an empty one says
         * so on its face (NestingIndex.vue).
         *
         * Empty means empty, not zeroed by hand: the helpers below answer 0 for no projects, and the
         * ordering date is null the way it is for projects with no fabrication date.
         */
        $batches[] = [
            'id' => null,
            'stage' => 'NESTING',
            'orderingTriggerDate' => (new KanbanFormatter)->orderingTriggerDate($pendingProjects),
            //What "Start quoting" would sweep in, which is what this card stands for
            'projects' => $this->projectCards($pendingProjects, $user->id, $staffNames),
            'cutCount' => $this->pendingCutCount($pendingProjects->pluck('id')->all()),
            'categoryCount' => $this->pendingCategoryCount($pendingProjects->pluck('id')->all(), $supplierGroups),
            //Whether any of what is waiting is this user's own work - see projectCards()
            'mine' => $pendingProjects->contains(fn (Project $project) => $project->user_id === $user->id),
        ];

        /*
         * Which of the fully ordered batches have had all their steel turn up, for the pill below.
         * One query for the page, like the three below it, rather than a read of the orders per card.
         */
        $delivered = $this->fullyDeliveredBatchIds($staged[BatchStages::DELIVERING]);

        foreach ([BatchStages::QUOTING, BatchStages::ORDERING, BatchStages::DELIVERING] as $stage) {
            foreach ($staged[$stage]->sortByDesc('id') as $batch) {
                $batches[] = [
                    'id' => $batch->id,
                    'stage' => $this->milestoneOf($stage, in_array($batch->id, $delivered, true)),
                    //Nothing to order by: this batch has been nested, so the deadline it had is spent
                    'orderingTriggerDate' => null,
                    'projects' => [],
                    'cutCount' => 0,
                    'categoryCount' => 0,
                    'mine' => false,
                ];
            }
        }

        /*
         * How many parts are on each batch, in one grouped query for the page rather than a count per
         * card.
         *
         * Read off the pieces, the way Batch::projects() decides what is on a batch, and it is the
         * pieces' quantities summed rather than the piece rows counted: a piece is a line of demand, so
         * one of them with an actual_qty of 12 is twelve cuts off the saw, and counting the rows would
         * have called that one.
         */
        $batchIds = array_values(array_filter(array_column($batches, 'id')));

        $cuts = Piece::query()
            ->whereIn('batch_id', $batchIds)
            ->groupBy('batch_id')
            ->selectRaw('batch_id, '.self::CUT_SUM.' as cuts')
            ->get()
            ->keyBy('batch_id');

        /*
         * And which jobs they are, because the card is headed by them: your own project's name rather
         * than a batch number, and the rest of the batch behind the "other projects" label it hovers
         * open.
         *
         * Two queries rather than a join, and the pairs asked for distinct - a project puts a piece on
         * the batch per material row, so the raw pairs would name one job a dozen times.
         */
        $projectIdsByBatch = Piece::query()
            ->select(['batch_id', 'project_id'])
            ->whereIn('batch_id', $batchIds)
            ->distinct()
            ->get()
            ->groupBy('batch_id')
            ->map(fn ($rows) => $rows->pluck('project_id')->all());

        $projects = Project::query()
            //The last four are the edit modal's, not the card's - see projectCards()
            ->select([
                'id',
                'name',
                'user_id',
                'reference',
                'date_materials_required',
                'date_fabrication_begins',
                'tentative',
            ])
            ->whereIn('id', $projectIdsByBatch->flatten()->unique()->all())
            //Oldest first, which is the order the cards name them in
            ->orderBy('id')
            ->get();

        /*
         * And how many suppliers it has to be bought from, which is the third number on a card. The
         * product categories a batch carries, mapped through the business's supplier groups - a pair
         * per batch per category, which is a handful of rows however big the job is.
         */
        $categoriesByBatch = Piece::query()
            ->select(['batch_id', 'product_category'])
            ->whereIn('batch_id', $batchIds)
            ->distinct()
            ->get()
            ->groupBy('batch_id')
            ->map(fn ($rows) => $rows->pluck('product_category')->all());

        foreach ($batches as $index => $batch) {
            if ($batch['id'] === null) {
                continue;
            }

            /*
             * The jobs on this batch, which answer both the card's heading and the "Only my projects"
             * switch - see projectCards() for which of them counts as yours.
             */
            $batchProjects = $projects->whereIn('id', $projectIdsByBatch->get($batch['id'], []));

            $batches[$index]['projects'] = $this->projectCards($batchProjects, $user->id, $staffNames);
            $batches[$index]['cutCount'] = (int) round((float) ($cuts->get($batch['id'])->cuts ?? 0));
            $batches[$index]['categoryCount'] = $this->supplierGroupCount(
                $categoriesByBatch->get($batch['id'], []),
                $supplierGroups,
            );
            $batches[$index]['mine'] = $batchProjects
                ->contains(fn (Project $project) => $project->user_id === $user->id);
        }

        return Inertia::render('NestingIndex', [
            'batches' => $batches,
            /*
             * The staff a new project can be created for, for this page's own "+ Materials" modal -
             * the same facility the board and the upload page have, because the person with the
             * spreadsheet is often not the person running the job. See User::colleagueOptions.
             */
            'colleagues' => $user->colleagueOptions(),
        ]);
    }

    /**
     * Those jobs as the card words them: the name, whose job it is, and whether it is yours.
     *
     * The card is headed by the projects on the batch rather than by its number, because a batch
     * number is nothing anybody recognises - "Batch #18" says nothing about whose steel it is or what
     * it is for, and the shop knows the work by the job name. So the heading names your own projects
     * on it, and the rest of the batch is the count underneath, listed on hover.
     *
     * "Yours" is the project manager - project.user_id - and deliberately not who created the batch: a
     * batch is several jobs bought as one, and whoever pressed "Start quoting" swept in whatever was
     * waiting, colleagues' jobs included. It is the same test BatchService::sortByUserAndLatest applies
     * to float your cards to the top of the board, and asking it the other way round would hide a
     * batch carrying your job because a colleague nested it.
     *
     * The uploader is deliberately not counted either. A draftsman who uploads a material list on a
     * manager's behalf is recorded in created_by_user_id, but the job is still the manager's, and the
     * board draws the same line.
     *
     * The manager is named for your own projects too, though the card does not print it there - the
     * one place the page needs it is a job that is not yours. Null where the account has since gone,
     * which the card words the way the board's card does rather than leaving a blank.
     *
     * The last four fields are not drawn anywhere. They are what the board's edit modal opens on - the
     * page reuses that component rather than growing a second rename form, and it edits the project
     * the card hands it (see NestingIndex.vue). Deliberately not ProjectResource, which is how the
     * board feeds the same modal: that walks the material rows, the manager and the upload
     * prerequisites of every project it draws, which is the cost this page was written to avoid.
     * "mine" is the whole of the permission question - PrerequisiteConditions::editProject lets the
     * project manager and nobody else rename a job - so the pencil is drawn off it.
     *
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, string>  $staffNames
     * @return array<int, array{id: int, name: string, manager: string|null, mine: bool, reference: string|null, date_materials_required: string|null, date_fabrication_begins: string|null, tentative: bool|null}>
     */
    private function projectCards(Collection $projects, int $userId, Collection $staffNames): array
    {
        return $projects
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'manager' => $staffNames->get($project->user_id),
                'mine' => $project->user_id === $userId,
                'reference' => $project->reference,
                'date_materials_required' => $project->date_materials_required,
                /*
                 * Trimmed to the date part, the way ProjectResource trims it and for the same reason:
                 * the column comes back as "2026-11-02 00:00:00" through this cast-less model, and a
                 * date input draws nothing at all for a value it cannot parse - so the modal would
                 * open empty on a project that has a fabrication date and offer to clear it.
                 */
                'date_fabrication_begins' => $project->date_fabrication_begins
                    ? substr((string) $project->date_fabrication_begins, 0, 10)
                    : null,
                'tentative' => $project->tentative,
            ])
            ->values()
            ->all();
    }

    /**
     * The last step this batch has actually passed, which is what the card's pill says.
     *
     * Deliberately not the column it is in. The board's columns name the work in hand - "Ordering" is
     * a batch somebody is still buying - and this page's cards answer the other question: how far has
     * this job got. So the words are what has been done to it, and two of them do not line up with a
     * column:
     *
     *  - QUOTED: nested and out with the suppliers. No order has gone in (the Quoting column). Said of
     *    a batch whose quote requests have not been sent either - the Quoting column does not
     *    distinguish the two, and both are a batch whose next step is a price.
     *  - ORDERED: an order has gone in. Either there is material on the job nobody has bought yet (the
     *    Ordering column) or it is all bought and some of it has not arrived (the Delivering column).
     *  - DELIVERED: it is all bought and every sent order has been booked in as delivered.
     *
     * That last one is the whole reason this is not a relabelling of the columns. The Delivering column
     * means every material row points at a sent order, which is a statement about the paperwork going
     * out, not about steel arriving - a card reading "Delivered" on that alone would be telling the
     * shop its material is in the rack while it is still on a lorry.
     *
     * A fully delivered batch closes itself five days after its last goods receipt
     * (App\Services\DeliveredBatchArchiving), so DELIVERED is what a card says in that window, and for
     * as long as a hold on the archiving keeps it on the board.
     */
    private function milestoneOf(string $stage, bool $everyOrderDelivered): string
    {
        if ($stage === BatchStages::QUOTING) {
            return 'QUOTED';
        }

        if ($stage === BatchStages::DELIVERING && $everyOrderDelivered) {
            return 'DELIVERED';
        }

        return 'ORDERED';
    }

    /**
     * Of those batches, the ones with no delivery still outstanding.
     *
     * is_delivered, not the goods receipt columns: that flag answers "did the steel turn up", which is
     * what the pill is saying, and the receipt answers the separate question of whether anybody checked
     * it (see Order::hasReceiptRecord). Every delivery booked in before those columns existed has the
     * flag and no receipt, and the pill would be calling all of them undelivered.
     *
     * Asked only of the Delivering column, which is where the DELIVERED pill can be reached from: a
     * batch with material nobody has bought is ORDERED however many of its sent orders have arrived.
     * Reaching the stage at all takes a sent order, so "nothing outstanding" cannot mean "no orders".
     *
     * @param  Collection<int, \App\Models\Batch>  $delivering
     * @return array<int, int>
     */
    private function fullyDeliveredBatchIds(Collection $delivering): array
    {
        $batchIds = $delivering->pluck('id')->all();

        if ($batchIds === []) {
            return [];
        }

        $awaitingDelivery = Order::query()
            ->whereIn('batch_id', $batchIds)
            ->where('order_sent', true)
            ->where('is_delivered', false)
            ->distinct()
            ->pluck('batch_id')
            //Cast, because what is returned is compared strictly against ids read off Batch rows
            ->map(fn ($batchId) => (int) $batchId)
            ->all();

        return array_values(array_diff($batchIds, $awaitingDelivery));
    }

    /**
     * How many parts are waiting to be nested, across those projects.
     *
     * The pending card's counterpart to the sum above, and restricted to pieces with no batch: a
     * project that was batched and has since had material added to it is on both cards, and only the
     * new rows are waiting. That is the same test NestingFormatter::piecesReadyForBatching applies.
     *
     * @param  array<int, int>  $projectIds
     */
    private function pendingCutCount(array $projectIds): int
    {
        if ($projectIds === []) {
            return 0;
        }

        return (int) round((float) Piece::query()
            ->whereIn('project_id', $projectIds)
            ->whereNull('batch_id')
            ->selectRaw(self::CUT_SUM.' as qty')
            ->value('qty'));
    }

    /**
     * How many suppliers this lot has to be bought from - steel merchant, timber merchant, and so on.
     *
     * The number of blocks the Order list button opens, which is what the line under it is describing:
     * the order list groups the stock by supplier group (see BatchOrderListController), because each
     * group is a different merchant to send a list to.
     *
     * Counted off the same map that grouping uses - SupplierFormatter::supplierGroups, which is scoped
     * to what this business's plan covers - so the two cannot disagree about how many blocks there
     * are. A category belonging to two groups counts for both, the way the grouping assigns it to
     * both; a category in no group counts for neither, and the order list does not draw it either.
     *
     * @param  array<int, string|null>  $productCategories
     * @param  array<string, array<int, string>>  $supplierGroups
     */
    private function supplierGroupCount(array $productCategories, array $supplierGroups): int
    {
        $matched = 0;

        foreach ($supplierGroups as $includedProducts) {
            if (array_intersect($productCategories, $includedProducts) !== []) {
                $matched++;
            }
        }

        return $matched;
    }

    /**
     * The same count for the pending card, whose pieces are the unbatched ones.
     *
     * @param  array<int, int>  $projectIds
     * @param  array<string, array<int, string>>  $supplierGroups
     */
    private function pendingCategoryCount(array $projectIds, array $supplierGroups): int
    {
        if ($projectIds === []) {
            return 0;
        }

        $productCategories = Piece::query()
            ->whereIn('project_id', $projectIds)
            ->whereNull('batch_id')
            ->distinct()
            ->pluck('product_category')
            ->all();

        return $this->supplierGroupCount($productCategories, $supplierGroups);
    }

    /**
     * What is waiting to be nested - the projects on the board's Nesting column.
     *
     * Empty means an empty card, not no card - see the card itself above. The same two calls the board
     * makes, in the same order, because this card has to carry exactly what that column draws: a
     * project held at a price book clarification is excluded from projectsReadyForBatching() even
     * though its matched pieces are unbatched, so the pieces alone would put a job on the open batch
     * that the board is not offering to nest. Restating that test here instead would be a second
     * definition of "ready for nesting" to keep in step with the first.
     *
     * The projects themselves rather than a yes or no, because the card's "Order by" date is the
     * earliest fabrication date among them - see KanbanFormatter::orderingTriggerDate.
     *
     * The empty check first, as App\Services\FabricationDeadlineQuoting does it: with nothing
     * unbatched there is no pending batch whatever the clarifications say, and that skips a
     * price book match per material row of every project the business has ever had.
     *
     * @return Collection<int, \App\Models\Project>
     */
    private function pendingBatchProjects(Business $business): Collection
    {
        $piecesReadyForBatching = (new NestingFormatter)->piecesReadyForBatching($business);

        if ($piecesReadyForBatching->isEmpty()) {
            return collect();
        }

        return $business->projectsReadyForBatching($piecesReadyForBatching);
    }
}
