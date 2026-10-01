<?php

use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\Business;
use App\Models\Project;
use App\Models\User;
use App\Notifications\BatchReadyToQuoteEmail;
use App\Notifications\ColleagueOrderingBatchTodayEmail;
use App\Services\DataClassificationService;
use App\Services\FabricationDeadlineQuoting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * The catalogue a BOM is matched against. Nothing in the Nesting column exists without it - an empty
 * products table makes every material row match nothing, so no piece is created and no project is
 * ever ready for batching.
 */
function seededCatalogue(): void
{
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();
}

/**
 * A project sitting in the board's Nesting column: real material rows, real pieces, no batch.
 *
 * The long way round, through the same BOM matching an upload does, because the sweep nests what it
 * finds - and a hand-built fixture piece expands to no cuts at all (see nestedBatch() in Pest.php for
 * the same reasoning).
 */
function nestingColumnProject(User $user, string $fabricationDate, array $nest = [[2500, 5], [1500, 2]]): Project
{
    $dataClassificationService = new DataClassificationService;

    $project = createProject($user);
    $project->update(['date_fabrication_begins' => $fabricationDate]);

    $sampleBOM = sampleBOM($project, $dataClassificationService, $nest);
    createPieces($sampleBOM, $project, $dataClassificationService);

    return $project->fresh();
}

it('would be a disaster if the shop started cutting before anybody had quoted the steel', function () {
    /*
     * The Nesting column is a waiting room on purpose - the longer material sits there the better the
     * nest and the bulk price get. But nobody is watching it at 6am, and a project cannot still be
     * waiting there when the saw is about to start: the steel has to be quoted, ordered and delivered
     * first. Five days out is where the waiting has to stop.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingColumnProject($user, now()->addDays(3)->toDateString());

    expect((new NestingFormatter)->piecesReadyForBatching($business))->not->toBeEmpty();

    $batch = (new FabricationDeadlineQuoting)->sweepBusiness($business);

    expect($batch)->toBeInstanceOf(Batch::class);
    expect($batch->user_id)->toBe($user->id);

    //The column is empty, which is what "moved to Quoting" means on this board
    expect((new NestingFormatter)->piecesReadyForBatching($business))->toBeEmpty();
    expect($batch->projects()->pluck('id')->all())->toContain($project->id);
});

it('leaves a project alone while there is still time to accumulate more material', function () {
    /*
     * The other half of the rule, and the more important one. Sweeping early throws away the whole
     * reason the column exists: a batch started today is a nest, a set of quotes and a delivery that
     * tomorrow's material list can no longer join.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    nestingColumnProject($user, now()->addDays(20)->toDateString());

    expect((new FabricationDeadlineQuoting)->sweepBusiness($business))->toBeNull();
    expect(Batch::count())->toBe(0);
});

it('counts a fabrication date that has already passed as the most urgent of all', function () {
    /*
     * A project whose fabrication was due to start last week is more urgent than one starting on
     * Friday, not less. Reading the window as "between now and five days" rather than "no later than
     * five days" would leave it waiting in the Nesting column for good.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    nestingColumnProject($user, now()->subDays(2)->toDateString());

    expect((new FabricationDeadlineQuoting)->sweepBusiness($business))->toBeInstanceOf(Batch::class);
});

it('would be a disaster if the batch were quoted without telling the person who has to quote it', function () {
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingColumnProject($user, now()->addDays(2)->toDateString());

    Notification::fake();

    $batch = (new FabricationDeadlineQuoting)->sweepBusiness($business);

    Notification::assertSentTo($user, BatchReadyToQuoteEmail::class,
        function (BatchReadyToQuoteEmail $notification) use ($project, $batch) {
            expect($notification->project->id)->toBe($project->id);
            expect($notification->batch->id)->toBe($batch->id);

            //Both channels: a red dot they might see tomorrow is not enough for "order this today"
            return $notification->via($notification->recipient) === ['mail', 'database'];
        });
});

it('sends the recipient to the quotes modal rather than printing the material lists at them', function () {
    /*
     * The email used to carry the tables themselves. It does not any more: a supplier group's list is
     * a dozen lines of section and length that a mail client reflows into one grey paragraph, and in
     * that form it cannot be sent to a merchant - re-typing it out of an email is precisely the work
     * this application exists to remove.
     *
     * So the email's whole value is getting the recipient to the modal that writes those drafts for
     * them, in one tap, and saying what they will find there. A link that only reached the board
     * would leave them to find the right card and press Quotes.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    nestingColumnProject($user, now()->addDays(2)->toDateString());

    $batch = (new FabricationDeadlineQuoting)->sweepBusiness($business);

    $mail = (new BatchReadyToQuoteEmail($batch->projects()->first(), $batch, $user, 'anything'))
        ->toMail($user);

    $rendered = implode("\n", $mail->introLines);

    //It names the button that writes the supplier drafts, and says there is one per category
    expect($rendered)->toContain('Email tables');
    expect($rendered)->toContain('material order list');

    //And none of the list itself
    expect($rendered)->not->toContain('mm');

    /*
     * And the button actually lands on the modal. Followed rather than inspected: the action url is
     * an opaque MagicLink token, so the only honest way to ask where it goes is to go there.
     */
    test()->get($mail->actionUrl)
        ->assertRedirect(route('projects.index', ['quotes' => $batch->id]));
});

