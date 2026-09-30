<?php

use App\Models\Batch;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ColleagueJoined;
use App\Notifications\ColleagueOrderedYourMaterials;
use App\Notifications\ColleagueQuotedYourMaterials;
use App\Notifications\QuoteDueEmail;
use App\Services\NotificationImplementations\NotificationColleagueOrderedImplementation;
use App\Services\NotificationImplementations\NotificationColleagueQuotedImplementation;
use App\Services\NotificationImplementations\NotificationQuotingOrderingDueImplementation;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A batch owned by one person carrying one project each from several - which is what "Start quoting"
 * produces, because it takes every project in the Nesting column and not just the presser's.
 *
 * @param  array<int, User>  $projectOwners
 * @return array{0: Batch, 1: array<int, Project>}
 */
function batchSpanningProjectManagers(User $batchOwner, array $projectOwners): array
{
    $batch = Batch::factory()->forUser($batchOwner->id)->create();

    $projects = [];

    foreach ($projectOwners as $owner) {
        $project = createProject($owner);
        pieceOnBatch($project, $batch);
        $projects[] = $project;
    }

    return [$batch, $projects];
}

it('would be a disaster if a colleague could take your project into their batch without telling you', function () {
    /**
     * "Start quoting" nests every project in the Nesting column into one batch owned by whoever
     * pressed it. From that moment the suppliers quoted and the delivery dates asked for belong to
     * that batch, and the owner of a project swept into it loses Edit, Archive and BOM upload on
     * their own project. The confirmation naming whose work is being taken is shown to the person
     * doing the taking; the owner was told nothing and found out by noticing the card had moved.
     */
    $business = createBusiness('gmail', true);
    $quoter = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    [$batch] = batchSpanningProjectManagers($quoter, [$quoter, $colleague]);

    Notification::fake();

    (new NotificationColleagueQuotedImplementation)->notifyAffectedProjectManagers($batch, $quoter);

    Notification::assertSentTo($colleague, ColleagueQuotedYourMaterials::class);

    //And not to the person who pressed the button, about their own project
    Notification::assertNotSentTo($quoter, ColleagueQuotedYourMaterials::class);
});

it('would be a disaster if the bell named a project the reader has no way to see', function () {
    /**
     * Test mode. Sandbox rows are scoped to one user, so a colleague notified about one gets a bell
     * entry naming a project that is not on their board, cannot be opened and never existed as far
     * as they are concerned.
     *
     * The hourly checks are safe from this for free - they run with no authenticated user, so
     * App\Sandbox\Sandbox sees live data only. These two run inside a request, where the person who
     * pressed the button may well be in their own sandbox.
     */
    $business = createBusiness('gmail', true);
    $quoter = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $quoter->sandbox_mode = true;
    $quoter->save();

    $this->actingAs($quoter);

    [$batch] = batchSpanningProjectManagers($quoter, [$quoter, $colleague]);

    Notification::fake();

    (new NotificationColleagueQuotedImplementation)->notifyAffectedProjectManagers($batch, $quoter);

    Notification::assertNothingSent();
});

it('would be a disaster if re-sending an order rang every project manager again', function () {
    /**
     * An order can be sent, undone and sent again - OrderUndoSentController exists for it. Each
     * press settles the approval for every project on the batch, so without a per-batch guard each
     * press would also put another copy of the same sentence in every colleague's bell.
     */
    $business = createBusiness('gmail', true);
    $orderer = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    [$batch] = batchSpanningProjectManagers($orderer, [$orderer, $colleague]);

    $implementation = new NotificationColleagueOrderedImplementation;

    $implementation->notifyAffectedProjectManagers($batch, $orderer);
    $implementation->notifyAffectedProjectManagers($batch, $orderer);
    $implementation->notifyAffectedProjectManagers($batch, $orderer);

    expect($colleague->notifications()->where('type', ColleagueOrderedYourMaterials::class)->count())->toBe(1);
});

it('tells the other project managers when someone sends the order that approves for them', function () {
    /**
     * Straight through the route, because the notification has to survive the two gates in front of
     * it. UpdateOrderApprovalStatus records who approved; this is what puts it in front of the
     * people it was recorded on behalf of.
     */
    $business = createBusiness('gmail', true);
    $orderer = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    [$batch] = batchSpanningProjectManagers($orderer, [$orderer, $colleague]);
    [, $order] = quoteAndOrder($orderer, $batch, quoteSent: true);

    $response = $this->actingAs($orderer)
        ->from('/dashboard')
        ->post(route('order.sent', $batch->id), ['order_id' => $order->id]);

    $response->assertRedirect();

    $notification = $colleague->notifications()
        ->where('type', ColleagueOrderedYourMaterials::class)
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['colleague_name'])->toBe($orderer->name)
        ->and($notification->data['batch_id'])->toBe($batch->id);
});

