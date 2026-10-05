<?php

namespace App\Http\Controllers;

use App\Formatters\KanbanFormatter;
use App\Formatters\NestingFormatter;
use App\Formatters\SupplierFormatter;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Offcut;
use App\Models\Order;
use App\Models\Piece;
use App\Models\Project;
use App\Models\Quote;
use App\Models\User;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\BatchStages;
use Illuminate\Support\Carbon;
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
     * That sentence needs a way for a batch to become finished, and for a while there was not one: the
     * only button that closed a batch by hand was on the deleted board, and the sweep that closes them
     * on a business's behalf can only see batches bought through the quotes screen, which nothing
     * writes any more. Every card carries "Move to done" now (see prerequisiteMarkDone below), and the
     * sweep closes a batch somebody marked delivered by hand as well - App\Services\DeliveredBatchAutoDone.
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
        $pending = $this->pendingBatch($business);

        /*
         * Your own projects first, then oldest first within each group.
         *
         * Inherited from the board's Nesting column, which sorted this way and is gone: the card
         * names the whole business's waiting work as one list, so on a page with a few colleagues on
         * it your own job was wherever creation order happened to put it. Sorted by id alone before
         * the column moved here.
         */
        $pendingProjects = $pending['projects']
            ->sortBy(fn (Project $project) => [
                $project->user_id === $user->id ? 0 : 1,
                //Two projects created in the same second would otherwise order arbitrarily
                $project->id,
            ])
            ->values();

        /*
         * And whether this user may close it, which is what the open batch card's menu offers.
         *
         * The same gate the board draws its own "Start quoting" button from, asked of the same two
         * collections - QuoteController::store asks it again and aborts 403, so a menu item
         * drawn off anything else could only ever lead to a dead end. Unsorted, the gate caring who
         * owns the projects rather than what order they are in.
         */
        $prerequisiteStartQuoting = (new PrerequisiteConditions)->startQuoting(
            $user,
            $pending['projects'],
            $pending['pieces'],
        );

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
         * dates are null the way they are for projects with no fabrication date.
         */
        $pendingRequiredDate = $this->materialsRequiredDate($pendingProjects);
        $pendingCriticalPathDeadline = $this->criticalPathDeadline($pendingRequiredDate, 'NESTING', $business);

        $batches[] = [
            'id' => null,
            'stage' => 'NESTING',
            //The day this card's steel has to be at the workshop, which every card on the page carries
            'materialsRequiredDate' => $pendingRequiredDate,
            //And the day this card, where it has got to, has to move on by - see criticalPathDeadline()
            'criticalPathDeadline' => $pendingCriticalPathDeadline,
            //And how far past it the card already is, which is what it is coloured and footed off
            'daysBehindCriticalPath' => $this->daysBehindCriticalPath($pendingCriticalPathDeadline),
            /*
             * And the day it has to stop waiting and be quoted, which only this card has - a batch
             * that has been nested has spent that deadline.
             *
             * Not drawn any more: the pill beside the job names is the required-by date above, and
             * the board's Nesting card is where the ordering deadline is read. It is still sent
             * because the "Start quoting" dialog argues off it - whether waiting for a bigger batch
             * is still advice or is now late (see startQuotingDialog).
             */
            'orderingTriggerDate' => (new KanbanFormatter)->orderingTriggerDate($pendingProjects),
            //What "Start quoting" would sweep in, which is what this card stands for
            'projects' => $this->projectCards($pendingProjects, $user->id, $staffNames),
            'cutCount' => $this->pendingCutCount($pendingProjects->pluck('id')->all()),
            'categoryCount' => $this->pendingCategoryCount($pendingProjects->pluck('id')->all(), $supplierGroups),
            //Whether any of what is waiting is this user's own work - see mineOf()
            'mine' => $this->mineOf($pendingProjects, $user),
            //Nothing to unpick: this batch has not been nested yet. See the loop below.
            'prerequisiteUndoStartQuoting' => null,
            //And nothing to call quoted, bought, delivered or cut: there is no batch row to carry a mark
            'prerequisiteMarkQuoted' => null,
            'prerequisiteMarkOrdered' => null,
            'prerequisiteMarkDelivered' => null,
            'prerequisiteMarkCut' => null,
            //Nor a job to call finished: this batch has not been nested, let alone bought and cut
            'prerequisiteMarkDone' => null,
            //Nor anything for a certificate to be evidence of - nothing here has been bought yet
            'canAttachCertificates' => null,
        ];

        /*
         * The offcuts each batch being quoted was given, which is half of what deciding "can this
         * still be unpicked" takes - an offcut that has since been cut into cannot be handed back.
         *
         * One query for the column rather than one per card, which is how the board asks it
         * (KanbanFormatter::quotingColumn). Asked only of the batches that can be re-nested at all:
         * the gate is the most expensive thing on this page after the nesting itself, and a batch
         * with an order out is past the point of answering yes.
         */
        $offcutsByBatch = Offcut::query()
            ->whereIn('batch_to_id', $staged[BatchStages::QUOTING]->pluck('id')->all())
            ->get()
            //Keyed by hand, the column having no cast on it - a string key would miss every lookup
            ->groupBy(fn (Offcut $offcut) => (int) $offcut->batch_to_id);

        /*
         * Which of the fully ordered batches have had all their steel turn up, for the pill below.
         * One query for the page, like the three below it, rather than a read of the orders per card.
         */
        $delivered = $this->fullyDeliveredBatchIds($staged[BatchStages::DELIVERING]);

        /*
         * And which of the batches out with the suppliers have a price in for every merchant they
         * have to buy from, which is the difference between the QUOTING and QUOTED pills - see
         * milestoneOf(). One query for the column, in the same way.
         */
        $fullyQuoted = $this->fullyQuotedBatchIds($staged[BatchStages::QUOTING], $supplierGroups);

        /*
         * And which of them have had every merchant marked ordered on the order list. The card's
         * pill has to agree with that modal: a batch whose only block says "Ordered" is a batch that
         * has been bought, and leaving it reading Quoting means the page contradicts itself.
         */
        $fullyMarkedOrdered = $this->fullyMarkedOrderedBatchIds($staged[BatchStages::QUOTING], $supplierGroups);

        /*
         * And the offcuts each batch being quoted has already handed on, which is the other half of
         * that question: a batch whose offcuts a later nest has cut into cannot be unpicked, because
         * unpicking it deletes them. One query for the column, like the one above it and for the same
         * reason - the gate asked it per card.
         */
        $producedOffcutsConsumed = Offcut::query()
            ->whereIn('batch_from_id', $staged[BatchStages::QUOTING]->pluck('id')->all())
            ->whereNotNull('batch_to_id')
            ->distinct()
            ->pluck('batch_from_id')
            ->map(fn ($batchId) => (int) $batchId)
            ->all();

        /*
         * Which jobs are on which batch, and the jobs themselves - asked here, before the cards are
         * built, because the prerequisites inside the loop want them too.
         *
         * Those gates read the manager and the done flag of every project on the batch
         * (PrerequisiteConditions::canChangeBatchItself, undoStartQuoting), and left to themselves
         * they fetch that per card - which on this page is the same handful of rows read again a card
         * at a time. The loop hands each batch what it has already loaded; see
         * Batch::seedProjectApprovalFlags.
         */
        $batchIds = collect([BatchStages::QUOTING, BatchStages::ORDERING, BatchStages::DELIVERING])
            ->flatMap(fn (string $stage) => $staged[$stage]->pluck('id'))
            ->all();

        /*
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
            /*
             * The last four are the edit modal's, not the card's - see projectCards(). done and
             * the manager's business are neither: they are what the prerequisite gates read, and they
             * are selected here so that those gates do not go and read them again per card.
             */
            ->select([
                'id',
                'name',
                'user_id',
                //Who put the list on for them, which is half of whose card this is - see mineOf()
                'created_by_user_id',
                'done',
                'reference',
                'date_materials_required',
                'date_fabrication_begins',
                'tentative',
            ])
            ->with('user:id,business_id')
            ->whereIn('id', $projectIdsByBatch->flatten()->unique()->all())
            //Oldest first, which is the order the cards name them in
            ->orderBy('id')
            ->get();

        foreach ([BatchStages::QUOTING, BatchStages::ORDERING, BatchStages::DELIVERING] as $stage) {
            foreach ($staged[$stage]->sortByDesc('id') as $batch) {
                /*
                 * What this card's gates would otherwise fetch for themselves - the jobs on the batch,
                 * and whether its offcuts have been handed on. Seeded rather than passed, so the gates
                 * keep the signatures every other caller uses and answer for themselves when nobody
                 * has done the work for them.
                 */
                $batch->seedProjectApprovalFlags(
                    $projects->whereIn('id', $projectIdsByBatch->get($batch->id, []))
                );
                $batch->seedProducedOffcutsConsumed(
                    in_array($batch->id, $producedOffcutsConsumed, true)
                );

                $milestone = $this->milestoneOf(
                    $batch,
                    $stage,
                    in_array($batch->id, $delivered, true),
                    in_array($batch->id, $fullyQuoted, true),
                    in_array($batch->id, $fullyMarkedOrdered, true),
                );

                $batches[] = [
                    'id' => $batch->id,
                    'stage' => $milestone,
                    //Filled below with the rest of what its projects answer - see the loop at the end
                    'materialsRequiredDate' => null,
                    'criticalPathDeadline' => null,
                    'daysBehindCriticalPath' => 0,
                    //Nothing to order by: this batch has been nested, so the deadline it had is spent
                    'orderingTriggerDate' => null,
                    'projects' => [],
                    'cutCount' => 0,
                    'categoryCount' => 0,
                    'mine' => false,
                    /*
                     * Whether the card's menu may offer to unpick it, and null where that is not a
                     * question the card can ask at all - which is the board's own convention for
                     * this flag (KanbanMinimalCard draws the button off it being set).
                     *
                     * Read off the pill rather than off the column, because the pill is the honest
                     * answer: re-nesting deletes the quotes, the draft orders and the offcuts the
                     * batch cut, so it is offered while the batch is still only being priced -
                     * QUOTING and QUOTED - and nowhere past that. Every batch in a later column has
                     * a sent order behind it, and a batch somebody marked "All ordered" from this
                     * menu has been bought off the application, where unpicking it here would strip
                     * material that a merchant is already cutting.
                     *
                     * Drawn greyed rather than dropped when it answers false, the way the board
                     * greys it, because "this batch can no longer be re-nested" is the thing
                     * somebody came to the menu to find out.
                     */
                    'prerequisiteUndoStartQuoting' => in_array($milestone, ['QUOTING', 'QUOTED'], true)
                        ? (new PrerequisiteConditions)->undoStartQuoting(
                            $batch,
                            $user,
                            $offcutsByBatch->get($batch->id, collect()),
                        )
                        : null,
                    /*
                     * And whether it may offer the two presses that move a batch on without naming a
                     * supplier - "All quoted" and "All ordered". Same convention again: false is
                     * greyed with the reason, null is a card that does not ask the question.
                     *
                     * The Quoting column alone, like the one above. Past it the batch has a sent
                     * order behind it and what the pill says is read off the orders and their
                     * deliveries - a mark set there could only contradict them, which is why the
                     * gate refuses it too (PrerequisiteConditions::markBatchQuoted).
                     */
                    'prerequisiteMarkQuoted' => $stage === BatchStages::QUOTING
                        ? (new PrerequisiteConditions)->markBatchQuoted($user, $batch)
                        : null,
                    'prerequisiteMarkOrdered' => $stage === BatchStages::QUOTING
                        ? (new PrerequisiteConditions)->markBatchOrdered($user, $batch)
                        : null,
                    //And "Delivered", which is the same kind of mark: the steel turned up, nobody named
                    'prerequisiteMarkDelivered' => $stage === BatchStages::QUOTING
                        ? (new PrerequisiteConditions)->markBatchDelivered($user, $batch)
                        : null,
                    /*
                     * "Cut" is the exception among the four. It is asked of a delivered batch however
                     * that batch got delivered - the marks above, or real orders booked in on their
                     * goods receipts - so it is drawn off the pill rather than the column, and the
                     * pill is the answer the gate would otherwise go and work out again.
                     */
                    'prerequisiteMarkCut' => in_array($milestone, ['DELIVERED', 'CUT'], true)
                        ? (new PrerequisiteConditions)->markBatchCut($user, $batch, true)
                        : null,
                    /*
                     * And the way off the page: "Move to done", which closes the batch and sends its
                     * projects to Past Projects.
                     *
                     * The same two cards "Cut" is offered on, read off the pill for the same reason -
                     * a batch is finished when its steel is in, however it got there, and the pill is
                     * where that has already been worked out. The press itself is the board's, which
                     * is where it lived until the board was deleted; without it on this card nothing
                     * in the application closes a batch at all and this list only grows. See
                     * PrerequisiteConditions::markBatchDone.
                     */
                    'prerequisiteMarkDone' => in_array($milestone, ['DELIVERED', 'CUT'], true)
                        ? (new PrerequisiteConditions)->markBatchDone($user, $batch, true)
                        : null,
                    /*
                     * And whether the card may offer to keep the merchant's paperwork against the
                     * batch itself. The same cards as "Cut", for the same reason in reverse: a
                     * certificate arrives with the steel, and a batch that has not been delivered has
                     * nothing to show one for. A closed batch is its own record and takes no more.
                     */
                    'canAttachCertificates' => in_array($milestone, ['DELIVERED', 'CUT'], true)
                        ? ! $batch->done
                        : null,
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
        $cuts = Piece::query()
            ->whereIn('batch_id', $batchIds)
            ->groupBy('batch_id')
            ->selectRaw('batch_id, '.self::CUT_SUM.' as cuts')
            ->get()
            ->keyBy('batch_id');

        /*
         * Which jobs they are, because the card is headed by them - your own project's name rather
         * than a batch number, and the rest of the batch behind the "other projects" label it hovers
         * open - was asked for above the loop, the gates in it wanting the same rows.
         *
         * And how many suppliers each has to be bought from, which is the third number on a card. The
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
            $batches[$index]['materialsRequiredDate'] = $this->materialsRequiredDate($batchProjects);
            /*
             * Read off the card's own pill rather than off the column it came out of, because the
             * pill is the step the work has actually reached: a batch somebody marked "All ordered"
             * from the card menu sits in the Quoting column and has been bought, and holding it to
             * the day it should have stopped quoting would chase it for a job already done.
             */
            $batches[$index]['criticalPathDeadline'] = $this->criticalPathDeadline(
                $batches[$index]['materialsRequiredDate'],
                $batch['stage'],
                $business,
            );
            $batches[$index]['daysBehindCriticalPath'] = $this->daysBehindCriticalPath(
                $batches[$index]['criticalPathDeadline'],
            );
            $batches[$index]['cutCount'] = (int) round((float) ($cuts->get($batch['id'])->cuts ?? 0));
            $batches[$index]['categoryCount'] = $this->supplierGroupCount(
                $categoriesByBatch->get($batch['id'], []),
                $supplierGroups,
            );
            $batches[$index]['mine'] = $this->mineOf($batchProjects, $user);
        }

        return Inertia::render('NestingIndex', [
            'batches' => $batches,
            /*
             * The staff a new project can be created for, for this page's own "+ Materials" modal -
             * the same facility the board and the upload page have, because the person with the
             * spreadsheet is often not the person running the job. See User::colleagueOptions.
             */
            'colleagues' => $user->colleagueOptions(),
            //Whether the open batch card's menu can offer to nest what is waiting - see above
            'prerequisiteStartQuoting' => $prerequisiteStartQuoting,
        ]);
    }

    /**
     * The day this card's material has to be at the workshop.
     *
     * The earliest fabrication start among the jobs on it, less one working day: the steel has to be
     * in the shop before the saw starts, and a job starting on the Monday wants it there on the
     * Friday - not on the Sunday, when the yard is shut and nobody is there to take a delivery.
     *
     * The subtraction itself is Project::materialsRequiredDate, which the fabrication deadline
     * warning and the bell read the same day off - the card and the email that chases it cannot be a
     * day apart about when the steel is wanted. All this adds is which of the jobs on the card sets
     * it, which is the earliest of them.
     *
     * Deliberately not KanbanFormatter::orderingTriggerDate, which is a different day about a
     * different thing: that one is when the open batch has to stop waiting and be quoted, five days
     * out, and it is what the fabrication deadline warnings chase. This is the deadline the work
     * itself has rather than one about a batch's progress, which is why every card on the page
     * carries it and not just the one that has not been nested yet.
     *
     * Null when no job on the card names a fabrication date. That is the open batch with nothing
     * waiting on it, and projects created before the date was asked for (see the
     * add_date_fabrication_begins migration) - a card of those has no deadline to print.
     *
     * @param  Collection<int, Project>  $projects
     */
    private function materialsRequiredDate(Collection $projects): ?string
    {
        $earliest = $projects
            ->pluck('date_fabrication_begins')
            ->filter()
            ->map(fn ($date) => Carbon::parse($date))
            ->min();

        return Project::materialsRequiredDate($earliest?->toDateString())
            ?->toDateString();
    }

    /**
     * The day this card had to have reached the step it is on, if it is to make its delivery date.
     *
     * The required-by date above is the end of the path and says the same thing to every card on the
     * page. This is what that date means *here*: the work the card still owes, counted back off it.
     * A batch being quoted owes the quoting time and the delivery time both - it is being priced now,
     * so none of the quoting is behind it - where one already bought owes the delivery alone, and on
     * the same required-by date those two are days apart. The pill is coloured off the difference
     * between this day and today (RequiredByPill), so a column of cards reads as how each one is
     * tracking against its own remaining work rather than as a row of dates all counting down to the
     * same morning.
     *
     * The step is read as the work in front of it rather than the work behind it, which is what makes
     * a card honest about being late while something can still be done. A batch out with the
     * merchants two days before its steel is wanted, on a 2 + 3 path, is three days behind and has
     * been since before anybody looked at it - reading QUOTING as "the quoting is done" would have
     * the card calling that a day's slip.
     *
     * The business's own two figures, which is what the critical path has been since the lead times
     * moved onto the business - see Project::quotingDays() and Project::longestDeliveryDays(). Read
     * off the page's business rather than through those methods: every job on every card here belongs
     * to this business, so the answer is the same for all of them, and asking per project is a walk
     * back through each one's manager to the same two columns.
     *
     * Working days, matching Project's deadlines and the required-by date this counts back from. A
     * merchant does not price over the weekend and does not deliver on a Sunday, so counting these
     * as calendar days spent the shop's weekend on the merchant's behalf and made every card two
     * days optimistic once a week. Carbon::subWeekdays steps over it; a figure of zero leaves the
     * date alone, which is the shop that collects off the rack the morning it needs the steel.
     *
     * Null for a card with nothing left to chase, which draws on time:
     *
     *  - No required-by date, so there is no path to be behind on. The pill is not drawn at all.
     *  - DELIVERED or CUT. The steel is in; the batch met its path, whatever today is.
     */
    private function criticalPathDeadline(?string $requiredDate, string $milestone, Business $business): ?string
    {
        if ($requiredDate === null) {
            return null;
        }

        $daysStillToSpend = match ($milestone) {
            /*
             * Nothing is priced yet, so the whole critical path is still in front of it - the quoting
             * and then the delivery. QUOTING sits here rather than with the steps below because a
             * batch out with the merchants is being priced now: the quoting is the work in hand, not
             * work it has finished, and the card has to owe it.
             */
            'NESTING', 'QUOTING' => (int) $business->quoting_days + (int) $business->delivery_days,
            /*
             * Priced, part way through being bought, or bought outright - and in all three the thing
             * still to come is the delivery, which is the lead time the steel takes to turn up once
             * it has been paid for. Placing the order is the press that sits between them and takes
             * no lead time of its own, so the three owe the same.
             */
            'QUOTED', 'ORDERING', 'ORDERED' => (int) $business->delivery_days,
            //DELIVERED, CUT, and anything a later milestone adds past them
            default => null,
        };

        if ($daysStillToSpend === null) {
            return null;
        }

        return Carbon::parse($requiredDate)
            ->subWeekdays($daysStillToSpend)
            ->toDateString();
    }

    /**
     * How far past that day this card already is, which is the one number the card is coloured off.
     *
     * Positive is behind: one means the step should have been finished yesterday, two or more means
     * the batch cannot make its date without somebody buying time back. Zero or less is on track, and
     * the day itself counts as on track - a deadline is the last day it can be met, not the first one
     * missed. Nothing to chase answers zero, which is every card with no required-by date and every
     * batch whose steel is already in.
     *
     * Working days, like the deadline it is measured against. A deadline missed on the Friday is one
     * working day behind on the Monday, not three - the merchant was not pricing anything over the
     * weekend and no amount of calendar has been lost. Counted in calendar days this would turn half
     * the page red every Monday morning and quietly green again by Tuesday, which teaches people to
     * ignore the colour.
     *
     * Answered here rather than in the template, where it used to be. Two things read it now - the
     * pill's colour and the action footer under the card - and a page that worked it out twice could
     * draw a card that is green and tells you to do something about it. It is also the one of these
     * numbers that cannot be had honestly in the browser: Moment has no notion of a working day.
     */
    private function daysBehindCriticalPath(?string $deadline): int
    {
        if ($deadline === null) {
            return 0;
        }

        return (int) Carbon::parse($deadline)->diffInWeekdays(Carbon::today(), false);
    }

    /**
     * Whether this card is one of yours, which is the whole of what the "Only my projects" switch asks.
     *
     * A job you manage, or one you put on the system for somebody else - Project::isManagedBy, the same
     * line that decides whose material lists you may change. The switch is on by default, so anything
     * it does not count as yours is a card you do not see until you go looking: a draftsman who uploads
     * for the project managers all day was managing none of them, which made the one screen in the
     * application open empty on his own work, every time.
     *
     * Deliberately wider here than on the jobs inside the card, where "mine" stays the project manager
     * alone (see projectCards). That flag draws the pencil, and renaming a colleague's job or moving
     * its fabrication date is theirs to do - PrerequisiteConditions::editProject refuses everyone else.
     * Being shown a card and being allowed to edit what is on it are different questions, and the
     * switch is only asking the first.
     *
     * @param  Collection<int, Project>  $projects
     */
    private function mineOf(Collection $projects, User $user): bool
    {
        return $projects->contains(fn (Project $project) => $project->isManagedBy($user));
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
     * @return array<int, array{id: int, name: string, manager: string|null, mine: bool, uploaded: bool, reference: string|null, date_materials_required: string|null, date_fabrication_begins: string|null, tentative: bool|null}>
     */
    private function projectCards(Collection $projects, int $userId, Collection $staffNames): array
    {
        return $projects
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'manager' => $staffNames->get($project->user_id),
                'mine' => $project->user_id === $userId,
                /*
                 * And whether you are the one who put it on the system for them, which is a different
                 * question and is answered separately on purpose - see mineOf(). The card is headed
                 * by a job you uploaded when none of the jobs on it are your own, so that a card the
                 * switch kept for you says on its face why you are looking at it.
                 */
                'uploaded' => $project->created_by_user_id === $userId,
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
     * this job got. So the words are what has been done to it, and the halfway steps are the ing-words:
     *
     *  - QUOTING: nested and out with the suppliers, with a price still missing for at least one of the
     *    merchants it has to be bought from - including a batch nobody has sent a quote request for yet.
     *  - QUOTED: every supplier group on the batch has a sent quote behind it, and no order has gone in.
     *    Both of these are the Quoting column, which does not distinguish them.
     *  - ORDERING: an order has gone in and there is material on the job nobody has bought yet, which
     *    is the Ordering column.
     *  - ORDERED: it is all bought, and some of it has not arrived (the Delivering column).
     *  - DELIVERED: it is all bought and every sent order has been booked in as delivered.
     *
     * The batch's own marks are read first, inside the Quoting column, and they say the same things
     * for a shop that does not buy through the quotes modal at all: quoted_at is "the prices are in",
     * ordered_at is "it has been bought", delivered_at is "it turned up", all set by hand from this
     * page's card menu and none of them naming a supplier (see the 2026_10_03 migrations). They are
     * read nowhere past that column, because past it there are real orders to read instead.
     *
     * "It has been bought" has a second spelling, and the pill has to accept it: the order list marks
     * one merchant at a time, and a batch whose every merchant has been marked there is as bought as
     * one somebody called bought in a single press. A card still reading Quoting over a modal whose
     * only block says Ordered is the page disagreeing with itself.
     *
     * That last one is the whole reason this is not a relabelling of the columns. The Delivering column
     * means every material row points at a sent order, which is a statement about the paperwork going
     * out, not about steel arriving - a card reading "Delivered" on that alone would be telling the
     * shop its material is in the rack while it is still on a lorry.
     *
     * A fully delivered batch closes itself five days after its last goods receipt
     * (App\Services\DeliveredBatchAutoDone), so DELIVERED is what a card says in that window, and for
     * as long as a hold on the auto-done sweep keeps it on the board.
     */
    private function milestoneOf(
        Batch $batch,
        string $stage,
        bool $everyOrderDelivered,
        bool $everyCategoryQuoted,
        bool $everyGroupMarkedOrdered,
    ): string {
        /*
         * Cut is the last step there is and the only one a batch can reach from either side of the
         * board, so it is read before the column is: a batch bought over the phone and a batch
         * ordered through the application are both cut in the same shop by the same people, and the
         * card says so for whichever of them got there.
         */
        if ($batch->cut_at !== null) {
            return 'CUT';
        }

        if ($stage === BatchStages::QUOTING) {
            if ($batch->delivered_at !== null) {
                return 'DELIVERED';
            }

            if ($batch->ordered_at !== null || $everyGroupMarkedOrdered) {
                return 'ORDERED';
            }

            return $everyCategoryQuoted || $batch->quoted_at !== null ? 'QUOTED' : 'QUOTING';
        }

        if ($stage === BatchStages::ORDERING) {
            return 'ORDERING';
        }

        return $everyOrderDelivered ? 'DELIVERED' : 'ORDERED';
    }

    /**
     * Of the batches out with the suppliers, the ones with a price in for every merchant.
     *
     * "Every merchant" is the card's own Material order count - the supplier groups this batch's
     * material falls into (see supplierGroupCount) - because that is the number the pill is read
     * against: a card saying "Quoted" over "2 Categories" is claiming both of them are priced.
     *
     * A sent quote, not a quote row: a quote row can be drafted long before anything was asked of
     * anybody, the way Project::percentageOfMaterialsQuoted counts a row quoted. There is one quote
     * per group now, so the group is priced once its own quote comes back.
     *
     * A batch whose material belongs to no supplier group the business's plan covers can never answer
     * yes, and says QUOTING for as long as it is in the column. That is the honest reading: there is
     * nobody to get a price from, which is a batch somebody still has work to do on.
     *
     * @param  Collection<int, \App\Models\Batch>  $quoting
     * @param  array<string, array<int, string>>  $supplierGroups
     * @return array<int, int>
     */
    private function fullyQuotedBatchIds(Collection $quoting, array $supplierGroups): array
    {
        $batchIds = $quoting->pluck('id')->all();

        if ($batchIds === []) {
            return [];
        }

        //What each batch has to buy, and what has been priced - a handful of rows per batch either way
        $categoriesByBatch = Piece::query()
            ->select(['batch_id', 'product_category'])
            ->whereIn('batch_id', $batchIds)
            ->distinct()
            ->get()
            ->groupBy('batch_id')
            ->map(fn ($rows) => $rows->pluck('product_category')->all());

        $quotedGroupsByBatch = Quote::query()
            ->select(['batch_id', 'supplier_category'])
            ->whereIn('batch_id', $batchIds)
            ->where('quote_sent', true)
            ->distinct()
            ->get()
            ->groupBy('batch_id')
            ->map(fn ($rows) => $rows->pluck('supplier_category')->all());

        $fullyQuoted = [];

        foreach ($batchIds as $batchId) {
            $required = $this->supplierGroupsFor(
                $categoriesByBatch->get($batchId, []),
                $supplierGroups,
            );

            if ($required === []) {
                continue;
            }

            $quoted = $quotedGroupsByBatch->get($batchId, []);

            if (array_diff($required, $quoted) === []) {
                $fullyQuoted[] = (int) $batchId;
            }
        }

        return $fullyQuoted;
    }

    /**
     * Of the batches out with the suppliers, the ones whose every merchant has been marked ordered.
     *
     * The order list marks one block at a time, for the shop that is half on the application and half
     * on the phone (see BatchMarkGroupOrderedController). Those marks are about merchants, and this
     * is the one question the card asks of them: is there a merchant left on this batch nobody has
     * bought from? When there is not, the whole job has been bought, and the pill says ORDERED
     * exactly as it does for the batch-wide mark.
     *
     * "Every merchant" is read the same way fullyQuotedBatchIds reads it - the supplier groups this
     * batch's material falls into, which is the card's own Material order count - so the two pills
     * are measured against the same list.
     *
     * A batch whose material belongs to no supplier group the business's plan covers answers no, the
     * way it answers no to being fully quoted: there is nobody to have bought from, and an empty list
     * must not read as a finished one.
     *
     * @param  Collection<int, \App\Models\Batch>  $quoting
     * @param  array<string, array<int, string>>  $supplierGroups
     * @return array<int, int>
     */
    private function fullyMarkedOrderedBatchIds(Collection $quoting, array $supplierGroups): array
    {
        //Only the batches somebody has marked something on - the rest cannot answer yes
        $marked = $quoting->filter(fn (Batch $batch) => ($batch->ordered_supplier_groups ?? []) !== []);

        if ($marked->isEmpty()) {
            return [];
        }

        $categoriesByBatch = Piece::query()
            ->select(['batch_id', 'product_category'])
            ->whereIn('batch_id', $marked->pluck('id')->all())
            ->distinct()
            ->get()
            ->groupBy('batch_id')
            ->map(fn ($rows) => $rows->pluck('product_category')->all());

        $fullyOrdered = [];

        foreach ($marked as $batch) {
            $required = $this->supplierGroupsFor(
                $categoriesByBatch->get($batch->id, []),
                $supplierGroups,
            );

            if ($required === []) {
                continue;
            }

            if (array_diff($required, $batch->ordered_supplier_groups ?? []) === []) {
                $fullyOrdered[] = (int) $batch->id;
            }
        }

        return $fullyOrdered;
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
        return count($this->supplierGroupsFor($productCategories, $supplierGroups));
    }

    /**
     * Those groups by name, which is the same question the pill asks - see fullyQuotedBatchIds().
     *
     * The names are the keys of SupplierFormatter::supplierGroups, which is what a quote records in
     * supplier_category, so the two lists can be compared directly.
     *
     * @param  array<int, string|null>  $productCategories
     * @param  array<string, array<int, string>>  $supplierGroups
     * @return array<int, string>
     */
    private function supplierGroupsFor(array $productCategories, array $supplierGroups): array
    {
        $matched = [];

        foreach ($supplierGroups as $supplierGroup => $includedProducts) {
            if (array_intersect($productCategories, $includedProducts) !== []) {
                $matched[] = (string) $supplierGroup;
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
     * earliest fabrication date among them - see KanbanFormatter::orderingTriggerDate. The pieces
     * come back beside them because the "Start quoting" gate is asked of both, and asking
     * piecesReadyForBatching a second time for it would be the most expensive read on the page run
     * twice.
     *
     * The empty check first, as App\Services\FabricationDeadlineQuoting does it: with nothing
     * unbatched there is no pending batch whatever the clarifications say, and that skips a
     * price book match per material row of every project the business has ever had.
     *
     * @return array{pieces: Collection<int, Piece>, projects: Collection<int, Project>}
     */
    private function pendingBatch(Business $business): array
    {
        $piecesReadyForBatching = (new NestingFormatter)->piecesReadyForBatching($business);

        if ($piecesReadyForBatching->isEmpty()) {
            return ['pieces' => $piecesReadyForBatching, 'projects' => collect()];
        }

        return [
            'pieces' => $piecesReadyForBatching,
            'projects' => $business->projectsReadyForBatching($piecesReadyForBatching),
        ];
    }
}
