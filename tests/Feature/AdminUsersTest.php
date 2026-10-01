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
    $business = createBusiness('Business A');
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
    $business = createBusiness('Business A');
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
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    Template::factory()->for($business)->create();
    Template::factory()->for($business)->inactive()->create();
    Template::factory()->for($business)->undetectable()->create();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page
            ->where('users.data.0.templates_count', 1)
            //And how many were recorded, so the first number is readable - see below
            ->where('users.data.0.templates_total', 3)
        );
});

it('says how many templates were recorded as well as how many are live', function () {
    /**
     * Three templates were recorded for a business, every one of them tested, and none of them
     * ticked active. This column said "0" in red, which is what it says for a business nobody has
     * recorded anything for - so the screen that exists to answer "can these people import yet"
     * gave the right answer to that question and the wrong impression about why.
     *
     * "0 of 3" is a different sentence: the templates exist, and switching one on is a tick on
     * another screen rather than an afternoon of recording them.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    Template::factory()->count(3)->for($business)->inactive()->create();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page
            ->where('users.data.0.templates_count', 0)
            ->where('users.data.0.templates_total', 3)
        );
});

it('would be a disaster if a user with no business broke the templates column', function () {
    //business_id is nullable, and both counts are read off a business that may not be there
    $admin = createUser(1, createBusiness('Business A'), true, true);

    User::factory()->create(['business_id' => null, 'name' => 'Orphan']);

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('users.data.0.business', null)
            ->where('users.data.0.templates_count', 0)
            ->where('users.data.0.templates_total', 0)
        );
});

it('lists the newest users first', function () {
    /**
     * The query had no order, so rows came back in whatever order the database chose and a
     * new signup - the reason to open this page - could appear anywhere in the list.
     */
    $business = createBusiness('Business A');
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
    $business = createBusiness('Business A');
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
    $business = createBusiness('Business A');
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

it('would be a disaster if the welcome email signed everyone in as the same user', function () {
    /**
     * The notification carries a magic link that signs its recipient in, and it used to read
     * that recipient off a constructor argument rather than off the notifiable. It was only
     * correct because the controller built a fresh instance per user inside a loop - sending
     * one instance to a collection - which is how activation sent it, before activation was
     * deleted - would have handed every user in the business a link that signed them in as the
     * first of them. It is sent one at a time now, from the resend button.
     */
    $business = createBusiness('Business A');
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
     * exactly who this email is for: it is now the way to get a customer whose verification email
     * never arrived into their account.
     */
    $business = createBusiness('Business A');
    $user = createUser(2, $business, false, false);

    expect($user->hasVerifiedEmail())->toBeFalse();

    $this->get((new WelcomeActivatedUserEmail)->toMail($user)->actionUrl)
        ->assertRedirect(route('projects.index'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('resends the welcome to one user rather than the whole business', function () {
    /**
     * Activation can only happen once, so the thing needed afterwards is to put right the one
     * person whose email bounced or was queued while the worker was down - not to mail
     * everyone again, which is what any business-wide resend would amount to.
     */
    $business = createBusiness('Business A');
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

it('says so rather than throwing when the user has no business to be welcomed to', function () {
    /**
     * users.business_id is nullable, and an orphaned user is exactly the kind of row an admin
     * opens this page to look at - so the button next to one must not 500.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);
    $orphan = User::factory()->create(['business_id' => null]);

    Notification::fake();

    $this->actingAs($admin)
        ->post(route('admin.resend.welcome', $orphan))
        ->assertSessionHas('warning', 'That user has no business, so there is nothing to welcome them to.');

    Notification::assertNothingSent();
});

it('would be a disaster if resending a welcome were reachable by a link or a prefetch', function () {
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    Notification::fake();

    $this->actingAs($admin)
        ->get('/admin/resend-welcome/'.$user->id)
        ->assertMethodNotAllowed();

    Notification::assertNothingSent();
});

it('would be a disaster if a non-admin could resend a welcome', function () {
    /*
     * It mints a magic link that signs its recipient in, so the one thing that must never be true of
     * it is that any logged-in customer can aim it at any address.
     */
    $business = createBusiness('Business A');
    $user = createUser(2, $business, false, true);

    Notification::fake();

    $this->actingAs($user)
        ->post(route('admin.resend.welcome', $user))
        ->assertRedirect('/');

    Notification::assertNothingSent();
});

it('says which users have not verified their email', function () {
    /**
     * Impersonating an unverified user lands on the verification prompt rather than the
     * dashboard, and the list gave no way to tell before clicking.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);
    createUser(2, $business, false, false);

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page
            ->where('users.data.0.isVerified', false)
            ->where('users.data.1.isVerified', true)
        );
});
