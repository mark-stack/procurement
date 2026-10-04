<?php

use App\Enums\GoodsReceiptNonconformanceEnums;
use App\Formatters\NestingFormatter;
use App\Formatters\SupplierFormatter;
use App\Models\Batch;
use App\Models\MaterialCertificate;
use App\Models\Order;
use App\Models\Piece;
use App\Models\Project;
use App\Models\Quote;
use App\Models\User;
use App\Services\BatchStages;
use App\Services\DataClassificationService;
use App\Services\FabricationDeadlineQuoting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * A project sitting in the board's Nesting column: real material rows, real pieces, no batch.
 *
 * Named apart from FabricationDeadlineQuotingTest's nestingColumnProject() - pest loads every test
 * file into the one process, so a second declaration of that name is a fatal, not an override.
 */
function nestingPageProject(User $user, ?string $fabricationDate = null): Project
{
    $dataClassificationService = new DataClassificationService;

    $project = createProject($user);
    $project->update(['date_fabrication_begins' => $fabricationDate]);

    $sampleBOM = sampleBOM($project, $dataClassificationService, [[2500, 5], [1500, 2]]);
    createPieces($sampleBOM, $project, $dataClassificationService);

    return $project->fresh();
}

/**
 * The parts a project comes to - its pieces' quantities, not its piece rows.
 *
 * The card counts cuts off the saw, so a test that counted rows would pass on a fixture where every
 * row happens to be a quantity of one and say nothing about the sum the page actually makes.
 */
function cutsOf(Project $project): int
{
    return (int) round(
        Piece::query()->where('project_id', $project->id)->get()->sum(
            fn (Piece $piece) => (float) $piece->actual_qty
        )
    );
}

/**
 * The merchants a project has to be bought from - steel merchant, timber merchant and the rest.
 *
 * What the Order list button counts, because that modal comes in a block per supplier group and each
 * block is a list to send somebody. Asked of the same map the modal groups by, so a fixture of PFC
 * sections answers 1 rather than "one per section type".
 */
function categoriesOf(Project $project): int
{
    $business = $project->user->business;

    $productCategories = Piece::query()
        ->where('project_id', $project->id)
        ->distinct()
        ->pluck('product_category')
        ->all();

    return collect((new SupplierFormatter)->supplierGroups($business))
        ->filter(fn (array $includedProducts) => array_intersect($productCategories, $includedProducts) !== [])
        ->count();
}

/**
 * A project as the card names it: the name, whose job it is, and whether that is you.
 *
 * Mirrors NestingIndexController::projectCards(), because the assertions below compare a whole card -
 * the heading is the projects on the batch now, not its number, so the names and the ownership are the
 * card rather than a detail of it.
 *
 * The last four are what the pencil beside the name opens the board's edit modal on. Nothing draws
 * them, so they are asserted here rather than nowhere: a card that stopped carrying them would draw
 * the pencil exactly as it does now and open a form with the project's dates blank, offering to clear
 * them.
 */
function projectCard(Project $project, User $user): array
{
    return [
        'id' => $project->id,
        'name' => $project->name,
        'manager' => $project->user->name,
        'mine' => $project->user_id === $user->id,
        'reference' => $project->reference,
        'date_materials_required' => $project->date_materials_required,
        //Trimmed to the date part, the way the controller trims it - see projectCards()
        'date_fabrication_begins' => $project->date_fabrication_begins
            ? substr((string) $project->date_fabrication_begins, 0, 10)
            : null,
        'tentative' => $project->tentative,
    ];
}

/**
 * A sent order on a batch, which is what moves it off the Quoting column.
 *
 * Its own name for the same reason as above - PastProjectsTest declares pastProjectOrder().
 */
function nestingPageSentOrder(User $user, Batch $batch, string $supplierCategory = 'STEEL_MERCHANT'): Order
{
    $quote = Quote::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_category' => $supplierCategory,
        'supplier_quote_reference' => null,
        'quote_sent' => true,
        'quoted_price' => null,
        'quoted_lead_time' => null,
    ]);

    return Order::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'quote_id' => $quote->id,
        'order_sent' => true,
        'is_delivered' => false,
    ]);
}

it('names the last step each batch has passed, down the three columns of live batches', function () {
    /*
     * The pill is the whole status of a card, so it says how far the batch has got rather than which
     * column it is parked in - and the cards come down the page in the order those columns run across
     * the board.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    //Quoting: nested, and nothing priced or ordered yet
    $quoting = Batch::factory()->forUser($user->id)->create(['done' => false]);

    /*
     * Delivering: an order has gone and there is no material row left unordered. A batch with no
     * pieces on it reads that way, which is how BatchStages::everyRowOrdered() answers it too. The
     * order has not been delivered, so the pill is ORDERED - see the test below.
     */
    $delivering = Batch::factory()->forUser($user->id)->create(['done' => false]);
    nestingPageSentOrder($user, $delivering);

    //Done, so this page is not where it is read - see the test below
    Batch::factory()->forUser($user->id)->create(['done' => true]);

    expect((new BatchStages)->of($quoting))->toBe(BatchStages::QUOTING);
    expect((new BatchStages)->of($delivering))->toBe(BatchStages::DELIVERING);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('NestingIndex')
            ->has('batches', 3)
            //The open batch heads the page with nothing waiting on it - see the test below
            ->where('batches.0', ['id' => null, 'stage' => 'NESTING', 'materialsRequiredDate' => null, 'criticalPathDeadline' => null, 'daysBehindCriticalPath' => 0, 'orderingTriggerDate' => null, 'projects' => [], 'cutCount' => 0, 'categoryCount' => 0, 'mine' => false, 'prerequisiteUndoStartQuoting' => null, 'prerequisiteMarkQuoted' => null, 'prerequisiteMarkOrdered' => null, 'prerequisiteMarkDelivered' => null, 'prerequisiteMarkCut' => null, 'canAttachCertificates' => null])
            /*
             * Nothing to order by on either of the live ones: a nested batch has spent the deadline it
             * was waiting on. Nor anything to be required by - neither carries a project, so there is no
             * fabrication date to work back from. And neither is "mine" - a batch with no project on it
             * carries nobody's work, whoever created the row.
             *
             * The re-nest gate is asked of the one being quoted and of nothing else: past that column an
             * order has gone out and the answer is not a question the card can ask - which the open
             * batch's null above says for the other end of the page. False here because the gate
             * wants one of your own projects on the batch, and this one carries none at all.
             */
            ->where('batches.1', ['id' => $quoting->id, 'stage' => 'QUOTING', 'materialsRequiredDate' => null, 'criticalPathDeadline' => null, 'daysBehindCriticalPath' => 0, 'orderingTriggerDate' => null, 'projects' => [], 'cutCount' => 0, 'categoryCount' => 0, 'mine' => false, 'prerequisiteUndoStartQuoting' => false, 'prerequisiteMarkQuoted' => false, 'prerequisiteMarkOrdered' => false, 'prerequisiteMarkDelivered' => false, 'prerequisiteMarkCut' => null, 'canAttachCertificates' => null])
            ->where('batches.2', ['id' => $delivering->id, 'stage' => 'ORDERED', 'materialsRequiredDate' => null, 'criticalPathDeadline' => null, 'daysBehindCriticalPath' => 0, 'orderingTriggerDate' => null, 'projects' => [], 'cutCount' => 0, 'categoryCount' => 0, 'mine' => false, 'prerequisiteUndoStartQuoting' => null, 'prerequisiteMarkQuoted' => null, 'prerequisiteMarkOrdered' => null, 'prerequisiteMarkDelivered' => null, 'prerequisiteMarkCut' => null, 'canAttachCertificates' => null])
        );
});

it('says Quoting until every merchant on the batch has priced it, and Quoted once they have', function () {
    /*
     * The two pills the Quoting column carries. The column cannot tell them apart - it means "no order
     * has gone in" whichever it is - and the difference is the thing somebody reads the card for: are
     * the prices in, or is one still being chased.
     *
     * Measured against the card's own Material order count, which is the supplier groups this batch's
     * material falls into, and against SENT quotes: a quote row can be drafted long before anything
     * was asked of anybody.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    //Steel and nothing else, so one price is the whole batch
    expect(categoriesOf($project))->toBe(1);

    //Drafted and not sent, which is what opening the modal leaves behind
    $quote = Quote::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_category' => 'STEEL_MERCHANT',
        'supplier_quote_reference' => null,
        'quote_sent' => false,
        'quoted_price' => null,
        'quoted_lead_time' => null,
    ]);

    /*
     * And a sent one for a merchant this batch does not buy from. It is a price on the batch, and it
     * prices none of what is on it - a card that counted sent quotes rather than covered groups would
     * call this batch quoted.
     */
    Quote::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_category' => 'TIMBER_MERCHANT',
        'supplier_quote_reference' => null,
        'quote_sent' => true,
        'quoted_price' => null,
        'quoted_lead_time' => null,
    ]);

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.id', $batch->id)
            ->where('batches.1.stage', 'QUOTING')
        );

    $quote->update(['quote_sent' => true]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.id', $batch->id)
            ->where('batches.1.stage', 'QUOTED')
        );
});

