<?php

use App\Models\Batch;
use App\Models\Project;
use Database\Factories\UserFactory;

test('profile page is displayed', function () {
    $business = createBusiness('admin', true);
    $user = createUser(1, $business, false, true);

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $business = createBusiness('admin', true);
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
    $business = createBusiness('admin', true);
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
    $business = createBusiness('admin', true);
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
    $business = createBusiness('admin', true);
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
    $business = createBusiness('admin', true);
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
    $business = createBusiness('admin', true);
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
    $business = createBusiness('admin', true);
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
    $business = createBusiness('admin', true);
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
    $business = createBusiness('admin', true);
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
    $business = createBusiness('admin', true);
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
