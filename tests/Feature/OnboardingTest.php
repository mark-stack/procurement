<?php

use App\Models\Business;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\NewUserEmail;
use App\Notifications\WelcomeActivatedUserEmail;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Notification;

/**
 * The state a new signup lands in, and how it gets out of it.
 *
 * Registration finds-or-creates a Business keyed on the email domain and logs the user straight in,
 * so a new company arrives with admin_setup_complete false and a colleague at an existing customer
 * arrives with it true. Those are two different products from the user's side and this file is about
 * both, plus the hand-off between them: an admin recording templates and pressing Activate.
 *
 * The three placeholders that stood here - "customer is not notified of completed template setup",
 * "customer cannot use email magic link", "customer does not see project index" - are all covered
 * now, some of it in AdminUsersTest where the activation screen's own tests live.
 */
function registerAt(string $email): User
{
    test()->post('/register', [
        'name' => 'Estimator',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    test()->post('/logout');

    return User::query()->where('email', $email)->firstOrFail();
}

it('starts a new business inactive, on trial, with nothing to import with', function () {
    Notification::fake();

    $business = registerAt('sam@newfabricator.com.au')->business;

    expect($business->admin_setup_complete)->toBeFalse()
        ->and($business->detectableTemplates()->count())->toBe(0)
        ->and($business->suppliers()->count())->toBe(0)
        //Writes are allowed - it is the middleware, not billing, that is holding them up
        ->and($business->allowsWrites())->toBeTrue();
});

it('sends an unverified signup to the verification prompt, not to onboarding', function () {
    Notification::fake();

    $user = registerAt('sam@newfabricator.com.au');

    expect($user->hasVerifiedEmail())->toBeFalse();

    foreach (['dashboard', 'onboarding', 'projects.index', 'billing.index', 'profile.edit'] as $route) {
        $this->actingAs($user)
            ->get(route($route))
            ->assertRedirect(route('verification.notice'));
    }
});

it('leaves billing and the profile reachable while the business is inactive', function () {
    /*
     * Everything that matters is behind BusinessReadyMiddleware, but these two must not be: the
     * trial runs while a business waits to be activated, so the page that fixes an expired one has
     * to be reachable from inside the wait. The profile is where the password is changed.
     */
    Notification::fake();

    $user = registerAt('sam@newfabricator.com.au');
    $user->markEmailAsVerified();
    $user = $user->fresh();

    $this->actingAs($user)->get(route('onboarding'))->assertStatus(200);
    $this->actingAs($user)->get(route('billing.index'))->assertStatus(200);
    $this->actingAs($user)->get(route('profile.edit'))->assertStatus(200);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('onboarding'));
});

it('would be a disaster if a colleague joining an active business had to wait for an admin', function () {
    /*
     * The second estimator at a customer we have already set up is the whole reason the business is
     * keyed on the email domain. They get no welcome email - only activation sends that, and it
     * already happened - so verification is the only step between registering and the board.
     */
    $existing = createBusiness('acmesteel', true);
    recordExampleTemplates($existing);
    createUser(1, $existing, false, true);

    Notification::fake();

    $joiner = registerAt('jo@'.$existing->domain);

    expect($joiner->business_id)->toBe($existing->id);
    Notification::assertNotSentTo($joiner, WelcomeActivatedUserEmail::class);

    $joiner->markEmailAsVerified();

    $this->actingAs($joiner->fresh())
        ->get(route('dashboard'))
        ->assertRedirect(route('projects.index'));
});

it('would be a disaster if a business were activated with nothing it could import', function () {
    /*
     * The welcome email says the setup configuration is complete. With no template recorded, the
     * first spreadsheet the customer uploads answers "didn't auto-detect properly, did the template
     * change?" - blaming their file for work that was never done, in an email that already told
     * them it was finished. Nothing can put that first impression back.
     */
    $business = createBusiness('acmesteel', false);
    $admin = createUser(1, $business, true, true);
    createUser(2, $business, false, true);

    Notification::fake();

    $this->actingAs($admin)
        ->post(route('admin.activate.business', $business))
        ->assertSessionHas('warning', 'That business has no templates that can detect a table, so it could not import anything. Record one first, then activate.');

    expect($business->fresh()->admin_setup_complete)->toBeFalse();
    Notification::assertNothingSent();
});

it('counts only the templates an upload is really matched against', function () {
    /*
     * The same two conditions the importer uses, so "has a template" here means the same thing it
     * means to a customer's upload. A deactivated row, or one recorded before templates carried the
     * heading cell that finds the table, detects nothing - and activating on the strength of one
     * would send the welcome to somebody who still cannot import.
     */
    $business = createBusiness('acmesteel', false);
    $admin = createUser(1, $business, true, true);
    recordExampleTemplates($business);
    $business->templates()->update(['active' => false]);

    Notification::fake();

    $this->actingAs($admin)
        ->post(route('admin.activate.business', $business))
        ->assertSessionHas('warning');

    expect($business->fresh()->admin_setup_complete)->toBeFalse();
});

it('would be a disaster if the trial were spent before the customer could use anything', function () {
    /*
     * Business::booted() starts the trial when the row is created, which is at registration - and
     * onboarding then asks the customer to email us their spreadsheets, which we take up to two
     * business days to turn into templates. So the trial was always short by the setup time, and a
     * signup that sat unactivated for longer than the trial arrived read-only in its first minute:
     * every page readable, every save refused, over a trial they never got to use.
     */
    $business = createBusiness('acmesteel', false);
    recordExampleTemplates($business);
    $admin = createUser(1, $business, true, true);

    //Registered, forgotten about, activated six weeks later
    $business->trial_ends_at = now()->subDays(12);
    $business->save();

    Notification::fake();

    $this->actingAs($admin)->post(route('admin.activate.business', $business));

    $business = $business->fresh();

    expect($business->admin_setup_complete)->toBeTrue()
        ->and($business->allowsWrites())->toBeTrue()
        ->and($business->trial_ends_at->isFuture())->toBeTrue()
        //The full trial, counted from activation
        ->and($business->trial_ends_at->startOfDay()->toDateString())
        ->toBe(now()->addDays((int) config('billing.trial_days'))->startOfDay()->toDateString());
});

it('gives a restarted trial its warnings back', function () {
    /*
     * SendTrialReminders sends each mark once per business, recorded in notification_logs, which is
     * what makes the hourly schedule safe. A business whose trial ran down before it was ever
     * activated therefore carries TRIAL_ENDED already, and would have gone through the whole of its
     * real trial with no seven-day warning and no notice at the end of it.
     */
    $business = createBusiness('acmesteel', false);
    recordExampleTemplates($business);
    $admin = createUser(1, $business, true, true);
    $owner = createUser(2, $business, false, true);

    $business->trial_ends_at = now()->subDays(1);
    $business->save();

    NotificationLog::create([
        'recipient_user_id' => $owner->id,
        'unique_model_id' => $business->id,
        'type' => 'TRIAL_ENDED',
    ]);

    Notification::fake();

    $this->actingAs($admin)->post(route('admin.activate.business', $business));

    expect(NotificationLog::query()->where('type', 'like', 'TRIAL\_%')->count())->toBe(0);
});

it('does not shorten a trial that was deliberately set longer', function () {
    /*
     * Activation only ever pushes the date outwards. A fixture, a seeder or a hand-edited row that
     * granted a long trial is not cut back to the config default by somebody pressing Activate.
     */
    $business = createBusiness('acmesteel', false);
    recordExampleTemplates($business);
    $admin = createUser(1, $business, true, true);

    $generous = now()->addDays((int) config('billing.trial_days') + 90);
    $business->trial_ends_at = $generous;
    $business->save();

    Notification::fake();

    $this->actingAs($admin)->post(route('admin.activate.business', $business));

    expect($business->fresh()->trial_ends_at->toDateString())->toBe($generous->toDateString());
});

it('would be a disaster if a business that was never activated were chased for money', function () {
    /*
     * "Your free trial ends in 7 days, subscribe" and then "your trial has ended", to somebody who
     * signed up, read the page asking them to send us their spreadsheets, and was never switched
     * on. They have never once been able to import a file. The reminders are keyed on the trial
     * date alone, which is true of every business including the ones still waiting on us.
     */
    $waiting = createBusiness('waiting', false);
    createUser(1, $waiting, false, true);
    $waiting->trial_ends_at = now()->addDays(1);
    $waiting->save();

    $live = createBusiness('live', true);
    createUser(2, $live, false, true);
    $live->trial_ends_at = now()->addDays(1);
    $live->save();

    Notification::fake();

    $this->artisan('billing:trial-reminders')->assertSuccessful();

    Notification::assertSentTimes(App\Notifications\TrialEndingEmail::class, 1);
    Notification::assertSentTo($live->users()->first(), App\Notifications\TrialEndingEmail::class);
    Notification::assertNotSentTo($waiting->users()->first(), App\Notifications\TrialEndingEmail::class);
});

it('would be a disaster if a never-activated business were told its trial had ended', function () {
    $waiting = createBusiness('waiting', false);
    createUser(1, $waiting, false, true);
    $waiting->trial_ends_at = now()->subDay();
    $waiting->save();

    Notification::fake();

    $this->artisan('billing:trial-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

it('welcomes the customer with a link that survives a weekend', function () {
    /*
     * This is a customer's first contact with the product and the only thing that verifies their
     * address - the verification link from registration expired days ago, because activation takes
     * up to two business days. It was taking the reminders' twelve hours, which are tuned for mail
     * that is forwarded to merchants and replaced by the next day's reminder if it goes unread. A
     * business activated on Friday afternoon had a dead link by Saturday morning.
     */
    $business = createBusiness('acmesteel', false);
    $user = createUser(2, $business, false, false);

    $url = (new WelcomeActivatedUserEmail)->toMail($user)->actionUrl;

    $this->travel(3)->days();

    $this->get($url)->assertRedirect(route('projects.index'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('expires the welcome link eventually', function () {
    //A passwordless full login, so it is a window rather than a standing door
    $business = createBusiness('acmesteel', false);
    $user = createUser(2, $business, false, false);

    $url = (new WelcomeActivatedUserEmail)->toMail($user)->actionUrl;

    $this->travel((int) config('magiclink.welcome.lifetime_minutes') + 1)->minutes();

    $this->get($url);

    $this->assertGuest();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('tells every admin about a signup, by email only', function () {
    /*
     * A signup is the start of work only we can do, so the notice has to reach whoever will do it.
     * first() picked one admin by id and left any other never hearing about a customer.
     *
     * Mail only. The database channel wrote a row the bell cannot render - it draws what
     * NotificationService::implementations() claims, and no implementation claims this type - so
     * every signup left an unread notification in an admin's bell with no wording, no button and
     * no way to clear it.
     */
    $platform = createBusiness('platform', true);
    $first = createUser(1, $platform, true, true);
    $second = User::factory()->create(['is_admin' => true, 'email_verified_at' => now(), 'business_id' => $platform->id]);

    Notification::fake();

    $user = registerAt('sam@newfabricator.com.au');

    Notification::assertSentTo($first, NewUserEmail::class);
    Notification::assertSentTo($second, NewUserEmail::class);

    Notification::assertSentTo($first, NewUserEmail::class, function ($notification, array $channels) {
        return $channels === ['mail'];
    });

    expect($user->exists)->toBeTrue();
});

it('would be a disaster if a signup left an unread notification nobody could clear', function () {
    $platform = createBusiness('platform', true);
    $admin = createUser(1, $platform, true, true);

    registerAt('sam@newfabricator.com.au');

    $admin = $admin->fresh();

    expect($admin->unreadNotifications()->count())->toBe(0)
        ->and((new NotificationService)->getUnreadNotifications($admin))->toBe([]);
});

it('would be a disaster if the welcome left one in a brand new user bell', function () {
    $business = createBusiness('acmesteel', false);
    recordExampleTemplates($business);
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    $this->actingAs($admin)->post(route('admin.activate.business', $business));

    $user = $user->fresh();

    expect($user->unreadNotifications()->count())->toBe(0)
        ->and((new NotificationService)->getUnreadNotifications($user))->toBe([]);
});

it('offers registration and a sign-in link when the landing page is told to', function () {
    /*
     * This was read with env() in HandleInertiaRequests, and env() returns null once the config is
     * cached - which is a deploy step. So production hid the register button and the sign-in link
     * from everybody, while .env said LOGIN_AVAILABLE=true: no way for a new customer to sign up,
     * and no way for an existing one to find the login. Against config() so that caching it, which
     * is what broke it, cannot break it again.
     */
    config(['registration.login_available' => true]);

    $this->get('/')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page->where('loginAvailable', true));

    config(['registration.login_available' => false]);

    $this->get('/')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page->where('loginAvailable', false));
});