it('says Delivered when the steel has turned up, not when the last order went out', function () {
    /*
     * The pill that does not line up with a column, and the reason this page cannot just reword the
     * board's. The Delivering column means every material row points at a sent order - paperwork out,
     * not steel in - so two batches sitting in it side by side get different pills: the one whose
     * orders have all been booked in as delivered, and the one still waiting on a lorry.
     *
     * is_delivered is the flag, because that is the one that answers "did it turn up": a delivery
     * booked in before the goods receipt columns existed has it and has no receipt behind it, and
     * reading received_at here would call all of those undelivered.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $arrived = Batch::factory()->forUser($user->id)->create(['done' => false]);
    nestingPageSentOrder($user, $arrived)->update(['is_delivered' => true]);

    //Two orders on this one and only the first of them in - a batch is delivered when all of it is.
    //Two groups, because the quotes table takes one row per group per batch.
    $partlyArrived = Batch::factory()->forUser($user->id)->create(['done' => false]);
    nestingPageSentOrder($user, $partlyArrived)->update(['is_delivered' => true]);
    nestingPageSentOrder($user, $partlyArrived, 'FASTENERS');

    //Both of them in the same column, which is what makes the two pills below worth asserting
    expect((new BatchStages)->of($arrived))->toBe(BatchStages::DELIVERING);
    expect((new BatchStages)->of($partlyArrived))->toBe(BatchStages::DELIVERING);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            //The open batch, which is always the first of them, and these two
            ->has('batches', 3)
            //Latest first within the column, which is the batch created second
            ->where('batches.1.id', $partlyArrived->id)
            ->where('batches.1.stage', 'ORDERED')
            ->where('batches.2.id', $arrived->id)
            ->where('batches.2.stage', 'DELIVERED')
        );
});

it('leaves a finished batch to /past-projects, however much of it is mine', function () {
    /*
     * There is nothing on a delivered batch left to nest or to buy, which is every button a card
     * carries, and that list only grows - it would bury the handful of batches somebody opened this page
     * to work on. Asserted with the batch carrying this user's own project, because that is the one a
     * card would be most tempted to draw: it passes the "Only my projects" test and still has no business
     * here.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user);
    $done = Batch::factory()->forUser($user->id)->create(['done' => true]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $done->id]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            /*
             * Nothing but the open batch, and nothing on that either: the project's material is on
             * the finished batch, so none of it is waiting.
             */
            ->has('batches', 1)
            ->where('batches.0.id', null)
            ->where('batches.0.projects', [])
        );
});

it('calls a batch Ordering while there is material on it nobody has bought', function () {
    /*
     * The column between the other two, and the one a card cannot guess at: an order has gone in and
     * there is still material on the job nobody has bought. The stage test is BatchStages', so this
     * builds the case it answers ORDERING to - pieces on the batch whose rows point at no sent order.
     *
     * Half-bought is the ing-word, the way half-quoted is: ORDERED is said of a batch with nothing
     * left to buy, which is the Delivering column and the test below.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    //Sent, but nothing on the batch is attached to it
    nestingPageSentOrder($user, $batch);

    expect((new BatchStages)->of($batch))->toBe(BatchStages::ORDERING);

    //Or the category assertion below would be comparing zero with zero
    expect(categoriesOf($project))->toBeGreaterThan(0);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            //The open batch, with nothing waiting on it, and this one
            ->has('batches', 2)
            ->where('batches.1', ['id' => $batch->id, 'stage' => 'ORDERING', 'materialsRequiredDate' => null, 'criticalPathDeadline' => null, 'daysBehindCriticalPath' => 0, 'orderingTriggerDate' => null, 'projects' => [projectCard($project, $user)], 'cutCount' => cutsOf($project), 'categoryCount' => categoriesOf($project), 'mine' => true, 'prerequisiteUndoStartQuoting' => null, 'prerequisiteMarkQuoted' => null, 'prerequisiteMarkOrdered' => null, 'prerequisiteMarkDelivered' => null, 'prerequisiteMarkCut' => null, 'canAttachCertificates' => null])
        );
});

it('cards the batch that does not exist yet, ahead of the ones that do', function () {
    /*
     * The Nesting column is a batch in waiting - "Start quoting" nests everything in it into one
     * batch - so it gets a card with no id, at the top, the way that column sits left of the rest.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $waiting = nestingPageProject($user);
    $existing = Batch::factory()->forUser($user->id)->create(['done' => false]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches', 2)
            //No fabrication date on the project, so there is no trigger - nothing will auto-quote it
            ->where('batches.0', ['id' => null, 'stage' => 'NESTING', 'materialsRequiredDate' => null, 'criticalPathDeadline' => null, 'daysBehindCriticalPath' => 0, 'orderingTriggerDate' => null, 'projects' => [projectCard($waiting, $user)], 'cutCount' => cutsOf($waiting), 'categoryCount' => categoriesOf($waiting), 'mine' => true, 'prerequisiteUndoStartQuoting' => null, 'prerequisiteMarkQuoted' => null, 'prerequisiteMarkOrdered' => null, 'prerequisiteMarkDelivered' => null, 'prerequisiteMarkCut' => null, 'canAttachCertificates' => null])
            ->where('batches.1', ['id' => $existing->id, 'stage' => 'QUOTING', 'materialsRequiredDate' => null, 'criticalPathDeadline' => null, 'daysBehindCriticalPath' => 0, 'orderingTriggerDate' => null, 'projects' => [], 'cutCount' => 0, 'categoryCount' => 0, 'mine' => false, 'prerequisiteUndoStartQuoting' => false, 'prerequisiteMarkQuoted' => false, 'prerequisiteMarkOrdered' => false, 'prerequisiteMarkDelivered' => false, 'prerequisiteMarkCut' => null, 'canAttachCertificates' => null])
        );
});

it('tells the pending card the day it stops being pending', function () {
    /*
     * The "Order by" date, which only the Nesting column has: the day the fabrication deadline sweep
     * warns that everything waiting has to be quoted. Read off KanbanFormatter::orderingTriggerDate,
     * the way the board's card reads it, so the two screens cannot promise different days - and off
     * the sweep's own constant, so neither of them promises a day the schedule has stopped keeping.
     *
     * This page no longer draws it - its cards print the day the material is wanted on site instead
     * (see the test below) - but it is still sent, because the "Start quoting" dialog decides off it
     * whether waiting for a bigger batch is advice or is now late.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    //The earliest fabrication date among the waiting projects is what the date is taken from
    nestingPageProject($user, now()->addDays(30)->toDateString());
    nestingPageProject($user, now()->addDays(12)->toDateString());

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches', 1)
            ->where('batches.0.stage', 'NESTING')
            ->where('batches.0.orderingTriggerDate', now()
                ->addDays(12 - FabricationDeadlineQuoting::DAYS_BEFORE_FABRICATION)
                ->toDateString())
        );
});

it('prints the day each card\'s material is wanted on site, a working day before fabrication', function () {
    /*
     * The "Required by" date, which every card on the page carries rather than only the one that has
     * not been nested yet. It is a fact about the work, not about a batch's progress: the steel has to
     * be in the shop before the saw starts, and that is as true of a batch already out with the
     * merchants as of one still waiting.
     *
     * A working day before the earliest fabrication date among the jobs on the card, so a Monday start
     * is wanted on the Friday - a date landing on the weekend would be promising a delivery on a day
     * the yard is shut and nobody is there to take it. The dates below are written out rather than
     * counted off today for that reason: which day of the week the answer lands on is the thing being
     * asserted.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    //Waiting to be nested: a Monday start, so the weekend has to be stepped over
    nestingPageProject($user, '2026-11-02');

    /*
     * And one that has been nested, carrying two jobs - the earliest of them is what the card answers,
     * the same way the open batch takes the earliest of what is waiting on it.
     */
    $nested = Batch::factory()->forUser($user->id)->create(['done' => false]);
    $earlier = nestingPageProject($user, '2026-11-04');
    $later = nestingPageProject($user, '2026-11-20');
    Piece::query()
        ->whereIn('project_id', [$earlier->id, $later->id])
        ->update(['batch_id' => $nested->id]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches', 2)
            //Monday the 2nd, so the Friday before it rather than the Sunday
            ->where('batches.0.stage', 'NESTING')
            ->where('batches.0.materialsRequiredDate', '2026-10-30')
            //And the Wednesday job on the nested batch, which is simply the day before
            ->where('batches.1.id', $nested->id)
            ->where('batches.1.materialsRequiredDate', '2026-11-03')
        );
});

