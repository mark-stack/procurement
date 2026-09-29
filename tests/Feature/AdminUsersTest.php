<?php

use App\Models\Supplier;
use App\Models\Template;
use App\Models\User;
use App\Notifications\WelcomeActivatedUserEmail;
use Illuminate\Support\Facades\Notification;

it('would be a disaster if the users list shipped every template screenshot to the browser', function () {
    /**
     * The page renders a count of templates and suppliers, never the rows, but it eager
     * loaded them and UserResource sent the business model - which serializes its loaded
     * relations - alongside the relations themselves. templates.screenshot is a base64
     * data url bounded at 1,000,000 characters, so each one went out twice per user row.
     * Three users in one business with two screenshots measured an 11.5MB response.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    createUser(2, $business, false, true);
    createUser(3, $business, false, true);

    $screenshot = 'data:image/png;base64,'.str_repeat('A', 500_000);
    Template::factory()->count(2)->create([
        'business_id' => $business->id,
        'screenshot' => $screenshot,
    ]);

    $response = $this->actingAs($admin)->get(route('admin.users.index'));

    expect($response->getContent())->not->toContain($screenshot);
    expect(strlen($response->getContent()))->toBeLessThan(500_000);

    $response->assertInertia(fn ($page) => $page
        ->missing('users.data.0.templates')
        ->missing('users.data.0.suppliers')
        ->missing('users.data.0.business.templates')
        ->missing('users.data.0.business.suppliers')
    );
});

it('counts the templates and suppliers of the business', function () {
    //A business is created with no templates, so the count is the two recorded here
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    Template::factory()->count(2)->create(['business_id' => $business->id]);
    $business->suppliers()->attach(Supplier::factory()->count(3)->create()->pluck('id'));

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page
            ->where('users.data.0.templates_count', 2)
            ->where('users.data.0.suppliers_count', 3)
        );
});

it('counts only the templates the business can actually import with', function () {
    /**
     * The column exists to answer "can these people upload anything yet" - a new signup has no
     * templates and cannot, until we build them one from the reports they email in. A row that
     * detects nothing does not move them off zero: deactivated, or recorded before templates
     * carried the heading row that finds the table, it is never matched against an upload, and
     * counting it would show a business as ready while every upload of theirs is rejected.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    Template::factory()->for($business)->create();
    Template::factory()->for($business)->inactive()->create();
    Template::factory()->for($business)->undetectable()->create();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page
            ->where('users.data.0.templates_count', 1)
        );
});

it('would be a disaster if the ready column could not tell an active business from a new one', function () {
    /**
     * businesses.admin_setup_complete is a boolean column with no cast on the model, so the
     * type reaching json was whatever the driver returned - an int on mysql, the string "0"
     * on sqlite. The page compares it with ===, so the column rendered neither "Active" nor
     * the Activate link under the test connection while it worked in production.
     */
    $active = createBusiness('Business A', true);
    $new = createBusiness('Business B', false);
    $admin = createUser(1, $active, true, true);
    createUser(2, $new, false, true);

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page
            ->where('users.data.0.business.admin_setup_complete', false)
            ->where('users.data.1.business.admin_setup_complete', true)
        );
});