it('would be a disaster if a colleague could join the business and nobody be told', function () {
    /**
     * There is no invitation and no staff list - a business is every user whose email domain
     * matched at registration. The previous attempt at this was an hourly sweep whose
     * markPreviousAsRead compared data->user_id against a User model rather than its id, so it
     * cleared nothing, and whose else branch cleared every NewUserEmail row on the platform
     * whenever two quiet days passed - the platform admin's own signup alerts included.
     */
    //A company domain, not 'gmail' - a personal provider is refused registration outright
    $business = createBusiness('acmesteel', true);
    $existing = createUser(1, $business, false, true);

    $response = $this->post(route('register'), [
        'name' => 'New Starter',
        'email' => 'new.starter@'.$business->domain,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect();

    $newUser = User::query()->where('email', 'new.starter@'.$business->domain)->firstOrFail();

    /*
     * Nothing yet: the domain matched, but nobody has shown they can read that mailbox. Announcing
     * here let anyone who knew a fabricator's domain put a stranger in every employee's bell as
     * their colleague.
     */
    expect($existing->notifications()->where('type', ColleagueJoined::class)->exists())->toBeFalse();

    //Verification is what establishes they are who the domain says - see AnnounceVerifiedColleague
    $this->actingAs($newUser)->get(URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $newUser->id, 'hash' => sha1($newUser->email)],
    ));

    $notification = $existing->notifications()->where('type', ColleagueJoined::class)->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['colleague_id'])->toBe($newUser->id)
        //And the new starter is not told about their own arrival
        ->and($newUser->notifications()->where('type', ColleagueJoined::class)->exists())->toBeFalse();
});

it('would be a disaster if a colleague notification crossed into another business', function () {
    $business = createBusiness('gmail', true);
    $quoter = createUser(1, $business, false, true);

    $otherBusiness = createBusiness('outlook', true);
    $stranger = createUser(2, $otherBusiness, false, true);

    //A project of the stranger's, on the quoter's batch. Not reachable through the UI - the gates
    //stop it - but the notification must not be the thing that leaks the project name if it ever is
    [$batch] = batchSpanningProjectManagers($quoter, [$quoter]);
    $strangersProject = createProject($stranger);

    Notification::fake();

    (new NotificationColleagueQuotedImplementation)->notifyAffectedProjectManagers($batch, $quoter);

    Notification::assertNotSentTo($stranger, ColleagueQuotedYourMaterials::class);
    expect($strangersProject->fresh())->not->toBeNull();
});

it('would be a disaster if a reminder stayed in the bell after its deadline stopped mattering', function () {
    /**
     * The clearing used to live in the else branch of the hourly check - "no project is due, so
     * clear every notification of this class". Two things were wrong with it.
     *
     * It fired only when no project in any business was due, which on a live board is approximately
     * never: a project that was chased and then had its materials date pushed out kept its unread
     * reminder for good, because somebody else's project was still due. And when it did fire it
     * marked read every row of that class for every user on the platform.
     *
     * Said the other way round - these project ids are still due, so reminders about anything else
     * are stale - it is correct with one business live or a thousand.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $settled = createProject($user);
    $stillDue = createProject($user);

    foreach ([$settled, $stillDue] as $project) {
        DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => QuoteDueEmail::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => ['project_id' => $project->id, 'project_name' => $project->name],
            'read_at' => null,
        ]);
    }

    (new NotificationService)->clearStaleProjectReminders('QuoteDueEmail', [$stillDue->id]);

    $unread = $user->unreadNotifications()->pluck('data')->map(fn ($data) => $data['project_id']);

    expect($unread->all())->toBe([$stillDue->id]);
});

it('fills the bell without emailing anybody, unless asked to', function () {
    /**
     * The reason the hourly checks were switched off wholesale: every reminder hard-coded
     * ['mail', 'database'], so the bell and the outbound email were one decision. They are not any
     * more, and the default is bell only - see config/notifications.php and the BellFirst trait.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $project->update([
        'date_materials_required' => now()->addDays((new Project)->criticalPathDays())->addHours(2),
    ]);

    config(['notifications.mail_reminders' => false]);
    expect((new QuoteDueEmail($project, $user, 'due'))->via($user))->toBe(['database']);

    config(['notifications.mail_reminders' => true]);
    expect((new QuoteDueEmail($project, $user, 'due'))->via($user))->toBe(['mail', 'database']);

    //And the reminder still reaches the bell with mail off
    config(['notifications.mail_reminders' => false]);
    (new NotificationQuotingOrderingDueImplementation)->hourlyCheck();

    expect($user->unreadNotifications()->where('type', QuoteDueEmail::class)->exists())->toBeTrue();
});

it('renders every unread notification the bell is given, and only once each', function () {
    /**
     * getUnreadNotifications walks the implementations for each row. It used to keep walking after
     * one of them had claimed the row, which cost four wasted isCorrectClass calls per notification
     * on every Inertia response - and would have appended a duplicate had two implementations ever
     * answered to the same type.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    [$batch] = batchSpanningProjectManagers($colleague, [$user]);

    (new NotificationColleagueQuotedImplementation)->notifyAffectedProjectManagers($batch, $colleague);
    (new NotificationColleagueOrderedImplementation)->notifyAffectedProjectManagers($batch, $colleague);

    $rendered = (new NotificationService)->getUnreadNotifications($user->fresh());

    expect($rendered)->toHaveCount(2);

    foreach ($rendered as $notification) {
        expect($notification['message'])->toContain($colleague->name)
            ->and($notification['id'])->not->toBeNull()
            ->and($notification['timestamp'])->not->toBeNull();
    }
});

it('would be a disaster if archiving a project left a colleague notification about it in the bell', function () {
    /**
     * The colleague notifications carry project_id for this reason: a project that leaves the board
     * stops being talked about, through NotificationService::clearProjectNotifications and the
     * project observer.
     */
    $business = createBusiness('gmail', true);
    $owner = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $project = createProject($owner);
    $batch = Batch::factory()->forUser($colleague->id)->create();
    pieceOnBatch($project, $batch);

    (new NotificationColleagueQuotedImplementation)->notifyAffectedProjectManagers($batch, $colleague);

    expect($owner->unreadNotifications()->count())->toBe(1);

    $project->archive = true;
    $project->save();

    expect($owner->fresh()->unreadNotifications()->count())->toBe(0);
});