it('counts each card\'s deadline back from the work that card still has left to do', function () {
    /*
     * The colour of the "Required by" pill, which is the half of it that is not the same on every
     * card. The date says when the steel is wanted; the deadline sent beside it says when *this*
     * batch had to have reached the step it is on to still make that date, and the two are days apart
     * because the step is read as the work in front of the batch rather than the work behind it.
     *
     * Asserted as dates rather than as a colour: the colour is the difference between this day and
     * today, and a test that counted off today would pass tomorrow by saying nothing.
     *
     * Defaults here - 2 days to quote and 3 to deliver (Business::DEFAULT_*) - and the test below
     * covers a business that has set its own.
     *
     * Working days, which is what the dates below are chosen to prove. The 3rd is a Tuesday, so five
     * working days back is the Tuesday before it and not the Thursday a calendar count would give -
     * the weekend in between is not time the merchant was pricing anything. Written out rather than
     * counted off today for that reason: which days the answer steps over is the thing being
     * asserted.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    //A Wednesday start, so the steel is wanted on the Tuesday
    nestingPageProject($user, '2026-11-04');

    //And a batch out with the merchants, wanted on the same day
    $quoting = Batch::factory()->forUser($user->id)->create(['done' => false]);
    $outForPricing = nestingPageProject($user, '2026-11-04');
    Piece::query()->where('project_id', $outForPricing->id)->update(['batch_id' => $quoting->id]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches', 2)
            /*
             * Nothing priced yet, so the quoting and the delivery both still have to come out of the
             * 3rd: five days back off it.
             */
            ->where('batches.0.stage', 'NESTING')
            ->where('batches.0.materialsRequiredDate', '2026-11-03')
            ->where('batches.0.criticalPathDeadline', '2026-10-27')
            /*
             * And the same five for a batch out with the merchants, which is the point of this pair:
             * a batch being priced is doing the quoting now, so none of the quoting time is behind it
             * and the card owes every day of the path that a batch still waiting owes.
             */
            ->where('batches.1.id', $quoting->id)
            ->where('batches.1.stage', 'QUOTING')
            ->where('batches.1.materialsRequiredDate', '2026-11-03')
            ->where('batches.1.criticalPathDeadline', '2026-10-27')
        );
});

it('drops the quoting time off the deadline once the prices are in, and not before', function () {
    /*
     * QUOTED is the first step with the quoting actually behind it - every merchant has priced, and
     * what is left is to place the order and wait for the steel. So the deadline loses the quoting
     * half of the path and keeps the delivery: three days back off the required-by date rather than
     * five.
     *
     * The step either side of it is what gives this its edges. QUOTING still owes five (above),
     * because a batch being priced has not finished being priced; ORDERED still owes three (below),
     * because buying the steel is a press rather than a lead time.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user, '2026-11-04');
    //Marked quoted off the card menu, which is one of the two ways a card reads QUOTED - see milestoneOf()
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false, 'quoted_at' => now()]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.id', $batch->id)
            ->where('batches.1.stage', 'QUOTED')
            ->where('batches.1.materialsRequiredDate', '2026-11-03')
            ->where('batches.1.criticalPathDeadline', '2026-10-29')
        );
});

it('still owes the delivery on a batch that has been bought outright', function () {
    /*
     * ORDERED is bought and waiting, which is not the same as arrived: the steel still takes the
     * delivery lead time to turn up, so the batch had to have been bought that many days before it
     * is wanted. Holding it to the required-by date itself would have the card call a batch ordered
     * the day before its steel is due on time, when it is two days late and nothing can be done.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user, '2026-11-04');
    //Marked bought off the card menu, which is what ORDERED is said of - see milestoneOf()
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false, 'ordered_at' => now()]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.id', $batch->id)
            ->where('batches.1.stage', 'ORDERED')
            ->where('batches.1.materialsRequiredDate', '2026-11-03')
            ->where('batches.1.criticalPathDeadline', '2026-10-29')
        );
});

it('chases a half-bought batch on the ordering deadline, not the delivery one', function () {
    /*
     * ORDERING is a batch with an order out and material on it nobody has bought yet. That material
     * still needs the full delivery lead time after it goes in, so the batch is held to the day the
     * buying has to be finished - the same deadline a batch still being priced has - rather than to
     * the delivery date its bought half is already working to.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user, '2026-11-04');
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    //Sent, but nothing on the batch is attached to it - which is what BatchStages calls ORDERING
    nestingPageSentOrder($user, $batch);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.id', $batch->id)
            ->where('batches.1.stage', 'ORDERING')
            ->where('batches.1.materialsRequiredDate', '2026-11-03')
            //Three days back off the 3rd, the way a batch still being priced is
            ->where('batches.1.criticalPathDeadline', '2026-10-29')
        );
});

it('stops holding a batch to a deadline once its steel is in', function () {
    /*
     * A delivered or cut batch has met its critical path, whatever today is. No deadline is sent, so
     * the pill draws on time - it keeps its date, which is still what the job is working to, and
     * stops being something the page chases. A red pill on one would be the page raising an alarm
     * about a delivery that has already happened.
     *
     * The fabrication date here is deliberately in the past: the point is that it makes no
     * difference.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $deliveredProject = nestingPageProject($user, '2020-01-08');
    $delivered = Batch::factory()->forUser($user->id)->create(['done' => false, 'delivered_at' => now()]);
    Piece::query()->where('project_id', $deliveredProject->id)->update(['batch_id' => $delivered->id]);

    $cutProject = nestingPageProject($user, '2020-01-08');
    $cut = Batch::factory()->forUser($user->id)->create(['done' => false, 'cut_at' => now()]);
    Piece::query()->where('project_id', $cutProject->id)->update(['batch_id' => $cut->id]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches', 3)
            //Latest first by id within the column, so the cut batch is the one above
            ->where('batches.1.id', $cut->id)
            ->where('batches.1.stage', 'CUT')
            ->where('batches.1.materialsRequiredDate', '2020-01-07')
            ->where('batches.1.criticalPathDeadline', null)
            ->where('batches.2.id', $delivered->id)
            ->where('batches.2.stage', 'DELIVERED')
            ->where('batches.2.materialsRequiredDate', '2020-01-07')
            ->where('batches.2.criticalPathDeadline', null)
        );
});

it('says how many working days past its deadline each card already is', function () {
    /*
     * The number the card is coloured and footed off, counted server side so that the pill and the
     * action footer under it cannot reach two different answers about the same batch.
     *
     * Working days, like the deadline it is measured against - see
     * NestingIndexController::daysBehindCriticalPath(). Today is pinned to a Tuesday, because the
     * whole point of the unit is what it does to a weekend, and a test counting off the real today
     * would say something different every day of the week.
     *
     * The two cards here are the same job at two different steps, which is what makes the figure
     * mean anything: a batch still being priced owes the quoting and the delivery both and is three
     * days behind; the same batch with its prices in owes only the delivery and is one. Being late
     * is a fact about the step, not about the date.
     */
    Carbon::setTestNow('2026-11-03');

    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    //Fabrication on the Friday, so the steel is wanted on the Thursday before it
    $quoted = Batch::factory()->forUser($user->id)->create(['done' => false, 'quoted_at' => now()]);
    $priced = nestingPageProject($user, '2026-11-06');
    Piece::query()->where('project_id', $priced->id)->update(['batch_id' => $quoted->id]);

    //Created second, so it cards above the one before it
    $quoting = Batch::factory()->forUser($user->id)->create(['done' => false]);
    $outForPricing = nestingPageProject($user, '2026-11-06');
    Piece::query()->where('project_id', $outForPricing->id)->update(['batch_id' => $quoting->id]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            //Nothing waiting, so the open batch has no date and nothing to be behind on
            ->where('batches.0.stage', 'NESTING')
            ->where('batches.0.criticalPathDeadline', null)
            ->where('batches.0.daysBehindCriticalPath', 0)
            /*
             * Still being priced: five working days back off the 5th is the Thursday before, and
             * today is the Tuesday after that - three working days, the weekend in between being
             * days the merchant was not pricing anything.
             */
            ->where('batches.1.id', $quoting->id)
            ->where('batches.1.stage', 'QUOTING')
            ->where('batches.1.criticalPathDeadline', '2026-10-29')
            ->where('batches.1.daysBehindCriticalPath', 3)
            //And priced, which owes two days fewer and is therefore two days less behind
            ->where('batches.2.id', $quoted->id)
            ->where('batches.2.stage', 'QUOTED')
            ->where('batches.2.criticalPathDeadline', '2026-11-02')
            ->where('batches.2.daysBehindCriticalPath', 1)
        );

    Carbon::setTestNow();
});

