<?php

use App\Models\Supplier;
use App\Models\Template;
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
