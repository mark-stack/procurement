<?php

use App\Models\Batch;
use App\Models\Business;
use App\Models\Project;
use Database\Factories\UserFactory;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $business = createBusiness('admin');
    $user = createUser(1, $business, false, true);

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $business = createBusiness('admin');
    $user = createUser(1, $business, false, true);

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $business = createBusiness('admin');
    $user = createUser(1, $business, false, true);

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('the email cannot be changed to a personal address', function () {
    $business = createBusiness('admin');
    $user = createUser(1, $business, false, true);

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'sam@gmail.com',
        ]);

    $response->assertSessionHasErrors('email');
    $this->assertNotSame('sam@gmail.com', $user->refresh()->email);
});

/*
 * Someone already on a personal address - the admin account is one - still has to be able to
 * fix a typo in their name without the form rejecting an email they never touched.
 */
test('an existing personal address can be kept', function () {
    $business = createBusiness('admin');
    $user = createUser(1, $business, false, true);
    $user->email = 'sam@gmail.com';
    $user->save();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'A New Name',
            'email' => 'sam@gmail.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertSame('A New Name', $user->refresh()->name);
});

test('user can delete their account', function () {
    $business = createBusiness('admin');
    $user = createUser(1, $business, false, true);

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => UserFactory::PASSWORD,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

it('would be a disaster if deleting an account answered with a 500', function () {
    /**
     * projects.user_id, batches.user_id, quotes.user_id and orders.user_id all restrict, so
     * $user->delete() throws for any account that has ever created a project - which is every real
     * account. And the delete sat after Auth::logout(), so "Delete Account" signed the user out,
     * answered with a 500, and never reached the session invalidate() below it. The account was
     * still there and nothing said so.
     *
     * The test that covered this route used an account with no projects, which is the one case that
     * worked.
     */
    $business = createBusiness('admin');
    $user = createUser(1, $business, false, true);
    createProject($user);

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => UserFactory::PASSWORD,
        ]);

    //Refused with a reason, and still signed in - not logged out into an error page
    $response->assertInvalid('account')->assertRedirect('/profile');
    expect($user->fresh())->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('names what is in the way when an account cannot be deleted', function () {
    //A refusal that does not say what is holding it up is a dead end
    $business = createBusiness('admin');
    $user = createUser(1, $business, false, true);
    createProject($user);
    createProject($user);
    Batch::factory()->forUser($user->id)->create();

    $this->actingAs($user)
        ->from('/profile')
        ->delete('/profile', ['password' => UserFactory::PASSWORD]);

    expect(session('errors')->get('account')[0])
        ->toContain('2 projects')
        ->toContain('1 batch');
});

it('would be a disaster if a colleague’s board could be deleted with an account', function () {
    /**
     * The other reason this is refused rather than cascaded. A user's projects sit in their
     * colleagues' Nesting column and their batches hold the steel those colleagues ordered, so
     * there is no version of "delete my account" that should take that with it.
     */
    $business = createBusiness('admin');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    pieceOnBatch(createProject($colleague), $batch);

    $this->actingAs($user)
        ->from('/profile')
        ->delete('/profile', ['password' => UserFactory::PASSWORD]);

    expect($batch->fresh())->not->toBeNull()
        ->and(Project::count())->toBe(1);
});

it('would be a disaster if test-mode rows were invisible to the deletion check', function () {
    /**
     * Projects and batches are behind a global sandbox scope, so counting them the ordinary way
     * only ever sees the mode the user happens to be in. Somebody who has left test mode would be
     * told they are clear to delete and then hit the foreign key on their own test rows - the exact
     * 500 this check exists to prevent.
     */
    $business = createBusiness('admin');
    $user = createUser(1, $business, false, true);

    //Made in test mode, and read back from outside it
    $user->sandbox_mode = true;
    $user->save();
    createProject($user->fresh());

    $user->sandbox_mode = false;
    $user->save();

    $this->actingAs($user->fresh())
        ->from('/profile')
        ->delete('/profile', ['password' => UserFactory::PASSWORD])
        ->assertInvalid('account');

    expect($user->fresh())->not->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $business = createBusiness('admin');
    $user = createUser(1, $business, false, true);

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect('/profile');

    $this->assertNotNull($user->fresh());
});

/*
 * Business preferences - the quoting and delivery lead times, which sit on this page but belong to
 * the business rather than to the person reading it. See BusinessPreferencesController.
 */

test('the profile page draws the lead times the business is actually running on', function () {
    $business = createBusiness('admin');
    $user = createUser(1, $business, false, true);

    //Not the defaults, so what is asserted is the row's own figures and not a coincidence
    $business->update(['quoting_days' => 4, 'delivery_days' => 9]);

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Edit')
            ->where('businessPreferences.quoting_days', 4)
            ->where('businessPreferences.delivery_days', 9)
            ->where('canEditBusinessPreferences', true)
        );
});

test('a new business starts on the platform lead times, two days to quote and three to deliver', function () {
    /*
     * The figures asked for, and the ones every deadline is counted back from until a business sets
     * its own. Asserted off a business created the way registration creates one rather than off a row
     * re-read from the database: a column default only fills the ROW, so an instance handed back from
     * create() carries whatever Business::$attributes says - which is the trap that note documents.
     */
    $business = createBusiness('admin');

    expect($business->quoting_days)->toBe(Business::DEFAULT_QUOTING_DAYS)
        ->and($business->delivery_days)->toBe(Business::DEFAULT_DELIVERY_DAYS);

    expect(Business::DEFAULT_QUOTING_DAYS)->toBe(2)
        ->and(Business::DEFAULT_DELIVERY_DAYS)->toBe(3);
});

test('business preferences can be updated, and every project on the board is measured against them', function () {
    /*
     * The whole point of the setting: the critical path is quoting time plus delivery time, and that
     * is what decides when a project starts being chased. A number saved here that nothing reads
     * would be a preference in name only.
     */
    $business = createBusiness('admin');
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    expect($project->criticalPathDays())->toBe(5);

    $this->actingAs($user)
        ->patch(route('business.preferences.update'), [
            'quoting_days' => 3,
            'delivery_days' => 10,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    expect($business->fresh()->quoting_days)->toBe(3)
        ->and($business->fresh()->delivery_days)->toBe(10)
        //Thirteen days before the material is wanted, where it was five
        ->and($project->fresh()->criticalPathDays())->toBe(13);
});

test('a lead time of no days at all is allowed, for a business that collects off the rack', function () {
    $business = createBusiness('admin');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->patch(route('business.preferences.update'), [
            'quoting_days' => 0,
            'delivery_days' => 0,
        ])
        ->assertSessionHasNoErrors();

    expect($business->fresh()->quoting_days)->toBe(0)
        ->and($business->fresh()->delivery_days)->toBe(0);
});

test('it refuses a lead time that is not a whole number of days, or is negative', function () {
    /*
     * Both are counted back from a materials date, so a negative one would put the deadline AFTER the
     * steel was wanted - the board would stop chasing a project exactly when it most needed chasing.
     */
    $business = createBusiness('admin');
    $user = createUser(1, $business, false, true);

    foreach ([
        ['quoting_days' => -1, 'delivery_days' => 3],
        ['quoting_days' => 2, 'delivery_days' => -1],
        ['quoting_days' => 'soon', 'delivery_days' => 3],
        ['quoting_days' => 2.5, 'delivery_days' => 3],
        ['quoting_days' => 2, 'delivery_days' => 4000],
        ['quoting_days' => null, 'delivery_days' => 3],
    ] as $payload) {
        $this->actingAs($user)
            ->from('/profile')
            ->patch(route('business.preferences.update'), $payload)
            ->assertSessionHasErrors();
    }

    //Untouched the whole way through
    expect($business->fresh()->quoting_days)->toBe(Business::DEFAULT_QUOTING_DAYS)
        ->and($business->fresh()->delivery_days)->toBe(Business::DEFAULT_DELIVERY_DAYS);
});

test('it would be a disaster if this form could set another business\'s lead times', function () {
    /*
     * It takes no id, so there is nothing to point at another business - this asserts the shape of
     * that rather than an example of it. A colleague in the same business may set them, which is the
     * other half: they already share the board these deadlines are drawn on.
     */
    $mine = createBusiness('mine');
    $theirs = createBusiness('theirs');

    $user = createUser(1, $mine, false, true);
    $colleague = createUser(2, $mine, false, true);

    $this->actingAs($user)
        ->patch(route('business.preferences.update'), ['quoting_days' => 7, 'delivery_days' => 7])
        ->assertSessionHasNoErrors();

    $this->actingAs($colleague)
        ->patch(route('business.preferences.update'), ['quoting_days' => 8, 'delivery_days' => 8])
        ->assertSessionHasNoErrors();

    expect($mine->fresh()->quoting_days)->toBe(8);

    //The other business is where it started
    expect($theirs->fresh()->quoting_days)->toBe(Business::DEFAULT_QUOTING_DAYS)
        ->and($theirs->fresh()->delivery_days)->toBe(Business::DEFAULT_DELIVERY_DAYS);
});

test('it would be a disaster if a signed-out visitor could set a business\'s lead times', function () {
    $business = createBusiness('admin');

    $this->patch(route('business.preferences.update'), ['quoting_days' => 30, 'delivery_days' => 30])
        ->assertRedirect(route('login'));

    expect($business->fresh()->quoting_days)->toBe(Business::DEFAULT_QUOTING_DAYS);
});