it('counts a card that is still inside its deadline as not behind at all', function () {
    /*
     * The other side of the same number, which is what decides that a card gets no action footer.
     *
     * Negative rather than clamped to zero: it is how much room is left, and the pill reads anything
     * at or under zero as on track. The day itself counts as on track too - a deadline is the last
     * day the step can be finished, not the first one missed.
     */
    Carbon::setTestNow('2026-11-03');

    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    //A fortnight further out than the card above, and so still well inside its own deadline
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    $project = nestingPageProject($user, '2026-11-20');
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.id', $batch->id)
            ->where('batches.1.stage', 'QUOTING')
            ->where('batches.1.criticalPathDeadline', '2026-11-12')
            ->where('batches.1.daysBehindCriticalPath', -7)
        );

    Carbon::setTestNow();
});

it('leaves a batch whose steel is in with nothing to be behind on', function () {
    /*
     * A delivered or cut batch is sent no deadline, so it answers zero however long ago its date
     * was - which is what keeps an action footer off a card where there is nothing left to do. The
     * fabrication date here is years in the past and makes no difference.
     */
    Carbon::setTestNow('2026-11-03');

    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false, 'delivered_at' => now()]);
    $project = nestingPageProject($user, '2020-01-08');
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.id', $batch->id)
            ->where('batches.1.stage', 'DELIVERED')
            ->where('batches.1.materialsRequiredDate', '2020-01-07')
            ->where('batches.1.criticalPathDeadline', null)
            ->where('batches.1.daysBehindCriticalPath', 0)
        );

    Carbon::setTestNow();
});

it('counts the deadline back from the business\'s own lead times, not the platform\'s', function () {
    /*
     * The critical path is the business's two figures, set in /profile under "Business preferences" -
     * so a shop that gets prices back the same afternoon and collects off the merchant's rack is
     * chased on its own schedule rather than on the platform's 2 and 3.
     *
     * One day to quote and none to deliver here, which is the shop that collects off the merchant's
     * rack: a batch still being priced owes that single day, and one already priced owes nothing at
     * all - its deadline is the required-by date itself, because it can be fetched the morning it is
     * wanted. Both halves of the business's figures are read, and neither is the platform's 2 and 3.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $business->update(['quoting_days' => 1, 'delivery_days' => 0]);

    $user = createUser(1, $business, false, true);

    nestingPageProject($user, '2026-11-04');

    //Priced already, so it owes the delivery alone - which this business does not have
    $quoted = Batch::factory()->forUser($user->id)->create(['done' => false, 'quoted_at' => now()]);
    $priced = nestingPageProject($user, '2026-11-04');
    Piece::query()->where('project_id', $priced->id)->update(['batch_id' => $quoted->id]);

    //And still out for prices, so it owes the quoting day. Created second, so it cards first
    $quoting = Batch::factory()->forUser($user->id)->create(['done' => false]);
    $outForPricing = nestingPageProject($user, '2026-11-04');
    Piece::query()->where('project_id', $outForPricing->id)->update(['batch_id' => $quoting->id]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.0.stage', 'NESTING')
            ->where('batches.0.criticalPathDeadline', '2026-11-02')
            ->where('batches.1.id', $quoting->id)
            ->where('batches.1.stage', 'QUOTING')
            ->where('batches.1.criticalPathDeadline', '2026-11-02')
            ->where('batches.2.id', $quoted->id)
            ->where('batches.2.stage', 'QUOTED')
            ->where('batches.2.criticalPathDeadline', '2026-11-03')
        );
});

it('cards the open batch with nothing waiting on it, rather than leaving it off the page', function () {
    /*
     * The open batch is the only one an upload can join, and the page's "+ Materials" button puts work
     * on it - so it is the card somebody comes here to use, and the card they look at afterwards for
     * what they just uploaded. Left off until something is waiting, it is missing exactly then: on a
     * first upload, and for anybody whose colleagues' jobs have all been nested already.
     *
     * Empty, and empty the whole way down - the page greys its three buttons off this, there being no
     * material list, no stock to buy and no nest behind an open batch with nothing on it.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    //A project with no pieces is on nobody's Nesting column - see Business::projectsReadyForBatching
    createProject($user);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches', 1)
            ->where('batches.0', ['id' => null, 'stage' => 'NESTING', 'materialsRequiredDate' => null, 'criticalPathDeadline' => null, 'daysBehindCriticalPath' => 0, 'orderingTriggerDate' => null, 'projects' => [], 'cutCount' => 0, 'categoryCount' => 0, 'mine' => false, 'prerequisiteUndoStartQuoting' => null, 'prerequisiteMarkQuoted' => null, 'prerequisiteMarkOrdered' => null, 'prerequisiteMarkDelivered' => null, 'prerequisiteMarkCut' => null, 'canAttachCertificates' => null])
        );
});

it('lists a colleague\'s batches too, the way the board\'s columns do', function () {
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $mine = Batch::factory()->forUser($user->id)->create(['done' => false]);
    $theirs = Batch::factory()->forUser($colleague->id)->create(['done' => false]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            //The open batch, then both of theirs and mine
            ->has('batches', 3)
            //Latest first within the column, which is the newer id
            ->where('batches.1.id', $theirs->id)
            ->where('batches.2.id', $mine->id)
        );
});

it('calls a batch mine when it carries my project, whoever pressed the button', function () {
    /*
     * What the "Only my projects" switch filters on. A batch is several jobs bought as one, and whoever
     * pressed "Start quoting" swept in everything that was waiting - colleagues' jobs included - so the
     * batch row's own user_id says nothing about whose work is on it. The test is the project manager of
     * a project on the batch, which is the same line the board draws when it floats your cards up a
     * column.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    //My job, nested by a colleague: mine, because the steel on it is for my project
    $myProject = nestingPageProject($user);
    $theirBatch = Batch::factory()->forUser($colleague->id)->create(['done' => false]);
    Piece::query()->where('project_id', $myProject->id)->update(['batch_id' => $theirBatch->id]);

    //And the same thing the other way round - a batch I started that carries only their work
    $theirProject = nestingPageProject($colleague);
    $myBatch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()->where('project_id', $theirProject->id)->update(['batch_id' => $myBatch->id]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            //Nothing left unbatched, so the open card above these two is an empty one
            ->has('batches', 3)
            ->where('batches.0.projects', [])
            //Latest first within the column, which is the batch created second
            ->where('batches.1.id', $myBatch->id)
            ->where('batches.1.mine', false)
            ->where('batches.2.id', $theirBatch->id)
            ->where('batches.2.mine', true)
        );
});

it('calls the pending card mine as soon as any of the work waiting is mine', function () {
    /*
     * The card with no batch row behind it gets the same flag, read off the projects "Start quoting"
     * would sweep in. Without it the switch hides the one card on the page that is about to become your
     * batch - and the "+ Materials" button above it puts work there.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    nestingPageProject($colleague);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches', 1)
            ->where('batches.0.stage', 'NESTING')
            ->where('batches.0.mine', false)
        );

    //Now my own material list is waiting too, on the same card - it is one batch in waiting, not two
    nestingPageProject($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches', 1)
            ->has('batches.0.projects', 2)
            ->where('batches.0.mine', true)
        );
});

it('says whose each job on a card is, so the heading can name a colleague\'s', function () {
    /*
     * The card is headed by the jobs on the batch rather than by its number, so every project it
     * carries arrives named, owned and in order:
     *
     *  - Oldest first, which is the order the heading lists your own in.
     *  - mine on the project manager, not on whoever nested the batch - see projectCards().
     *  - The manager's name on all of them. The card only prints it for a job that is not yours, which
     *    is the card a batch carrying none of your work gets.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $theirs = nestingPageProject($colleague);
    $theirs->update(['name' => 'Mezzanine']);
    $mine = nestingPageProject($user);
    $mine->update(['name' => 'Warehouse frame']);

    $batch = Batch::factory()->forUser($colleague->id)->create(['done' => false]);
    Piece::query()
        ->whereIn('project_id', [$theirs->id, $mine->id])
        ->update(['batch_id' => $batch->id]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            //The empty open batch, and the one both jobs were nested onto
            ->has('batches', 2)
            ->where('batches.1.projects', [
                /*
                 * The colleague's, created first, named with the manager the card would print - and
                 * the whole card, the edit modal's four fields included, since this is the one
                 * assertion that spells a project card out in full. The dates are null because
                 * nestingPageProject() sets none; the reference and the tentative flag come off
                 * createProject().
                 */
                ['id' => $theirs->id, 'name' => 'Mezzanine', 'manager' => $colleague->name, 'mine' => false, 'reference' => $theirs->reference, 'date_materials_required' => null, 'date_fabrication_begins' => null, 'tentative' => $theirs->tentative],
                ['id' => $mine->id, 'name' => 'Warehouse frame', 'manager' => $user->name, 'mine' => true, 'reference' => $mine->reference, 'date_materials_required' => null, 'date_fabrication_begins' => null, 'tentative' => $mine->tentative],
            ])
            //A colleague nested it, and it is still mine: my job is on it
            ->where('batches.1.mine', true)
        );
});

