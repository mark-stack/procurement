<?php

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\ColleagueJoined;
use App\Notifications\QuoteDueEmail;
use App\Notifications\WelcomeActivatedUserEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The record of what this application sent, and the admin screen over it.
 *
 * The bell had its own table and email had nothing at all: once the queue worker was done with a
 * TrialEndingEmail or a welcome and login link, no row anywhere said it had ever gone out. So "did
 * that customer get the email" was a question only the mail provider could answer, and only for as
 * long as it kept its logs.
 */
it('would be a disaster if an email left no record anywhere', function () {
    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    //The one email an admin sends by hand, and a mail-only class: nothing wrote a notifications row
    //for this, so before the delivery log it was unrecorded end to end
    $user->notify(new WelcomeActivatedUserEmail);

    $delivery = NotificationDelivery::query()->sole();

    expect($delivery->channel)->toBe('mail')
        ->and($delivery->type)->toBe(WelcomeActivatedUserEmail::class)
        ->and($delivery->notifiable_type)->toBe(User::class)
        ->and((int) $delivery->notifiable_id)->toBe($user->id)
        //The address it actually went to, which is the whole point of the row
        ->and($delivery->recipient_email)->toBe($user->email)
        //Read off the built message. This class sets its own subject
        ->and($delivery->subject)->not->toBeNull();
});

it('records one row per channel, and ties them to the bell entry they share', function () {
    /*
     * A reminder with mail switched on goes out twice - BellFirst returns ['mail', 'database'] - and
     * the screen exists to make that visible. One row would have to choose a channel to call it, and
     * either choice is a lie about half of what happened.
     */
    config(['notifications.mail_reminders' => true]);

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    $user->notify(new QuoteDueEmail($project, $user, 'Quote this'));

    $deliveries = NotificationDelivery::query()->orderBy('channel')->get();

    expect($deliveries->pluck('channel')->all())->toBe(['database', 'mail']);

    //Laravel ids the notification instance before the first channel runs, so both rows carry it
    expect($deliveries->pluck('notification_id')->unique())->toHaveCount(1);

    //And it is the primary key of the row the bell actually renders
    $bellEntry = DatabaseNotification::query()->sole();
    expect($deliveries->first()->notification_id)->toBe($bellEntry->id);

    //The bell has no subject and did not fail to have one; the mail's is what was sent
    expect($deliveries->firstWhere('channel', 'database')->subject)->toBeNull()
        ->and($deliveries->firstWhere('channel', 'mail')->subject)->toBe('Procurement actions');

    //What it was about, kept on both so the list can name the project either way
    expect($deliveries->firstWhere('channel', 'database')->payload['project_name'])->toBe($project->name)
        ->and($deliveries->firstWhere('channel', 'mail')->payload['project_name'])->toBe($project->name);
});

it('would be a disaster if logging the subject minted a second login link', function () {
    /*
     * Six notification classes mint a MagicLink in toMail(): a password-less login to a fabricator's
     * account. Reading the subject by calling toMail() again would issue a second one per email sent,
     * to be left lying in whatever the listener did with it. The subject is read off the message that
     * was already built instead - see RecordNotificationDelivery::subjectOf().
     */
    config(['notifications.mail_reminders' => true]);

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    $user->notify(new QuoteDueEmail($project, $user, 'Quote this'));

    //One email, one login link
    expect(NotificationDelivery::query()->channel('mail')->count())->toBe(1)
        ->and(\MagicLink\MagicLink::query()->count())->toBe(1);
});

it('lists both channels with a pill saying which', function () {
    $business = createBusiness('fabricator');
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    $user->notify(new ColleagueJoined($admin));
    $user->notify(new WelcomeActivatedUserEmail);

    $this->actingAs($admin)
        ->get(route('admin.notifications.index'))
        ->assertInertia(fn ($page) => $page
            ->component('AdminNotificationsIndex')
            ->has('deliveries.data', 2)
            //Newest first, so the welcome is the top row
            ->where('deliveries.data.0.channel', 'mail')
            ->where('deliveries.data.0.channelLabel', 'Email')
            ->where('deliveries.data.1.channel', 'database')
            ->where('deliveries.data.1.channelLabel', 'Bell')
            //Named for what it is about, not for the channel in its class name
            ->where('deliveries.data.0.typeLabel', 'Welcome Activated User')
            ->where('deliveries.data.0.recipient.name', $user->name)
            /*
             * Eager loaded through the morph, columns spelled out. Asserted because the failure is
             * invisible otherwise: a constraint that did not reach the per-type query leaves the
             * resource lazy-loading the business per row, which renders identically and costs a
             * query per delivery on a table that only grows.
             */
            ->where('deliveries.data.0.recipient.business', $business->domain)
            //Both pills countable without clicking either
            ->where('totals.mail', 1)
            ->where('totals.database', 1)
        );
});

it('filters to the email half, which is how the users list links here', function () {
    $business = createBusiness('fabricator');
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    $user->notify(new ColleagueJoined($admin));
    $user->notify(new WelcomeActivatedUserEmail);

    $this->actingAs($admin)
        ->get(route('admin.notifications.index', ['channel' => 'mail']))
        ->assertInertia(fn ($page) => $page
            ->has('deliveries.data', 1)
            ->where('deliveries.data.0.channel', 'mail')
            ->where('filters.channel', 'mail')
            //Counted over the log and not the filtered page, so the pill can offer the other half
            ->where('totals.database', 1)
        );
});