it('would be a disaster if a colleague found out their steel had been ordered afterwards', function () {
    /*
     * The sweep takes the WHOLE Nesting column, so a colleague's project is swept into a batch they
     * do not own, by a deadline that is not theirs, with nobody pressing anything. From that moment
     * they lose Edit, Archive and BOM upload on their own project and somebody else picks the
     * suppliers. The last chance to say "that BOM is not final" is today.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $urgent = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    nestingColumnProject($urgent, now()->addDays(2)->toDateString());
    $colleagueProject = nestingColumnProject($colleague, now()->addMonth()->toDateString());

    Notification::fake();

    $batch = (new FabricationDeadlineQuoting)->sweepBusiness($business);

    Notification::assertSentTo($colleague, ColleagueOrderingBatchTodayEmail::class,
        function (ColleagueOrderingBatchTodayEmail $notification) use ($colleagueProject, $batch, $urgent) {
            expect($notification->project->id)->toBe($colleagueProject->id);
            expect($notification->batch->id)->toBe($batch->id);
            expect($notification->colleague->id)->toBe($urgent->id);

            return true;
        });

    //And the person doing the ordering is not told that they will be ordering
    Notification::assertNotSentTo($urgent, ColleagueOrderingBatchTodayEmail::class);
});

it('picks the most recently added project when two share the same fabrication date', function () {
    /*
     * The tie-break decides who owns the batch, who gets the material tables and whose name is in
     * everybody else's inbox - so it cannot be left to whatever order the database returns rows in.
     * The latest arrival is the project whose BOM somebody was working on, so they are the colleague
     * with the job in front of them today.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $first = createUser(1, $business, false, true);
    $second = createUser(2, $business, false, true);

    $sameDate = now()->addDays(4)->toDateString();

    nestingColumnProject($first, $sameDate);
    $laterProject = nestingColumnProject($second, $sameDate);

    //Created in the same second in a test, so the id is what separates them - see triggerProject
    $batch = (new FabricationDeadlineQuoting)->sweepBusiness($business);

    expect($batch->user_id)->toBe($second->id);
    expect($laterProject->id)->toBeGreaterThan(Project::min('id'));
});

it('prefers the earliest fabrication date over the most recently added', function () {
    /*
     * The tie-break is only a tie-break. A project added this morning for a job that starts in five
     * days does not outrank one added last week for a job that starts tomorrow.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $soonest = createUser(1, $business, false, true);
    $later = createUser(2, $business, false, true);

    nestingColumnProject($soonest, now()->addDay()->toDateString());
    nestingColumnProject($later, now()->addDays(4)->toDateString());

    $batch = (new FabricationDeadlineQuoting)->sweepBusiness($business);

    expect($batch->user_id)->toBe($soonest->id);
});

it('would be a disaster if a swept column were swept again an hour later', function () {
    /*
     * It runs hourly. Without the column being genuinely empty afterwards this would mint a batch an
     * hour, each one holding nothing, and email the same people every time.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    nestingColumnProject($user, now()->addDays(2)->toDateString());

    expect((new FabricationDeadlineQuoting)->sweepBusiness($business))->toBeInstanceOf(Batch::class);
    expect((new FabricationDeadlineQuoting)->sweepBusiness($business))->toBeNull();

    expect(Batch::count())->toBe(1);
});

it('would be a disaster if a lapsed trial could still spend money on steel', function () {
    /*
     * BillingWriteAccessMiddleware makes a lapsed account read-only: the user cannot press "Start
     * quoting" themselves. A schedule that presses it for them would be the one way round that gate,
     * and it would commit them to a purchase order.
     */
    seededCatalogue();

    $business = lapsedTrialBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    nestingColumnProject($user, now()->addDays(2)->toDateString());

    expect($business->fresh()->allowsWrites())->toBeFalse();
    expect((new FabricationDeadlineQuoting)->sweepBusiness($business->fresh()))->toBeNull();
    expect(Batch::count())->toBe(0);
});