it('hands each card what the pencil beside a job name opens', function () {
    /*
     * The pencil opens the board's edit modal on the card's own project, so the card has to carry what
     * that form opens on - the name, the reference, the two dates and the tentative flag. Nothing on
     * the page draws them, which is exactly why they are asserted: they would go missing silently and
     * the form would open offering to clear the dates it could not read.
     *
     * The fabrication date is the one with work in it. The column comes back from MySQL as
     * "2026-11-02 00:00:00" through a model with no cast, and a date input draws nothing at all for a
     * value it cannot parse - the same trimming ProjectResource does for the board.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user, now()->addDays(20)->toDateString());

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.0.projects.0.id', $project->id)
            ->where('batches.0.projects.0.reference', $project->reference)
            ->where('batches.0.projects.0.tentative', $project->tentative)
            ->where('batches.0.projects.0.date_materials_required', $project->date_materials_required)
            //The date part alone, which is all a date input can read
            ->where('batches.0.projects.0.date_fabrication_begins', now()->addDays(20)->toDateString())
        );
});

it('hands the page the colleagues a new project can be created for', function () {
    /*
     * The "+ Materials" button opens the board's own new-project modal, and that modal asks whose job
     * it is - the draftsman uploading a material list for a project manager. Without this the select
     * is empty and every upload from here lands under the uploader's name.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('colleagues')
            ->where('colleagues', fn ($colleagues) => collect($colleagues)
                ->pluck('id')
                ->contains($colleague->id))
        );
});

it('names the jobs on each card, a batch being several projects bought as one', function () {
    /*
     * What the card is headed by, and the count underneath it. Read off the pieces, which is how
     * Batch::projects() decides what is on a batch - and distinct, because a project puts a piece on
     * the batch per material row, so a raw read would name one job a dozen times.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $first = nestingPageProject($user);
    $second = nestingPageProject($user);

    /*
     * The fixture has to have a quantity on it worth summing, or "cuts" and "rows" would be the same
     * number and the assertion below would hold either way.
     */
    expect(cutsOf($first))->toBeGreaterThan(
        Piece::query()->where('project_id', $first->id)->count()
    );

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()
        ->whereIn('project_id', [$first->id, $second->id])
        ->update(['batch_id' => $batch->id]);

    //A third job, still waiting, so the pending card has a count of its own to report
    nestingPageProject($user);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches', 2)
            ->where('batches.0.stage', 'NESTING')
            ->has('batches.0.projects', 1)
            ->where('batches.1.id', $batch->id)
            //Both jobs, oldest first, which is the order the heading names them in
            ->where('batches.1.projects', [projectCard($first, $user), projectCard($second, $user)])
            /*
             * And the material rows behind those jobs, which is what the BOM button's second line
             * counts - both projects' rows, since both are on this batch.
             */
            ->where('batches.1.cutCount', cutsOf($first) + cutsOf($second))
        );
});

it('offers the open batch the press that closes it, on the board\'s own gate', function () {
    /*
     * The page's three-dot menu reaches the two presses that change a batch, and both are drawn off a
     * server gate rather than off anything the card can see - the controllers behind them abort 403,
     * so a menu drawn any other way offers a dead end.
     *
     * This is the first of them. "Start quoting" nests everything waiting into one batch under your
     * name, settling the suppliers and the delivery dates for every job it sweeps in, and the gate is
     * PrerequisiteConditions::startQuoting - the same one the board's Nesting card draws its button
     * from, so the two screens cannot disagree about whether the press is available.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    nestingPageProject($user);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('prerequisiteStartQuoting', true));
});

it('refuses the open batch that press when the work waiting is a colleague\'s', function () {
    /*
     * The gate wants one of your own projects in what is about to be nested. A page that offered the
     * press anyway would be offering to take a colleague's job into a batch under your name and then
     * answer 403 - so the menu greys the item and says whose work it is instead.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    nestingPageProject($colleague);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            //There is work waiting, and it is on the card - it is only the press that is refused
            ->has('batches.0.projects', 1)
            ->where('batches.0.mine', false)
            ->where('prerequisiteStartQuoting', false)
        );
});

it('offers a batch being quoted the way back, per batch', function () {
    /*
     * The second of the two presses: "Re-nest", which unpicks the batch and sends its projects back to
     * the open one. It deletes the quotes, the draft orders and the offcuts the batch cut, so it is
     * drawn off PrerequisiteConditions::undoStartQuoting - the gate BatchController::destroy aborts
     * 403 on, and the one the board's Quoting card greys its own button with.
     *
     * Per batch rather than per page, unlike the gate above: it is asked about one batch, and two
     * batches side by side in the same column can answer differently.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    expect((new BatchStages)->of($batch))->toBe(BatchStages::QUOTING);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.id', $batch->id)
            ->where('batches.1.stage', 'QUOTING')
            ->where('batches.1.prerequisiteUndoStartQuoting', true)
        );
});

it('would be a disaster if a card offered to unpick a batch that has been ordered', function () {
    /*
     * Re-nesting deletes the batch's orders, and the gate refuses once one of them has been sent -
     * which is the whole reason it exists. Past the Quoting column the question is not asked at all,
     * and the card says so with null rather than false: the board uses the same convention, drawing
     * the button only where the flag is set, so a card that started answering false here would put a
     * greyed "Re-nest" on every ordered and delivered batch on the page.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);
    nestingPageSentOrder($user, $batch);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.id', $batch->id)
            ->where('batches.1.prerequisiteUndoStartQuoting', null)
        );
});

it('calls a batch quoted on the press, with no supplier anywhere in the business', function () {
    /*
     * "All quoted" - the step for a shop that does not buy through the quotes and orders screen.
     *
     * Everything on that screen hangs off a supplier: a quote is a row per merchant, an order a row
     * per quote. This business has entered none, so there is nothing to tick and nothing the pill can
     * read - which is the case the mark exists for. Nothing is sent by it, so the test is the column
     * on the batch and the word on the card.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    //Nothing quoted or ordered on it, which is the whole point
    expect($batch->quotes()->count())->toBe(0);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.stage', 'QUOTING')
            ->where('batches.1.prerequisiteMarkQuoted', true)
            ->where('batches.1.prerequisiteMarkOrdered', true)
        );

    $this->post(route('batch.all.quoted', $batch->id))->assertRedirect();

    $batch->refresh();

    //Who said so, beside when - a claim about money with nobody's name on it is the one nobody can ask about
    expect($batch->quoted_at)->not->toBeNull()
        ->and($batch->quoted_by_user_id)->toBe($user->id)
        //And nothing was invented to carry it
        ->and($batch->quotes()->count())->toBe(0)
        ->and($batch->orders()->count())->toBe(0);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.stage', 'QUOTED')
            //Said once: the item greys rather than offering to set a date that is already set
            ->where('batches.1.prerequisiteMarkQuoted', false)
            ->where('batches.1.prerequisiteMarkOrdered', true)
        );
});

it('calls it ordered on the other press, and leaves the board column where it is', function () {
    /*
     * "All ordered", which is a claim that money has been spent and nothing more: no merchant is
     * contacted, no order row is marked sent, no pieces or bars are attached to one - all of which
     * OrderSentController does, because that one is a real order to a real supplier.
     *
     * So the batch does not move. The board's columns are built on sent orders and the goods receipts
     * that follow them, and a batch bought off the application has neither - BatchStages still answers
     * QUOTING, and the card is the only thing that says otherwise.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->post(route('batch.all.ordered', $batch->id))->assertRedirect();

    $batch->refresh();

    expect($batch->ordered_at)->not->toBeNull()
        ->and($batch->ordered_by_user_id)->toBe($user->id)
        ->and($batch->orders()->count())->toBe(0)
        //Still out for quote as far as every other screen is concerned
        ->and((new BatchStages)->of($batch))->toBe(BatchStages::QUOTING);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.stage', 'ORDERED')
            /*
             * Quoting is behind it now, so neither mark is on offer: one is spent and the other is a
             * step this batch has passed.
             */
            ->where('batches.1.prerequisiteMarkQuoted', false)
            ->where('batches.1.prerequisiteMarkOrdered', false)
            /*
             * And the way back has gone with it. The steel is being cut at a merchant this
             * application never saw, so there is nothing to unpick it into - the card stops asking,
             * the way it stops on a batch with a real sent order behind it.
             */
            ->where('batches.1.prerequisiteUndoStartQuoting', null)
        );
});

