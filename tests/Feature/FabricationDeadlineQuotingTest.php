<?php

use App\Actions\Batch\StartQuoting;
use App\Formatters\NestingFormatter;
use App\Models\Batch;
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
 * The long way round, through the same BOM matching an upload does, because what is being tested is
 * whether a real column is recognised - and a hand-built fixture piece expands to no cuts at all (see
 * nestedBatch() in Pest.php for the same reasoning).
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

/**
 * How many of one notification class a user has been sent, read or unread.
 *
 * Counted off the database rather than Notification::fake(), because the once-a-day window these hold
 * themselves to is a query against exactly these rows - faking the channel would leave nothing to
 * find and every run would look like the first.
 */
function warningsSent(User $user, string $notificationClass): int
{
    return $user->notifications()
        ->where('type', $notificationClass)
        ->count();
}

it('would be a disaster if the shop started cutting before anybody had quoted the steel', function () {
    /*
     * The Nesting column is a waiting room on purpose - the longer material sits there the better the
     * nest and the bulk price get. But nobody is watching it at 6am, and a project cannot still be
     * waiting there when the saw is about to start: the steel has to be quoted, ordered and delivered
     * first. Five days out is where somebody has to be told.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingColumnProject($user, now()->addDays(3)->toDateString());

    expect((new NestingFormatter)->piecesReadyForBatching($business))->not->toBeEmpty();

    Notification::fake();

    $trigger = (new FabricationDeadlineQuoting)->warnBusiness($business);

    expect($trigger)->toBeInstanceOf(Project::class);
    expect($trigger->id)->toBe($project->id);

    Notification::assertSentTo($user, BatchReadyToQuoteEmail::class,
        function (BatchReadyToQuoteEmail $notification) use ($project) {
            expect($notification->project->id)->toBe($project->id);

            //Both channels: a red dot seen the day after the steel was due is no use at all
            return $notification->via($notification->recipient) === ['mail', 'database'];
        });
});

it('would be a disaster if a deadline spent the business money on its own', function () {
    /*
     * This used to press "Start quoting" itself, and that is what was taken out of it. Starting
     * quoting creates a batch, saves a nest, consumes offcut inventory and takes Edit, Done and
     * BOM upload off every project it sweeps in - a purchasing decision, made by a schedule, on
     * behalf of somebody who was not looking. All it may do now is ask.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingColumnProject($user, now()->addDays(2)->toDateString());

    (new FabricationDeadlineQuoting)->warnBusiness($business);

    expect(Batch::count())->toBe(0);

    //The column is exactly where it was, which is the whole point
    expect((new NestingFormatter)->piecesReadyForBatching($business))->not->toBeEmpty();
    expect($project->fresh()->pieces()->whereNotNull('batch_id')->exists())->toBeFalse();
});

it('leaves a project alone while there is still time to accumulate more material', function () {
    /*
     * The other half of the rule, and the more important one. Warning early teaches people to press
     * the button early, which throws away the reason the column exists: a batch started today is a
     * nest, a set of quotes and a delivery that tomorrow's material list can no longer join.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    nestingColumnProject($user, now()->addDays(20)->toDateString());

    expect((new FabricationDeadlineQuoting)->warnBusiness($business))->toBeNull();
    expect(warningsSent($user, BatchReadyToQuoteEmail::class))->toBe(0);
});

it('counts a fabrication date that has already passed as the most urgent of all', function () {
    /*
     * A project whose fabrication was due to start last week is more urgent than one starting on
     * Friday, not less. Reading the window as "between now and five days" rather than "no later than
     * five days" would leave it waiting in the Nesting column with nobody ever told.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    nestingColumnProject($user, now()->subDays(2)->toDateString());

    expect((new FabricationDeadlineQuoting)->warnBusiness($business))->toBeInstanceOf(Project::class);
});

it('would be a disaster if an hourly warning arrived hourly', function () {
    /*
     * The old version was idempotent by construction: it emptied the column, so an hour later there
     * was nothing left to find. Nothing empties now - the condition is still true until somebody
     * presses the button - so the only thing between an hourly command and twenty-four emails a day
     * is the window on the notification itself.
     *
     * A day in production, a minute under TEST_MODE, which is what the travelling below steps over.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    nestingColumnProject($user, now()->addDays(2)->toDateString());

    (new FabricationDeadlineQuoting)->warnBusiness($business);
    (new FabricationDeadlineQuoting)->warnBusiness($business);
    (new FabricationDeadlineQuoting)->warnBusiness($business);

    expect(warningsSent($user, BatchReadyToQuoteEmail::class))->toBe(1);

    //And tomorrow it asks again, because the steel is a day later and still not quoted
    test()->travelTo(now()->addMinutes(2));

    (new FabricationDeadlineQuoting)->warnBusiness($business);

    expect(warningsSent($user, BatchReadyToQuoteEmail::class))->toBe(2);

    /*
     * One unread, not two. The bell would otherwise fill with a row per day of the window, every one
     * of them saying the same thing about the same project.
     */
    expect($user->unreadNotifications()->where('type', BatchReadyToQuoteEmail::class)->count())->toBe(1);
});

