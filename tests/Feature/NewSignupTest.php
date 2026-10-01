<?php

use App\Models\Business;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\NewUserEmail;
use App\Notifications\WelcomeActivatedUserEmail;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Notification;

/**
 * What a new signup can do, which as of now is everything.
 *
 * This file used to be OnboardingTest, and it was about a wait. Registration created a business with
 * admin_setup_complete false, BusinessReadyMiddleware held every page a customer would use, and the
 * page they got instead asked them to email us example bills of materials - two business days,
 * quoted on the page. An admin then read each one, filled in the templates form, tested it and
 * pressed Activate, which also started the trial and sent the only welcome email.
 *
 * None of that exists. An upload that matches no template writes its own template, tests it with the
 * real importer against the file that triggered it, and saves it if it passes - see
 * TemplateLearningService and TemplateLearningTest. So a new company verifies its address and is on
 * the board, and the trial runs from registration because that is when the product starts working.
 *
 * What is left here is everything about a signup that is still true: the state the row lands in, who
 * hears about it, and the magic link that gets somebody in when their verification email did not
 * arrive.
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

it('starts a new business on trial, with no templates and no suppliers', function () {
    /*
     * Both counts are zero and neither holds anything up any more. No templates means the first
     * upload writes one; no suppliers means they cannot quote until they add some, which they do
     * themselves on /suppliers. Nothing here is waiting on us.
     */
    Notification::fake();

    $business = registerAt('sam@newfabricator.com.au')->business;

    expect($business->detectableTemplates()->count())->toBe(0)
        ->and($business->suppliers()->count())->toBe(0)
        ->and($business->trial_ends_at->isFuture())->toBeTrue()
        ->and($business->allowsWrites())->toBeTrue();
});

it('sends an unverified signup to the verification prompt and nowhere else', function () {
    //The one gate registration does not open: everything below needs a confirmed address first
    Notification::fake();

    $user = registerAt('sam@newfabricator.com.au');

    expect($user->hasVerifiedEmail())->toBeFalse();

    foreach (['dashboard', 'projects.index', 'billing.index', 'profile.edit'] as $route) {
        $this->actingAs($user)
            ->get(route($route))
            ->assertRedirect(route('verification.notice'));
    }
});

it('would be a disaster if a brand new company still had to wait for us', function () {
    /*
     * The whole of the change, in one test. A company that signed up a minute ago, with no template
     * recorded for it and no admin having touched it, verifies its address and is on the material
     * list upload page - where it can create a project and upload a bill of materials, which is what
     * writes its first template.
     *
     * This used to land on /onboarding, a page quoting two business days.
     */
    Notification::fake();

    $user = registerAt('sam@newfabricator.com.au');
    $user->markEmailAsVerified();
    $user = $user->fresh();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page->component('MaterialListUpload'));
    $this->actingAs($user)->get(route('projects.index'))->assertStatus(200);
    $this->actingAs($user)->get(route('billing.index'))->assertStatus(200);
    $this->actingAs($user)->get(route('profile.edit'))->assertStatus(200);
});

it('would be a disaster if a colleague joining a working business had to wait for an admin', function () {
    /*
     * The second estimator at a customer already importing is the whole reason the business is keyed
     * on the email domain: they join the row their colleagues use, templates and all. No welcome
     * email, because nothing was switched on for them - verification is the only step between
     * registering and uploading, which is now also true of the first estimator.
     */
    $existing = createBusiness('acmesteel');
    recordExampleTemplates($existing);
    createUser(1, $existing, false, true);

    Notification::fake();

    $joiner = registerAt('jo@'.$existing->domain);

    expect($joiner->business_id)->toBe($existing->id);
    Notification::assertNotSentTo($joiner, WelcomeActivatedUserEmail::class);

    $joiner->markEmailAsVerified();

    $this->actingAs($joiner->fresh())
        ->get(route('dashboard'))
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page->component('MaterialListUpload'));
});

it('warns every business on a trial, including one that has not recorded a template yet', function () {
    /*
     * This test used to assert the opposite, and was right to. SendTrialReminders filtered on
     * Business::scopeActivated(), because "your free trial ends in 7 days, subscribe" followed by
     * "your trial has ended" is a demand for money from somebody who signed up, read the page asking
     * them to email us their spreadsheets, and was never switched on - they had never once been able
     * to import a file.
     *
     * There is no such customer now. A business with no template is a business that has not uploaded
     * anything yet, not one waiting on us, so its trial is a trial it could have spent - and the
     * scope, along with the column it read, is gone. Here so that putting it back would fail.
     */
    $fresh = createBusiness('no-templates-yet');
    createUser(1, $fresh, false, true);
    $fresh->trial_ends_at = now()->addDays(1);
    $fresh->save();

    $importing = createBusiness('importing');
    recordExampleTemplates($importing);
    createUser(2, $importing, false, true);
    $importing->trial_ends_at = now()->addDays(1);
    $importing->save();

    Notification::fake();

    $this->artisan('billing:trial-reminders')->assertSuccessful();

    Notification::assertSentTo($fresh->users()->first(), App\Notifications\TrialEndingEmail::class);
    Notification::assertSentTo($importing->users()->first(), App\Notifications\TrialEndingEmail::class);
});

it('tells a business with no template yet that its trial has ended', function () {
    //Same inversion as above: the trial ran against a product that worked, so the notice is honest
    $fresh = createBusiness('no-templates-yet');
    $owner = createUser(1, $fresh, false, true);
    $fresh->trial_ends_at = now()->subDay();
    $fresh->save();

    Notification::fake();

    $this->artisan('billing:trial-reminders')->assertSuccessful();

    Notification::assertSentTo($owner, App\Notifications\TrialEndingEmail::class);
});

it('welcomes the customer with a link that survives a weekend', function () {
    /*
     * The magic link an admin sends when a customer's verification email never arrived or has
     * expired. It was taking the trial reminders' twelve hours, which are tuned for mail that is
     * replaced by the next day's reminder if it goes unread - so a link sent on Friday afternoon was
     * dead by Saturday morning, to somebody who had written in because they could not get in.
     */
    $business = createBusiness('acmesteel');
    $user = createUser(2, $business, false, false);

    $url = (new WelcomeActivatedUserEmail)->toMail($user)->actionUrl;

    $this->travel(3)->days();

    $this->get($url)->assertRedirect(route('projects.index'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('expires the welcome link eventually', function () {
    //A passwordless full login, so it is a window rather than a standing door
    $business = createBusiness('acmesteel');
    $user = createUser(2, $business, false, false);

    $url = (new WelcomeActivatedUserEmail)->toMail($user)->actionUrl;

    $this->travel((int) config('magiclink.welcome.lifetime_minutes') + 1)->minutes();

    $this->get($url);

    $this->assertGuest();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('tells every admin about a signup, by email only', function () {
    /*
     * A signup no longer starts any work of ours - the customer's first upload sets them up - but it
     * is still the thing we most want to know about, and first() picked one admin by id and left any
     * other never hearing about a customer.
     *
     * Mail only. The database channel wrote a row the bell cannot render - it draws what
     * NotificationService::implementations() claims, and no implementation claims this type - so
     * every signup left an unread notification in an admin's bell with no wording, no button and
     * no way to clear it.
     */
    $platform = createBusiness('platform');
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
    $platform = createBusiness('platform');
    $admin = createUser(1, $platform, true, true);

    registerAt('sam@newfabricator.com.au');

    $admin = $admin->fresh();

    expect($admin->unreadNotifications()->count())->toBe(0)
        ->and((new NotificationService)->getUnreadNotifications($admin))->toBe([]);
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