it('would be a disaster if a batch bought off the application could still be re-nested', function () {
    /*
     * Re-nesting deletes the nest, the quotes and the offcuts the batch cut. The gate has always
     * refused it once an order went out through the application - and a batch somebody marked "All
     * ordered" has been bought just as hard, over the phone, with no order row here to show for it.
     *
     * So the mark closes the way back, on the page and at the gate: the card stops offering it and
     * BatchController::destroy refuses the press that is no longer drawn.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    $this->actingAs($user);

    //Priced, not yet bought: QUOTED still offers it, the prices having changed nothing about the nest
    $this->post(route('batch.all.quoted', $batch->id))->assertRedirect();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.stage', 'QUOTED')
            ->where('batches.1.prerequisiteUndoStartQuoting', true)
        );

    $this->post(route('batch.all.ordered', $batch->id))->assertRedirect();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.stage', 'ORDERED')
            ->where('batches.1.prerequisiteUndoStartQuoting', null)
        );

    $this->delete(route('batches.destroy', $batch->id))->assertForbidden();

    //Still nested, with its projects where they were
    expect($batch->refresh()->pieces()->count())->toBeGreaterThan(0);
});

it('would be a disaster if a colleague with no job on the batch could call it bought', function () {
    /*
     * The same line the quotes screen draws row by row (PrerequisiteConditions::canChangeQuoteSentState):
     * you have to be a project manager on the batch. A press that moves the whole batch on must not be
     * the way round a gate the per-supplier screen applies - and "somebody else's steel has been
     * bought" is the single worst thing a colleague could write onto a card.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $owner = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $project = nestingPageProject($owner);
    $batch = Batch::factory()->forUser($owner->id)->create(['done' => false]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    $this->actingAs($colleague);

    $this->post(route('batch.all.quoted', $batch->id))->assertForbidden();
    $this->post(route('batch.all.ordered', $batch->id))->assertForbidden();

    expect($batch->refresh()->quoted_at)->toBeNull()
        ->and($batch->ordered_at)->toBeNull();

    //And the card says so rather than offering a press that 403s
    $this->actingAs($colleague)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.prerequisiteMarkQuoted', false)
            ->where('batches.1.prerequisiteMarkOrdered', false)
        );
});

it('would be a disaster if another business could mark a batch quoted or ordered', function () {
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    $stranger = createUser(1, createBusiness('somebody else'), false, true);

    $this->actingAs($stranger);

    $this->post(route('batch.all.quoted', $batch->id))->assertForbidden();
    $this->post(route('batch.all.ordered', $batch->id))->assertForbidden();

    expect($batch->refresh()->quoted_at)->toBeNull()
        ->and($batch->ordered_at)->toBeNull();
});

it('stops offering either mark once a real order has gone out', function () {
    /*
     * Past the Quoting column the pill is read off the orders and the deliveries behind them, so a
     * mark set there could only contradict them. Null rather than false, the way the re-nest gate
     * answers past the same point: the question is not asked on those cards at all.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);
    nestingPageSentOrder($user, $batch);

    $this->actingAs($user);

    //Exception handling left on: the two presses below are refused, and a 403 is the assertion
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.prerequisiteMarkQuoted', null)
            ->where('batches.1.prerequisiteMarkOrdered', null)
        );

    //And the endpoints refuse it too, a menu item being a suggestion rather than the decision
    $this->post(route('batch.all.quoted', $batch->id))->assertForbidden();
    $this->post(route('batch.all.ordered', $batch->id))->assertForbidden();
    $this->post(route('batch.all.delivered', $batch->id))->assertForbidden();
});

it('carries a batch bought over the phone all the way to cut, without a supplier anywhere', function () {
    /*
     * The four marks end to end, which is the shop this application has been opening up to: no
     * suppliers entered, nothing to tick on the quotes screen, and a job that is nevertheless quoted,
     * bought, delivered and cut.
     *
     * Each one is a word on the card and nothing else - no quote, no order, no goods receipt - and
     * the board never moves the batch, its columns being built on orders that really were sent.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->post(route('batch.all.quoted', $batch->id))->assertRedirect();
    $this->post(route('batch.all.ordered', $batch->id))->assertRedirect();

    //Delivered is offered from Quoting, Quoted and Ordered alike - see PrerequisiteConditions
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.stage', 'ORDERED')
            ->where('batches.1.prerequisiteMarkDelivered', true)
            //Nothing to cut yet, and the card does not ask: the steel has not arrived
            ->where('batches.1.prerequisiteMarkCut', null)
            ->where('batches.1.canAttachCertificates', null)
        );

    $this->post(route('batch.all.delivered', $batch->id))->assertRedirect();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.stage', 'DELIVERED')
            //Now it can be cut, and now the merchant's paperwork has somewhere to go
            ->where('batches.1.prerequisiteMarkCut', true)
            ->where('batches.1.canAttachCertificates', true)
            //Spent, every one of them, and greyed on the card rather than offered twice
            ->where('batches.1.prerequisiteMarkQuoted', false)
            ->where('batches.1.prerequisiteMarkOrdered', false)
            ->where('batches.1.prerequisiteMarkDelivered', false)
        );

    $this->post(route('batch.cut', $batch->id))->assertRedirect();

    $batch->refresh();

    expect($batch->quoted_at)->not->toBeNull()
        ->and($batch->ordered_at)->not->toBeNull()
        ->and($batch->delivered_at)->not->toBeNull()
        ->and($batch->cut_at)->not->toBeNull()
        ->and($batch->cut_by_user_id)->toBe($user->id)
        //Nothing was sent to anybody, and the board still has it out for quote
        ->and($batch->orders()->count())->toBe(0)
        ->and($batch->quotes()->count())->toBe(0)
        ->and((new BatchStages)->of($batch))->toBe(BatchStages::QUOTING);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.stage', 'CUT')
            ->where('batches.1.prerequisiteMarkCut', false)
        );
});

it('offers Cut on a batch delivered the ordinary way, which the other marks never touch', function () {
    /*
     * Cutting is the one step on that menu that is not about buying: a batch ordered through the
     * quotes screen and booked in on its goods receipts gets cut in the same shop by the same people.
     *
     * So the three buying marks stay null on it - the orders and receipts are the answer there, and a
     * mark could only contradict them - while Cut is offered, and the certificates can be added to
     * what the orders already carry.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    $order = nestingPageSentOrder($user, $batch);

    /*
     * Every material line on the batch pointing at that sent order, which is what puts a batch in the
     * Delivering column - see BatchStages::everyRowOrdered. Without it the batch is still Ordering,
     * with material nobody has bought, and the delivered question does not arise.
     */
    Piece::query()->where('project_id', $project->id)->update(['order_id' => $order->id]);

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    //Ordered and still on the lorry: nothing to cut, and the card says so by not asking
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.prerequisiteMarkCut', null)
        );

    //Every sent order booked in, which is what the DELIVERED pill is read from
    $order->update(['is_delivered' => true]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.stage', 'DELIVERED')
            ->where('batches.1.prerequisiteMarkCut', true)
            ->where('batches.1.canAttachCertificates', true)
            //Still none of its business: this batch was bought through a merchant
            ->where('batches.1.prerequisiteMarkQuoted', null)
            ->where('batches.1.prerequisiteMarkOrdered', null)
            ->where('batches.1.prerequisiteMarkDelivered', null)
        );

    $this->post(route('batch.cut', $batch->id))->assertRedirect();

    expect($batch->refresh()->cut_at)->not->toBeNull();
});