it('stops asking once somebody has started quoting', function () {
    /*
     * The point of asking rather than acting is that somebody goes and presses the button - and the
     * moment they do, "these materials are still waiting to be nested" is false. Left alone it would
     * sit unread in the bell underneath the notification saying the batch had been quoted.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    nestingColumnProject($user, now()->addDays(2)->toDateString());

    (new FabricationDeadlineQuoting)->warnAll();

    expect($user->unreadNotifications()->where('type', BatchReadyToQuoteEmail::class)->count())->toBe(1);

    //The button, pressed by hand, exactly as QuoteController::store presses it
    StartQuoting::run($user, $business, (new NestingFormatter)->piecesReadyForBatching($business));

    (new FabricationDeadlineQuoting)->warnAll();

    expect($user->unreadNotifications()->where('type', BatchReadyToQuoteEmail::class)->count())->toBe(0);

    //Marked read, not deleted - the bell's history is still the bell's history
    expect(warningsSent($user, BatchReadyToQuoteEmail::class))->toBe(1);
});

it('signs the recipient in on the page the button it names is on', function () {
    /*
     * The email used to explain, at length, which button to find when they got there - on the one
     * screen it is already about. The explanation is gone: the deadline is the whole message, and
     * the button names the thing to do rather than the screen it is on.
     *
     * The link signs them in and stops there. It made the press itself for a while, through a page
     * that posted itself, and that page was a flash of a screen on the way to this one for a saving
     * of one button - on a press that commits the business to a purchase, reached from a link in an
     * email where a mistimed tap and a mail filter look alike.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingColumnProject($user, now()->addDays(2)->toDateString());

    $mail = (new BatchReadyToQuoteEmail($project, $user, 'anything'))->toMail($user);

    $rendered = implode("\n", $mail->introLines);

    expect($mail->actionText)->toBe('Start quoting');

    //The deadline and nothing else. The walkthrough of a screen they have not reached yet is gone
    expect($rendered)->not->toContain('Email tables');
    expect($rendered)->not->toContain('Quotes / Orders');

    //And none of the material list itself - a list reflowed by a mail client cannot be sent to a merchant
    expect($rendered)->not->toContain('mm');

    /*
     * Followed rather than inspected: the action url is an opaque MagicLink token, and MagicLink
     * rebuilds the response it was handed from the target url and status code alone - so the only
     * honest way to ask where this goes is to go there.
     */
    test()->get($mail->actionUrl)->assertRedirect(route('dashboard'));

    /*
     * And it bought nothing on the way. A magic link is followed by mail scanners and link-rewriting
     * gateways before a human sees it - config/magiclink allows three visits for exactly that reason
     * - so a url that nested on arrival would commit the business to a purchase nobody had clicked.
     */
    expect(Batch::count())->toBe(0);
    expect((new NestingFormatter)->piecesReadyForBatching($business))->not->toBeEmpty();
});