it('would be a disaster if a mistyped channel read as "nothing was ever emailed"', function () {
    /*
     * The column stores whatever the channel called itself, so ?channel=email - the obvious thing to
     * type, and not what the mail channel is called - would match nothing and draw an empty table on
     * a page whose whole subject is whether mail is going out. Unrecognised narrows to nothing, so it
     * narrows to everything instead.
     */
    $business = createBusiness('fabricator');
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    $user->notify(new WelcomeActivatedUserEmail);

    $this->actingAs($admin)
        ->get(route('admin.notifications.index', ['channel' => 'email']))
        ->assertInertia(fn ($page) => $page
            ->has('deliveries.data', 1)
            ->where('filters.channel', '')
        );
});

it('narrows to one recipient, and says who', function () {
    $business = createBusiness('fabricator');
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);
    $colleague = createUser(3, $business, false, true);

    $user->notify(new WelcomeActivatedUserEmail);
    $colleague->notify(new WelcomeActivatedUserEmail);

    $this->actingAs($admin)
        ->get(route('admin.notifications.index', ['channel' => 'mail', 'user' => $user->id]))
        ->assertInertia(fn ($page) => $page
            ->has('deliveries.data', 1)
            ->where('deliveries.data.0.recipient.id', $user->id)
            //Named in the heading, so a list narrowed to one person cannot read as the whole platform
            ->where('filteredUser.name', $user->name)
            //And the counts follow the person, not the platform
            ->where('totals.mail', 1)
        );
});

it('says so when the link names a user who no longer exists', function () {
    /*
     * The log outlives the account - that is what it is for - so a link made from the users list is
     * still a link after the account behind it is gone. filteredUser null while the filter is set is
     * what the page reads to say the id matched nobody, rather than drawing an unexplained empty
     * table under a filter pill.
     */
    $business = createBusiness('fabricator');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)
        ->get(route('admin.notifications.index', ['user' => 99999]))
        ->assertInertia(fn ($page) => $page
            ->where('filters.user', 99999)
            ->where('filteredUser', null)
        );
});

it('would be a disaster if a deleted recipient took the page down with them', function () {
    /*
     * A log that cannot be read once the account is gone cannot answer the question it exists for:
     * what did we send that person before they left. The resource renders a missing notifiable rather
     * than dereferencing it, the way UserResource guards a user with no business.
     */
    $business = createBusiness('fabricator');
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    $user->notify(new WelcomeActivatedUserEmail);
    $sentTo = $user->email;
    $user->delete();

    $this->actingAs($admin)
        ->get(route('admin.notifications.index'))
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->has('deliveries.data', 1)
            ->where('deliveries.data.0.recipient', null)
            //The address survives on the row, so the page can still say where it went
            ->where('deliveries.data.0.recipientEmail', $sentTo)
        );
});

it('would be a disaster if the whole log came back in one response', function () {
    //A row per notification per channel, forever: this is the one table here that only grows
    $business = createBusiness('fabricator');
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    NotificationDelivery::factory()->count(60)->create([
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.notifications.index'))
        ->assertInertia(fn ($page) => $page
            ->has('deliveries.data', 50)
            ->where('deliveries.meta.total', 60)
        );

    $this->actingAs($admin)
        ->get(route('admin.notifications.index', ['page' => 2]))
        ->assertInertia(fn ($page) => $page->has('deliveries.data', 10));
});

it('would be a disaster if a non-admin could read every notification on the platform', function () {
    /*
     * Every reminder and every email sent to every business, with project names and addresses in it.
     * The group's middleware is what stops it; this is the test that says this route is inside the
     * group - see AdminPrivilegeTest for the gate itself.
     */
    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)->get(route('admin.notifications.index'))->assertRedirect('/');
});

it('sends a signed-out visitor to the login page and back to the log', function () {
    $this->get(route('admin.notifications.index'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('url.intended', route('admin.notifications.index'));
});

it('brings the bell notifications that were sent before the log existed', function () {
    /*
     * Without the backfill the screen opens empty on a platform that has been sending for months,
     * which reads as "nothing has ever been sent" rather than "the log starts today". Only the bell
     * can be recovered - there is no record of a sent email to find - so mail history genuinely does
     * start at the migration, and the page says as much when the email list is empty.
     */
    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    //Back to before the migration ran, with one bell row already in place
    Schema::drop('notification_deliveries');

    DatabaseNotification::create([
        'id' => Str::uuid()->toString(),
        'type' => QuoteDueEmail::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => ['project_id' => $project->id, 'project_name' => $project->name],
        'read_at' => null,
    ]);

    $migration = require database_path('migrations/2026_10_02_100000_create_notification_deliveries_table.php');
    $migration->up();

    $delivery = NotificationDelivery::query()->sole();

    expect($delivery->channel)->toBe('database')
        ->and($delivery->type)->toBe(QuoteDueEmail::class)
        ->and((int) $delivery->notifiable_id)->toBe($user->id)
        //The payload comes across, so the backfilled rows name their project like the new ones
        ->and($delivery->payload['project_name'])->toBe($project->name)
        /*
         * And no address is invented for them. The bell was never sent to one, and resolving the
         * user's current email here would claim a mail went out that did not.
         */
        ->and($delivery->recipient_email)->toBeNull();
});
