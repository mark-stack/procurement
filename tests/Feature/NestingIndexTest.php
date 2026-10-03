<?php

use App\Formatters\NestingFormatter;
use App\Formatters\SupplierFormatter;
use App\Models\Batch;
use App\Models\MaterialCertificate;
use App\Models\Order;
use App\Models\Piece;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BatchStages;
use App\Services\DataClassificationService;
use App\Services\FabricationDeadlineQuoting;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
function nestingPageSentOrder(User $user, Batch $batch): Order
{
    $supplier = Supplier::factory()->create();

    $quote = Quote::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_id' => $supplier->id,
        'supplier_category' => 'STEEL_MERCHANT',
        'supplier_quote_reference' => null,
        'quote_sent' => true,
        'quoted_price' => null,
        'quoted_lead_time' => null,
    ]);

    return Order::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_id' => $supplier->id,
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

    //Quoted: nested, and nothing ordered yet
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
    $this->get(route('nesting.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('NestingIndex')
            ->has('batches', 3)
            //The open batch heads the page with nothing waiting on it - see the test below
            ->where('batches.0', ['id' => null, 'stage' => 'NESTING', 'orderingTriggerDate' => null, 'projects' => [], 'cutCount' => 0, 'categoryCount' => 0, 'mine' => false])
            /*
             * Nothing to order by on either of the live ones: a nested batch has spent the deadline it
             * was waiting on. And neither is "mine" - a batch with no project on it carries nobody's
             * work, whoever created the row.
             */
            ->where('batches.1', ['id' => $quoting->id, 'stage' => 'QUOTED', 'orderingTriggerDate' => null, 'projects' => [], 'cutCount' => 0, 'categoryCount' => 0, 'mine' => false])
            ->where('batches.2', ['id' => $delivering->id, 'stage' => 'ORDERED', 'orderingTriggerDate' => null, 'projects' => [], 'cutCount' => 0, 'categoryCount' => 0, 'mine' => false])
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

    //Two orders on this one and only the first of them in - a batch is delivered when all of it is
    $partlyArrived = Batch::factory()->forUser($user->id)->create(['done' => false]);
    nestingPageSentOrder($user, $partlyArrived)->update(['is_delivered' => true]);
    nestingPageSentOrder($user, $partlyArrived);

    //Both of them in the same column, which is what makes the two pills below worth asserting
    expect((new BatchStages)->of($arrived))->toBe(BatchStages::DELIVERING);
    expect((new BatchStages)->of($partlyArrived))->toBe(BatchStages::DELIVERING);

    $this->actingAs($user);

    $this->withoutExceptionHandling();
    $this->get(route('nesting.index'))
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
    $this->get(route('nesting.index'))
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

it('calls a batch Ordered off the material rows, not off the order being sent', function () {
    /*
     * The column between the other two, and the one a card cannot guess at: an order has gone in and
     * there is still material on the job nobody has bought. The stage test is BatchStages', so this
     * builds the case it answers ORDERING to - pieces on the batch whose rows point at no sent order.
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
    $this->get(route('nesting.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            //The open batch, with nothing waiting on it, and this one
            ->has('batches', 2)
            ->where('batches.1', ['id' => $batch->id, 'stage' => 'ORDERED', 'orderingTriggerDate' => null, 'projects' => [projectCard($project, $user)], 'cutCount' => cutsOf($project), 'categoryCount' => categoriesOf($project), 'mine' => true])
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
    $this->get(route('nesting.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches', 2)
            //No fabrication date on the project, so there is no trigger - nothing will auto-quote it
            ->where('batches.0', ['id' => null, 'stage' => 'NESTING', 'orderingTriggerDate' => null, 'projects' => [projectCard($waiting, $user)], 'cutCount' => cutsOf($waiting), 'categoryCount' => categoriesOf($waiting), 'mine' => true])
            ->where('batches.1', ['id' => $existing->id, 'stage' => 'QUOTED', 'orderingTriggerDate' => null, 'projects' => [], 'cutCount' => 0, 'categoryCount' => 0, 'mine' => false])
        );
});

it('tells the pending card the day it stops being pending', function () {
    /*
     * The "Order by" date, which only the Nesting column has: the day the fabrication deadline sweep
     * nests everything waiting whether anybody presses the button or not. Read off
     * KanbanFormatter::orderingTriggerDate, the way the board's card reads it, so the two screens
     * cannot promise different days - and off the sweep's own constant, so neither of them promises a
     * day the schedule has stopped keeping.
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
    $this->get(route('nesting.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches', 1)
            ->where('batches.0.stage', 'NESTING')
            ->where('batches.0.orderingTriggerDate', now()
                ->addDays(12 - FabricationDeadlineQuoting::DAYS_BEFORE_FABRICATION)
                ->toDateString())
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
    $this->get(route('nesting.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches', 1)
            ->where('batches.0', ['id' => null, 'stage' => 'NESTING', 'orderingTriggerDate' => null, 'projects' => [], 'cutCount' => 0, 'categoryCount' => 0, 'mine' => false])
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
    $this->get(route('nesting.index'))
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
    $this->get(route('nesting.index'))
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
    $this->get(route('nesting.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches', 1)
            ->where('batches.0.stage', 'NESTING')
            ->where('batches.0.mine', false)
        );

    //Now my own material list is waiting too, on the same card - it is one batch in waiting, not two
    nestingPageProject($user);

    $this->get(route('nesting.index'))
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
    $this->get(route('nesting.index'))
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
    $this->get(route('nesting.index'))
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
    $this->get(route('nesting.index'))
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
    $this->get(route('nesting.index'))
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
    $this->get(route('nesting.index'))
        ->assertOk()
        //Its own open batch and nothing else - the other business's live batch is not a card here
        ->assertInertia(fn (Assert $page) => $page
            ->has('batches', 1)
            ->where('batches.0.id', null)
        );
});