it('would be a disaster if a colleague found out their steel had been ordered afterwards', function () {
    /*
     * Starting quoting takes the WHOLE Nesting column, so a colleague's project goes into a batch
     * they do not own, on a deadline that is not theirs. From that moment they lose Edit, Done and
     * BOM upload on their own project and somebody else picks the suppliers. Now that the press is a
     * person's decision again, the warning reaches them before it happens rather than after - which
     * is the only version of this that leaves them time to say "that BOM is not final".
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $urgent = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $trigger = nestingColumnProject($urgent, now()->addDays(2)->toDateString());
    $colleagueProject = nestingColumnProject($colleague, now()->addMonth()->toDateString());

    Notification::fake();

    (new FabricationDeadlineQuoting)->warnBusiness($business);

    Notification::assertSentTo($colleague, ColleagueOrderingBatchTodayEmail::class,
        function (ColleagueOrderingBatchTodayEmail $notification) use ($colleagueProject, $trigger, $urgent) {
            expect($notification->project->id)->toBe($colleagueProject->id);
            expect($notification->trigger->id)->toBe($trigger->id);
            expect($notification->colleague->id)->toBe($urgent->id);

            return true;
        });

    /*
     * And the person being asked to press the button is not also told that somebody will be pressing
     * it. They get the one addressed to them.
     */
    Notification::assertNotSentTo($urgent, ColleagueOrderingBatchTodayEmail::class);
    Notification::assertSentTo($urgent, BatchReadyToQuoteEmail::class);
});

it('picks the most recently added project when two share the same fabrication date', function () {
    /*
     * The tie-break decides who is asked to press the button and whose name is in everybody else's
     * inbox - so it cannot be left to whatever order the database returns rows in. The latest arrival
     * is the project whose BOM somebody was working on, so they are the colleague with the job in
     * front of them today.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $first = createUser(1, $business, false, true);
    $second = createUser(2, $business, false, true);

    $sameDate = now()->addDays(4)->toDateString();

    nestingColumnProject($first, $sameDate);
    $laterProject = nestingColumnProject($second, $sameDate);

    //Created in the same second in a test, so the id is what separates them - see triggerProject
    $trigger = (new FabricationDeadlineQuoting)->warnBusiness($business);

    expect($trigger->id)->toBe($laterProject->id);
    expect($trigger->user_id)->toBe($second->id);
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

    $trigger = (new FabricationDeadlineQuoting)->warnBusiness($business);

    expect($trigger->user_id)->toBe($soonest->id);
});

it('says nothing to an account that is not allowed to press the button', function () {
    /*
     * BillingWriteAccessMiddleware makes a lapsed account read-only: the user cannot press "Start
     * quoting" themselves. Chasing them to press it would be sending somebody to a 403, and their
     * trial lapsing is SendTrialReminders' news to break, not this one's.
     */
    seededCatalogue();

    $business = lapsedTrialBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    nestingColumnProject($user, now()->addDays(2)->toDateString());

    expect($business->fresh()->allowsWrites())->toBeFalse();
    expect((new FabricationDeadlineQuoting)->warnBusiness($business->fresh()))->toBeNull();
    expect(warningsSent($user, BatchReadyToQuoteEmail::class))->toBe(0);
});