it('lists the newest users first', function () {
    /**
     * The query had no order, so rows came back in whatever order the database chose and a
     * new signup - the reason to open this page - could appear anywhere in the list.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    $second = createUser(2, $business, false, true);
    $third = createUser(3, $business, false, true);

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page
            ->where('users.data.0.id', $third->id)
            ->where('users.data.1.id', $second->id)
            ->where('users.data.2.id', $admin->id)
        );
});

it('would be a disaster if every user on the platform came back in one response', function () {
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    for ($id = 2; $id <= 60; $id++) {
        createUser($id, $business, false, true);
    }

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page
            ->has('users.data', 50)
            ->where('users.meta.total', 60)
            ->where('users.meta.last_page', 2)
        );

    $this->actingAs($admin)
        ->get(route('admin.users.index', ['page' => 2]))
        ->assertInertia(fn ($page) => $page->has('users.data', 10));
});

it('would be a disaster if a non-admin could see every user on the platform', function () {
    $business = createBusiness('Business A', true);
    $user = createUser(2, $business, false, true);

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertRedirect('/');
});

it('sends a signed-out admin to the login page and back to the page they asked for', function () {
    /**
     * The middleware bounced everyone to '/', so an admin whose session had expired lost
     * the page they were on and the landing page had nothing to say about why.
     */
    $this->get(route('admin.users.index'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('url.intended', route('admin.users.index'));
});

it('would be a disaster if activating a business were reachable by a link or a prefetch', function () {
    /**
     * It was a GET rendered as an <Link>, so a prefetch, a crawler or the back button
     * welcomed every user in the business by email again.
     */
    $business = createBusiness('Business A', false);
    $admin = createUser(1, $business, true, true);

    Notification::fake();

    $this->actingAs($admin)
        ->get('/admin/activate-business/'.$business->id)
        ->assertMethodNotAllowed();

    expect($business->fresh()->admin_setup_complete)->toBeFalse();
    Notification::assertNothingSent();
});

it('activates the business and welcomes its users', function () {
    $business = createBusiness('Business A', false);
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    Notification::fake();

    $this->actingAs($admin)
        ->from(route('admin.users.index'))
        ->post(route('admin.activate.business', $business))
        ->assertRedirect(route('admin.users.index'));

    expect($business->fresh()->admin_setup_complete)->toBeTrue();
    Notification::assertSentTo($user, WelcomeActivatedUserEmail::class);
    Notification::assertSentTimes(WelcomeActivatedUserEmail::class, 2);
});

it('would be a disaster if activating a business welcomed everyone in it twice', function () {
    /**
     * Nothing stopped the controller running against a business that was already active, so
     * a refresh or a double click re-sent the welcome email to every user in it.
     */
    $business = createBusiness('Business A', false);
    $admin = createUser(1, $business, true, true);
    createUser(2, $business, false, true);

    Notification::fake();

    $this->actingAs($admin)->post(route('admin.activate.business', $business));
    $this->actingAs($admin)
        ->post(route('admin.activate.business', $business))
        ->assertSessionHas('warning', 'That business is already active.');

    Notification::assertSentTimes(WelcomeActivatedUserEmail::class, 2);
});

it('would be a disaster if the welcome email signed everyone in as the same user', function () {
    /**
     * The notification carries a magic link that signs its recipient in, and it used to read
     * that recipient off a constructor argument rather than off the notifiable. It was only
     * correct because the controller built a fresh instance per user inside a loop - sending
     * one instance to the collection, which is what it does now, would have handed every user
     * in the business a link that signed them in as the first of them.
     */
    $business = createBusiness('Business A', false);
    $first = createUser(1, $business, true, true);
    $second = createUser(2, $business, false, true);

    $notification = new WelcomeActivatedUserEmail;

    foreach ([$first, $second] as $user) {
        $this->get($notification->toMail($user)->actionUrl);

        $this->assertAuthenticatedAs($user);
        auth()->logout();
    }
});

it('verifies the address the welcome email reached', function () {
    /**
     * Reaching the link is the same proof the verification email asks for. Without marking the
     * address verified, the link signed the user in and EnsureEmailIsVerified - which guards
     * projects.index - bounced them to the verification prompt, and an unverified signup is
     * exactly who this email welcomes.
     */
    $business = createBusiness('Business A', false);
    $user = createUser(2, $business, false, false);

    expect($user->hasVerifiedEmail())->toBeFalse();

    $this->get((new WelcomeActivatedUserEmail)->toMail($user)->actionUrl)
        ->assertRedirect(route('projects.index'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('would be a disaster if a business could be left active with nobody welcomed', function () {
    /**
     * The column was saved and the welcome sent afterwards, so a failure in between left the
     * business reading "Active" with nobody emailed - and the already-active refusal then
     * turned away every attempt to put that right.
     */
    $business = createBusiness('Business A', false);
    $admin = createUser(1, $business, true, true);

    Notification::shouldReceive('send')->andThrow(new RuntimeException('the queue is down'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($admin)->post(route('admin.activate.business', $business)))
        ->toThrow(RuntimeException::class);

    expect($business->fresh()->admin_setup_complete)->toBeFalse();
});

it('resends the welcome to one user rather than the whole business', function () {
    /**
     * Activation can only happen once, so the thing needed afterwards is to put right the one
     * person whose email bounced or was queued while the worker was down - not to mail
     * everyone again, which is what any business-wide resend would amount to.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    Notification::fake();

    $this->actingAs($admin)
        ->from(route('admin.users.index'))
        ->post(route('admin.resend.welcome', $user))
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('success');

    Notification::assertSentTo($user, WelcomeActivatedUserEmail::class);
    Notification::assertNotSentTo($admin, WelcomeActivatedUserEmail::class);
});

it('refuses to welcome a user to a business that is not active yet', function () {
    /**
     * The email says the setup configuration is complete and its link lands on projects, which
     * BusinessReadyMiddleware guards - so sending it before activation is a lie followed by a
     * redirect back to onboarding.
     */
    $business = createBusiness('Business A', false);
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    Notification::fake();

    $this->actingAs($admin)
        ->post(route('admin.resend.welcome', $user))
        ->assertSessionHas('warning', 'That business is not active yet. Activate it, which welcomes everyone in it.');

    Notification::assertNothingSent();
});

it('says so rather than throwing when the user has no business to be welcomed to', function () {
    /**
     * users.business_id is nullable, and an orphaned user is exactly the kind of row an admin
     * opens this page to look at - so the button next to one must not 500.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    $orphan = User::factory()->create(['business_id' => null]);

    Notification::fake();

    $this->actingAs($admin)
        ->post(route('admin.resend.welcome', $orphan))
        ->assertSessionHas('warning', 'That user has no business, so there is nothing to welcome them to.');

    Notification::assertNothingSent();
});

it('would be a disaster if resending a welcome were reachable by a link or a prefetch', function () {
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    Notification::fake();

    $this->actingAs($admin)
        ->get('/admin/resend-welcome/'.$user->id)
        ->assertMethodNotAllowed();

    Notification::assertNothingSent();
});

it('deactivates a business without emailing anyone', function () {
    /**
     * Activation's counterpart, and deliberately not its mirror: there is no "your account has
     * been switched off" message worth sending, and activating afterwards welcomes everyone
     * again anyway.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    createUser(2, $business, false, true);

    Notification::fake();

    $this->actingAs($admin)
        ->from(route('admin.users.index'))
        ->post(route('admin.deactivate.business', $business))
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('success');

    expect($business->fresh()->admin_setup_complete)->toBeFalse();
    Notification::assertNothingSent();
});

it('says so rather than pretending when a business is already inactive', function () {
    $business = createBusiness('Business A', false);
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)
        ->post(route('admin.deactivate.business', $business))
        ->assertSessionHas('warning', 'That business is not active.');
});

it('lets a deactivated business be activated again, welcoming everyone a second time', function () {
    /**
     * The welcome is what activation is for, so going round the loop sends it again - which is
     * the reason deactivation is not offered as an undo for a misclick on Activate.
     */
    $business = createBusiness('Business A', false);
    $admin = createUser(1, $business, true, true);
    createUser(2, $business, false, true);

    Notification::fake();

    $this->actingAs($admin)->post(route('admin.activate.business', $business));
    $this->actingAs($admin)->post(route('admin.deactivate.business', $business));
    $this->actingAs($admin)->post(route('admin.activate.business', $business));

    expect($business->fresh()->admin_setup_complete)->toBeTrue();
    Notification::assertSentTimes(WelcomeActivatedUserEmail::class, 4);
});

it('would be a disaster if deactivating a business were reachable by a link or a prefetch', function () {
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)
        ->get('/admin/deactivate-business/'.$business->id)
        ->assertMethodNotAllowed();

    expect($business->fresh()->admin_setup_complete)->toBeTrue();
});

it('would be a disaster if a non-admin could deactivate a business or resend a welcome', function () {
    $business = createBusiness('Business A', true);
    $user = createUser(2, $business, false, true);

    Notification::fake();

    $this->actingAs($user)
        ->post(route('admin.deactivate.business', $business))
        ->assertRedirect('/');

    $this->actingAs($user)
        ->post(route('admin.resend.welcome', $user))
        ->assertRedirect('/');

    expect($business->fresh()->admin_setup_complete)->toBeTrue();
    Notification::assertNothingSent();
});

it('says which users have not verified their email', function () {
    /**
     * Impersonating an unverified user lands on the verification prompt rather than the
     * dashboard, and the list gave no way to tell before clicking.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    createUser(2, $business, false, false);

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page
            ->where('users.data.0.isVerified', false)
            ->where('users.data.1.isVerified', true)
        );
});