it('does not reach across businesses', function () {
    /*
     * The sweep walks every business on the platform. A project ready for batching is found through
     * its owner's business, so a deadline in one yard must not nest another's steel.
     */
    seededCatalogue();

    $urgentBusiness = createBusiness('urgent');
    $urgentUser = createUser(1, $urgentBusiness, false, true);
    nestingColumnProject($urgentUser, now()->addDays(2)->toDateString());

    $calmBusiness = createBusiness('calm');
    $calmUser = createUser(1, $calmBusiness, false, true);
    $calmProject = nestingColumnProject($calmUser, now()->addMonth()->toDateString());

    $created = (new FabricationDeadlineQuoting)->sweep();

    expect($created)->toHaveCount(1);
    expect($created[0]->user_id)->toBe($urgentUser->id);

    //The calm yard's material is still waiting where it was
    expect((new NestingFormatter)->piecesReadyForBatching($calmBusiness))->not->toBeEmpty();
    expect($calmProject->fresh()->pieces()->whereNotNull('batch_id')->exists())->toBeFalse();
});

it('would be a disaster if the board promised an ordering date the sweep did not keep', function () {
    /*
     * The Nesting card's header prints "Order by <date>", which is the earliest fabrication date in
     * the column less the days the sweep holds off for. Two places subtracting five days is one
     * place to forget, and the cost of forgetting is a board that tells a project manager they have
     * until Friday while the schedule buys on Wednesday - so the figure is computed off the same
     * constant, and this is the test that says so.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    //The later one first, so "earliest" cannot pass by accident on row order
    nestingColumnProject($user, now()->addDays(30)->toDateString());
    nestingColumnProject($user, now()->addDays(12)->toDateString());

    test()->actingAs($user);

    $column = test()->get(route('projects.index'))
        ->viewData('page')['props']['projects']['READY_FOR_NESTING'];

    $triggerDate = $column['orderingTriggerDate'];

    expect($triggerDate)->toBe(
        now()->addDays(12)->subDays(FabricationDeadlineQuoting::DAYS_BEFORE_FABRICATION)->toDateString()
    );

    //Nothing happens the day before the date the board gave
    test()->travelTo(Carbon::parse($triggerDate)->subDay()->startOfDay());
    expect((new FabricationDeadlineQuoting)->sweepBusiness($business))->toBeNull();

    //And on it, the column is taken
    test()->travelTo(Carbon::parse($triggerDate)->startOfDay());
    expect((new FabricationDeadlineQuoting)->sweepBusiness($business))->toBeInstanceOf(Batch::class);
});

it('says nothing about an ordering date for projects that were never asked for one', function () {
    /*
     * date_fabrication_begins is nullable for the projects that pre-date the question being asked.
     * A card of those has no trigger - nothing will auto-quote them - and the header has to say
     * nothing rather than count five days back from an invented date.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingColumnProject($user, now()->addDays(3)->toDateString());
    $project->update(['date_fabrication_begins' => null]);

    test()->actingAs($user);

    $column = test()->get(route('projects.index'))
        ->viewData('page')['props']['projects']['READY_FOR_NESTING'];

    expect($column['orderingTriggerDate'])->toBeNull();

    //And it is left where it is, rather than swept on a date nobody gave
    expect((new FabricationDeadlineQuoting)->sweepBusiness($business))->toBeNull();
});

it('is reachable as a scheduled command', function () {
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    nestingColumnProject($user, now()->addDays(2)->toDateString());

    test()->artisan('quoting:fabrication-deadline')
        ->expectsOutputToContain('Batches started by a fabrication deadline: 1')
        ->assertSuccessful();
});