it('does not reach across businesses', function () {
    /*
     * The pass walks every business on the platform. A project ready for batching is found through
     * its owner's business, so a deadline in one yard must not put a warning in another's bell.
     */
    seededCatalogue();

    $urgentBusiness = createBusiness('urgent');
    $urgentUser = createUser(1, $urgentBusiness, false, true);
    nestingColumnProject($urgentUser, now()->addDays(2)->toDateString());

    $calmBusiness = createBusiness('calm');
    $calmUser = createUser(1, $calmBusiness, false, true);
    nestingColumnProject($calmUser, now()->addMonth()->toDateString());

    $warned = (new FabricationDeadlineQuoting)->warnAll();

    expect($warned)->toHaveCount(1);
    expect($warned[0]->user_id)->toBe($urgentUser->id);

    expect(warningsSent($urgentUser, BatchReadyToQuoteEmail::class))->toBe(1);
    expect(warningsSent($calmUser, BatchReadyToQuoteEmail::class))->toBe(0);
});

it('would be a disaster if the board promised an ordering date nothing kept to', function () {
    /*
     * The Nesting card's header prints "Order by <date>", which is the earliest fabrication date in
     * the column less the days quoting and delivery take. Two places subtracting five days is one
     * place to forget, and the cost of forgetting is a board that tells a project manager they have
     * until Friday while the warning arrives on Wednesday - so the figure is computed off the same
     * constant, and this is the test that says so.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    //The later one first, so "earliest" cannot pass by accident on row order
    nestingColumnProject($user, now()->addDays(30)->toDateString());
    nestingColumnProject($user, now()->addDays(12)->toDateString());

    test()->actingAs($user);

    //The open batch card - the Nesting column, now that the board it used to head is gone
    $column = test()->get(route('dashboard'))
        ->viewData('page')['props']['batches'][0];

    $triggerDate = $column['orderingTriggerDate'];

    expect($triggerDate)->toBe(
        now()->addDays(12)->subDays(FabricationDeadlineQuoting::DAYS_BEFORE_FABRICATION)->toDateString()
    );

    //Nothing is said the day before the date the board gave
    test()->travelTo(Carbon::parse($triggerDate)->subDay()->startOfDay());
    expect((new FabricationDeadlineQuoting)->warnBusiness($business))->toBeNull();

    //And on it, the column is asked about
    test()->travelTo(Carbon::parse($triggerDate)->startOfDay());
    expect((new FabricationDeadlineQuoting)->warnBusiness($business))->toBeInstanceOf(Project::class);
});

it('says nothing about an ordering date for projects that were never asked for one', function () {
    /*
     * date_fabrication_begins is nullable for the projects that pre-date the question being asked.
     * A card of those has no deadline to count back from - nothing will ever come and chase them -
     * and the header has to say nothing rather than count five days back from an invented date.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingColumnProject($user, now()->addDays(3)->toDateString());
    $project->update(['date_fabrication_begins' => null]);

    test()->actingAs($user);

    //The open batch card - the Nesting column, now that the board it used to head is gone
    $column = test()->get(route('dashboard'))
        ->viewData('page')['props']['batches'][0];

    expect($column['orderingTriggerDate'])->toBeNull();

    //And it is left alone, rather than chased on a date nobody gave
    expect((new FabricationDeadlineQuoting)->warnBusiness($business))->toBeNull();
});

it('is reachable as a scheduled command', function () {
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    nestingColumnProject($user, now()->addDays(2)->toDateString());

    test()->artisan('quoting:fabrication-deadline')
        ->expectsOutputToContain('Columns warned about a fabrication deadline: 1')
        ->assertSuccessful();

    //And it did not quote anything on the way past
    expect(Batch::count())->toBe(0);
});

it('presses the button the warning asks for, and opens the order list of what it made', function () {
    /*
     * The green action on the warning. It used to send the reader to the page the button is on and
     * leave them to find it, which is the one screen they were already being told about - so it is
     * the press itself now, and then the order list of the batch it produced: somebody told their
     * steel has to go out today needs the materials to put in front of a merchant.
     *
     * The same press the card's own "Lock batch for quoting" makes - PressStartQuoting - so the batch,
     * the nest and the notifications to the colleagues swept up in it are identical either way.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $project = nestingColumnProject($user, now()->addDays(2)->toDateString());

    (new FabricationDeadlineQuoting)->warnBusiness($business);

    $notification = $user->notifications()
        ->where('type', BatchReadyToQuoteEmail::class)
        ->firstOrFail();

    $response = test()->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('mark.notification.status'), [
            'id' => $notification->id,
            'status' => 'GREEN',
        ]);

    //The column was nested, which is the thing the warning was asking for
    expect(Batch::count())->toBe(1);

    $batch = Batch::query()->firstOrFail();

    expect($batch->user_id)->toBe($user->id)
        ->and($project->fresh()->pieces()->whereNull('batch_id')->exists())->toBeFalse()
        ->and((new NestingFormatter)->piecesReadyForBatching($business))->toBeEmpty();

    /*
     * And the page it lands on is told to open that batch's order list. A flash rather than a query
     * string: it is an instruction about this one arrival, where ?orderList=12 would sit in the
     * address bar reopening the modal on every refresh.
     */
    $response->assertRedirect(route('dashboard'))
        ->assertSessionHas('openOrderList', $batch->id);

    //The warning is answered, so it is out of the bell rather than waiting for the next sweep
    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('would be a disaster if the green action quoted a column a colleague had already taken', function () {
    /*
     * An hour is long enough for somebody else to press the button, and the warning sits in a bell
     * until it is read. Pressing it then must not build a second batch over the same steel - the
     * press is gated and race-checked exactly as the card's own is - and it has to say where the
     * work went, because an emptied column and a silent redirect look identical to a success.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    nestingColumnProject($user, now()->addDays(2)->toDateString());

    (new FabricationDeadlineQuoting)->warnBusiness($business);

    $notification = $user->notifications()
        ->where('type', BatchReadyToQuoteEmail::class)
        ->firstOrFail();

    //The colleague gets there first, by the one route that does this
    StartQuoting::run($user, $business, (new NestingFormatter)->piecesReadyForBatching($business));

    expect(Batch::count())->toBe(1);

    $response = test()->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('mark.notification.status'), [
            'id' => $notification->id,
            'status' => 'GREEN',
        ]);

    //No second batch over the same steel, and nothing to open
    expect(Batch::count())->toBe(1);

    $response->assertRedirect(route('dashboard'))
        ->assertSessionMissing('openOrderList')
        ->assertSessionHas('warning');
});

it('would be a disaster if the green action let a lapsed account buy steel', function () {
    /*
     * The bell's status route sits outside BillingWriteAccessMiddleware on purpose - dismissing a
     * notification changes nothing anybody is billed for - and that stopped being the whole truth
     * when this notification's green action became the press itself. Nesting is the most expensive
     * write in the application and it commits the business to a purchase, so the one way into it
     * that the middleware does not guard has to ask the same question for itself.
     *
     * The warning is sent while the trial is live, because projectsToWarn will not chase a read-only
     * business at all - and then it sits in the bell, which is where a trial runs out.
     */
    seededCatalogue();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    nestingColumnProject($user, now()->addDays(2)->toDateString());

    (new FabricationDeadlineQuoting)->warnBusiness($business);

    $notification = $user->notifications()
        ->where('type', BatchReadyToQuoteEmail::class)
        ->firstOrFail();

    //And now the trial lapses, with the row still unread
    $business->trial_ends_at = now()->subDay();
    $business->save();

    expect($business->fresh()->allowsWrites())->toBeFalse();

    $response = test()->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('mark.notification.status'), [
            'id' => $notification->id,
            'status' => 'GREEN',
        ]);

    //Nothing bought, and nothing to open
    expect(Batch::count())->toBe(0);
    expect((new NestingFormatter)->piecesReadyForBatching($business->fresh()))->not->toBeEmpty();

    $response->assertSessionMissing('openOrderList')
        ->assertSessionHas('warning');
});