it('would be a disaster if a batch could be called cut before its steel arrived', function () {
    /*
     * "Cut" over a batch nobody has delivered is a claim about parts that do not exist, and the
     * offcuts the nest promised are the first thing somebody would go looking for. The card never
     * draws it there - the gate is asked again on the press, because a card is a suggestion.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    $this->actingAs($user);

    $this->post(route('batch.cut', $batch->id))->assertForbidden();

    expect($batch->refresh()->cut_at)->toBeNull();
});

it('would be a disaster if a colleague with no job on the batch could call it delivered or cut', function () {
    /*
     * The same line every other press on a batch draws: you have to be a project manager on it. These
     * two are claims about somebody else's steel arriving and somebody else's steel being cut, and a
     * colleague is exactly who must not be able to make them.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $owner = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $project = nestingPageProject($owner);
    $batch = Batch::factory()->forUser($owner->id)->create(['done' => false, 'delivered_at' => now()]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    $this->actingAs($colleague);

    $this->post(route('batch.all.delivered', $batch->id))->assertForbidden();
    $this->post(route('batch.cut', $batch->id))->assertForbidden();

    expect($batch->refresh()->cut_at)->toBeNull();

    //And the card says so rather than offering a press that 403s
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.1.prerequisiteMarkCut', false)
        );
});

it('would be a disaster if another business could mark a batch delivered or cut', function () {
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingPageProject($user);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false, 'delivered_at' => now()]);
    Piece::query()->where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    $this->actingAs(createUser(1, createBusiness('somebody else'), false, true));

    $this->post(route('batch.all.delivered', $batch->id))->assertForbidden();
    $this->post(route('batch.cut', $batch->id))->assertForbidden();

    expect($batch->refresh()->cut_at)->toBeNull();
});

it('reports a batch efficiency off the nest that was saved against it', function () {
    /*
     * The figure on the card is the one the batch was actually bought on, so it is read off the saved
     * nest rather than by nesting the batch again - a second run would answer with today's offcuts and
     * today's price book.
     */
    [$business, $user, $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    expect($batch->nested_state)->not->toBe([]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $efficiency = $this->getJson(route('nesting.efficiency'))->assertOk()->json('efficiency');

    expect($efficiency[$batch->id])->toBeGreaterThan(0)
        ->and($efficiency[$batch->id])->toBe(
            (new NestingFormatter)->usageStats($batch->nested_state)['METERAGE']['efficiency']
        );
});

it('stops reporting a batch efficiency once the batch is finished', function () {
    /*
     * No card to put the figure on any more, and reading it means unpacking the whole nest of the batch -
     * every bar, offcut and cut on it. Over a shop's history that is the most expensive thing this page
     * could do, all of it for nobody.
     */
    [$business, $user, $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    $this->actingAs($user);

    //The same nest, reported while the batch is live, so the assertion below is about "done" alone
    expect($this->getJson(route('nesting.efficiency'))->assertOk()->json('efficiency'))
        ->toHaveKey($batch->id);

    $batch->update(['done' => true]);

    $this->withoutExceptionHandling();
    $this->getJson(route('nesting.efficiency'))
        ->assertOk()
        ->assertJson(['efficiency' => []]);
});

it('leaves out a batch with no saved nest, rather than calling it 0%', function () {
    /*
     * nested_state is only written by Actions\Batch\SaveNesting, so a batch from before that - or one
     * whose nest could not be read - has none. Reported as 0% it would read as a terrible nest; left
     * out, the card says nothing, which is the truth.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);

    expect($batch->nested_state)->toBe([]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->getJson(route('nesting.efficiency'))
        ->assertOk()
        ->assertJson(['efficiency' => []]);
});

it("would be a disaster if another business's efficiency were reported", function () {
    [$business, $user, $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    $otherBusiness = createBusiness('competitor');
    $otherUser = createUser(1, $otherBusiness, false, true);

    $this->actingAs($otherUser);

    $this->withoutExceptionHandling();
    $this->getJson(route('nesting.efficiency'))
        ->assertOk()
        ->assertJson(['efficiency' => []]);
});

it('answers the BOM button with every project on the batch, each row naming its own', function () {
    /*
     * A batch is several jobs bought as one, so the card's BOM is the material rows of all of them -
     * and a row nobody can trace back to a project is unreadable, which is what the Project column
     * the table carries is for.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    //Named apart: createProject() calls every project "some project", and the column prints the name
    $first = nestingPageProject($user);
    $first->update(['name' => 'Warehouse frame']);
    $second = nestingPageProject($user);
    $second->update(['name' => 'Mezzanine']);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()
        ->whereIn('project_id', [$first->id, $second->id])
        ->update(['batch_id' => $batch->id]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $response = $this->getJson(route('download.batch.bom', $batch))->assertOk();

    $bom = $response->json('batchBom');

    expect($bom['batch_id'])->toBe($batch->id)
        ->and($bom['projectCount'])->toBe(2)
        ->and($bom['rows'])->toHaveCount(
            $first->rawMaterialQuotes()->count() + $second->rawMaterialQuotes()->count()
        );

    //Both jobs are on the one table, and every row says which it came from
    expect(collect($bom['rows'])->pluck('project')->unique()->sort()->values()->all())
        ->toBe(collect([$first->name, $second->name])->sort()->values()->all());

    //What the table prints, so a missing key is caught here rather than as a blank column
    expect($bom['rows'][0])->toHaveKeys([
        'id', 'project', 'description', 'product_label', 'nesting_algo',
        'length_required', 'width_required', 'sub_qty', 'assembly_mark', 'status',
    ]);
});

it('answers the pending card with what is waiting, there being no batch to ask for', function () {
    /*
     * The one card on the page with no id. Its BOM is the Nesting column: everything "Start quoting"
     * would sweep into a batch, which is why the route's batch is optional.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $waiting = nestingPageProject($user);

    //Already nested, so it is on a batch of its own and not on this table
    $nested = nestingPageProject($user);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::query()->where('project_id', $nested->id)->update(['batch_id' => $batch->id]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $bom = $this->getJson(route('download.batch.bom'))->assertOk()->json('batchBom');

    expect($bom['batch_id'])->toBeNull()
        ->and($bom['projectCount'])->toBe(1)
        ->and(collect($bom['rows'])->pluck('project')->unique()->all())->toBe([$waiting->name]);
});

it('answers the Order list button with the stock the saved nest needs, by supplier group', function () {
    /*
     * The same lists the quotes/orders modal's "Email tables" buttons write into a mail, read off the
     * nest the batch was bought on - so the order on screen and the order the merchant is sent are the
     * same order. The lines themselves are built in Shared/shared.js; what is asserted here is that the
     * three fields it reads arrive, per supplier group.
     */
    [$business, $user, $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $orderList = $this->getJson(route('batch.order.list', $batch))->assertOk()->json('orderList');

    expect($orderList['batch_id'])->toBe($batch->id)
        ->and($orderList['groups'])->not->toBeEmpty();

    $group = collect($orderList['groups'])->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect($group)->not->toBeNull()
        //What is on this batch, not every product the group could cover
        ->and($group['includedProducts'])->toContain('PFC')
        ->and($group['batchGroup'])->not->toBeEmpty();

    $item = $group['batchGroup'][0];

    expect($item)->toHaveKeys(['algo', 'product_derived_label', 'nested'])
        ->and($item['nested'])->toHaveKey('orderList')
        ->and($item['nested']['orderList'][0])->toHaveKeys(['count', 'result']);

    /*
     * Trimmed to those three fields. A nested piece carries every bar and cut on the batch with it,
     * and none of that belongs in a list of stock to buy.
     */
    expect(array_keys($item))->toBe(['algo', 'product_derived_label', 'nested']);
});

it('says on the order list which supplier groups have been ordered, and under what PO number', function () {
    /*
     * The list is read while the buying is half done, so each block has to say whether that merchant
     * is still waiting. Ordered is the sent order's question - a row provisioned by opening the
     * quotes/orders modal is not a purchase - and the PO number comes off that same order.
     */
    [$business, $user, $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    [, $order] = quoteAndOrder($user, $batch, orderSent: true);
    $order->update(['purchase_order_number' => 'PO-4471']);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $orderList = $this->getJson(route('batch.order.list', $batch))->assertOk()->json('orderList');

    $steel = collect($orderList['groups'])->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect($steel['ordered'])->toBeTrue()
        ->and($steel['purchaseOrderNumber'])->toBe('PO-4471');
});

it("hands the order list each group's mill certificates, and says which groups come with one at all", function () {
    /*
     * The block's third pill. Whether a group is certificated is products.certificates - the flag the
     * BOM's certificate column already reads - so timber and fasteners show nothing rather than a pill
     * that will never be satisfied. The files are links, not contents: they live on the private disk.
     */
    [$business, $user, $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    [, $order] = quoteAndOrder($user, $batch, orderSent: true);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $orderList = $this->getJson(route('batch.order.list', $batch))->assertOk()->json('orderList');

    $steel = collect($orderList['groups'])->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect($steel['certificated'])->toBeTrue()
        ->and($steel['certificates'])->toBe([]);

    $certificate = MaterialCertificate::factory()->forOrder($order->id)->create([
        'user_id' => $user->id,
        'original_filename' => 'heat-74412.pdf',
    ]);

    $orderList = $this->getJson(route('batch.order.list', $batch))->assertOk()->json('orderList');

    $steel = collect($orderList['groups'])->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect($steel['certificates'])->toHaveCount(1)
        ->and($steel['certificates'][0]['filename'])->toBe('heat-74412.pdf')
        ->and($steel['certificates'][0]['url'])
        ->toBe(route('material.certificates.download', $certificate));
});

it('shows the certificate a buyer attached before placing the order', function () {
    /*
     * Ordered and certificated are different questions asked of different rows. A certificate can go
     * on while the order is still a draft, and a block that holds the file but says "No mill cert"
     * sends somebody chasing the merchant for something already filed.
     */
    [$business, $user, $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    [, $order] = quoteAndOrder($user, $batch, orderSent: false);

    MaterialCertificate::factory()->forOrder($order->id)->create(['user_id' => $user->id]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $orderList = $this->getJson(route('batch.order.list', $batch))->assertOk()->json('orderList');

    $steel = collect($orderList['groups'])->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect($steel['ordered'])->toBeFalse()
        ->and($steel['certificates'])->toHaveCount(1);
});

it("says on the order list what the goods receipt recorded, and whether the load was accepted", function () {
    /*
     * The block's fourth pill, and the record behind it. Ordering is only half of buying: the question
     * read off this list a week later is whether the steel turned up and whether anybody looked at it,
     * which is what ISO 9001 8.6 asks and what the quotes/orders receipt panel writes.
     *
     * Accepted is not the two checks on their own - a recorded nonconformance fails the delivery
     * whatever they say, because steel can be the right grade and the right count and still arrive bent.
     */
    [$business, $user, $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    [, $order] = quoteAndOrder($user, $batch, orderSent: true);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $orderList = $this->getJson(route('batch.order.list', $batch))->assertOk()->json('orderList');

    $steel = collect($orderList['groups'])->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    //Ordered and still owed: nothing booked in, and nothing pretending to have been
    expect($steel['goodsReceipt']['received'])->toBeFalse()
        ->and($steel['goodsReceipt']['deliveredWithoutReceipt'])->toBeFalse()
        ->and($steel['goodsReceipt'])->not->toHaveKey('received_at');

    $order->update([
        'is_delivered' => true,
        'received_at' => now(),
        'received_by_user_id' => $user->id,
        'delivery_docket_number' => 'DN-884213',
        'quantity_verified' => true,
        'grade_verified' => false,
        'receipt_nonconformance' => GoodsReceiptNonconformanceEnums::WRONG_GRADE->value,
        'receipt_note' => 'GR250 came instead of GR300',
    ]);

    $orderList = $this->getJson(route('batch.order.list', $batch))->assertOk()->json('orderList');

    $steel = collect($orderList['groups'])->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect($steel['goodsReceipt']['received'])->toBeTrue()
        ->and($steel['goodsReceipt']['accepted'])->toBeFalse()
        ->and($steel['goodsReceipt']['received_at'])->not->toBeNull()
        ->and($steel['goodsReceipt']['received_by'])->toBe($user->name)
        ->and($steel['goodsReceipt']['docket_number'])->toBe('DN-884213')
        ->and($steel['goodsReceipt']['quantity_verified'])->toBeTrue()
        ->and($steel['goodsReceipt']['grade_verified'])->toBeFalse()
        //The enum's label rather than its stored value: this is read, not matched on
        ->and($steel['goodsReceipt']['nonconformance'])->toBe('Wrong grade or material')
        ->and($steel['goodsReceipt']['note'])->toBe('GR250 came instead of GR300');
});

it('tells a delivery nobody checked apart from one marked as arrived with no receipt at all', function () {
    /*
     * Two states that both fall short of an accepted delivery and are not the same thing. A receipt
     * with the checks unanswered is a record somebody wrote; a delivery ticked before the receipt
     * columns existed has no date, no name and nothing to open - and it cannot be filled in afterwards,
     * so the list says so rather than drawing an empty receipt.
     */
    [$business, $user, $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    [, $order] = quoteAndOrder($user, $batch, orderSent: true);

    //Booked in, with neither check answered
    $order->update([
        'is_delivered' => true,
        'received_at' => now(),
        'received_by_user_id' => $user->id,
    ]);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $orderList = $this->getJson(route('batch.order.list', $batch))->assertOk()->json('orderList');

    $steel = collect($orderList['groups'])->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect($steel['goodsReceipt']['received'])->toBeTrue()
        //Null, not false: an unanswered check is not a failed one
        ->and($steel['goodsReceipt']['accepted'])->toBeNull()
        ->and($steel['goodsReceipt']['quantity_verified'])->toBeNull()
        ->and($steel['goodsReceipt']['deliveredWithoutReceipt'])->toBeFalse();

    //The old way: the flag on its own
    $order->update(['received_at' => null, 'received_by_user_id' => null]);

    $orderList = $this->getJson(route('batch.order.list', $batch))->assertOk()->json('orderList');

    $steel = collect($orderList['groups'])->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect($steel['goodsReceipt']['received'])->toBeFalse()
        ->and($steel['goodsReceipt']['deliveredWithoutReceipt'])->toBeTrue()
        ->and($steel['goodsReceipt']['accepted'])->toBeNull();
});

it('has no goods receipt to show for a merchant nobody has ordered from', function () {
    /*
     * A receipt can only be written against a placed order, so the unsent row that exists because
     * somebody opened the quotes/orders modal has nothing to say about a delivery - and "not received"
     * beside "Not ordered" is the same sentence twice. The certificates are the other way round, and
     * deliberately: one can be attached before the order goes out.
     */
    [$business, $user, $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    quoteAndOrder($user, $batch, orderSent: false);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $orderList = $this->getJson(route('batch.order.list', $batch))->assertOk()->json('orderList');

    $steel = collect($orderList['groups'])->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect($steel['ordered'])->toBeFalse()
        ->and($steel['goodsReceipt'])->toBeNull();
});

it('does not ask a timber merchant for a mill certificate', function () {
    /*
     * LVL arrived from the spreadsheet flagged for certificates along with every other meterage
     * product. Timber does not come with a mill cert, so the flag was wrong, and this is what stops it
     * coming back - the Order list and the BOM both read it.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $certificated = (new NestingFormatter)->getCertificateProductLabels();

    expect($certificated)->toContain('UB')
        ->and($certificated)->not->toContain('LVL');
});

it('calls a group ordered on the strength of the sent order, not the PO number somebody has yet to type', function () {
    /*
     * Two things the block must not get wrong. An order row exists for every supplier the moment
     * somebody opens the quotes/orders modal, so an unsent one is not a purchase and the group is
     * still waiting. And purchase_order_number is nullable - filled in afterwards, and plenty of
     * merchants are ordered from without one - so a sent order with no number is still ordered.
     */
    [$business, $user, $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    [, $order] = quoteAndOrder($user, $batch, orderSent: false);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $orderList = $this->getJson(route('batch.order.list', $batch))->assertOk()->json('orderList');

    $steel = collect($orderList['groups'])->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect($steel['ordered'])->toBeFalse()
        ->and($steel['purchaseOrderNumber'])->toBeNull();

    //Sent, with nobody having typed a number against it
    $order->update(['order_sent' => true]);

    $orderList = $this->getJson(route('batch.order.list', $batch))->assertOk()->json('orderList');

    $steel = collect($orderList['groups'])->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect($steel['ordered'])->toBeTrue()
        ->and($steel['purchaseOrderNumber'])->toBeNull();
});

it('offers an order list for the batch that does not exist yet', function () {
    /*
     * The pending card has no saved nest to read, so this is the suggestion - the same run the board's
     * Nesting card makes to show its efficiency.
     */
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    nestingPageProject($user);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $orderList = $this->getJson(route('batch.order.list'))->assertOk()->json('orderList');

    expect($orderList['batch_id'])->toBeNull()
        ->and($orderList['groups'])->not->toBeEmpty();
});

it("would be a disaster if another business's order list could be read off its batch id", function () {
    /*
     * Every length of steel on somebody else's job, by id, from a page that lists only your own.
     */
    $business1 = createBusiness('biz1');
    $user1 = createUser(1, $business1, false, true);

    $business2 = createBusiness('biz2');
    $user2 = createUser(1, $business2, false, true);
    $theirBatch = Batch::factory()->forUser($user2->id)->create(['done' => false]);

    $this->actingAs($user1);

    $this->getJson(route('batch.order.list', $theirBatch))->assertForbidden();
});

it("would be a disaster if another business's BOM could be read off its batch id", function () {
    /*
     * The whole material list of a batch, by id, from a page that lists only your own. Without the
     * gate this is every job a competitor has on, on a route with no modal in front of it.
     */
    $business1 = createBusiness('biz1');
    $user1 = createUser(1, $business1, false, true);

    $business2 = createBusiness('biz2');
    $user2 = createUser(1, $business2, false, true);
    $theirBatch = Batch::factory()->forUser($user2->id)->create(['done' => false]);

    $this->actingAs($user1);

    $this->getJson(route('download.batch.bom', $theirBatch))->assertForbidden();
});

it("would be a disaster if another business's batches were listed", function () {
    $business1 = createBusiness('biz1');
    $user1 = createUser(1, $business1, false, true);

    $business2 = createBusiness('biz2');
    $user2 = createUser(1, $business2, false, true);
    Batch::factory()->forUser($user2->id)->create(['done' => false]);
    Batch::factory()->forUser($user2->id)->create(['done' => true]);

    $this->actingAs($user1);

    $this->withoutExceptionHandling();
    $this->get(route('dashboard'))
        ->assertOk()
        //Its own open batch and nothing else - the other business's live batch is not a card here
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches', 1)
            ->where('batches.0.id', null)
        );
});
