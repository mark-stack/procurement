<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('would be a disaster if impersonation were reachable by a link or a prefetch', function () {
    /**
     * The route was a GET rendered as an <Link>, so a cross-site <img>, a browser prefetch
     * or a crawler hitting a logged-in admin silently swapped which account the session
     * was authenticated as - with no CSRF token involved and no way back.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    $this->actingAs($admin)
        ->get('/admin/impersonate/'.$user->id)
        ->assertMethodNotAllowed();

    expect(Auth::id())->toBe($admin->id);
});

it('would be a disaster if a non-admin could impersonate another user', function () {
    $business = createBusiness('Business A', true);
    $user = createUser(1, $business, false, true);
    $target = createUser(2, $business, false, true);

    $this->actingAs($user)
        ->post(route('admin.impersonate', $target))
        ->assertRedirect('/');

    expect(Auth::id())->toBe($user->id);
});

it('signs the admin in as the user and records the way back', function () {
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    $this->actingAs($admin)
        ->post(route('admin.impersonate', $user))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('impersonator_id', $admin->id);

    $this->assertAuthenticatedAs($user);
});

it('would be a disaster if impersonating an unverified user stranded the admin', function () {
    /**
     * dashboard sits behind 'verified', so an unverified target bounced to the verification
     * prompt - a page whose only actions were "log out" and "resend verification email",
     * which sends a real email to the customer. The admin nav is gone at that point, so
     * there was no way back short of logging out and logging in again.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    $unverified = createUser(2, $business, false, false);

    $this->actingAs($admin)
        ->post(route('admin.impersonate', $unverified))
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('impersonator_id', $admin->id);

    $this->assertAuthenticatedAs($unverified);
});

it('would be a disaster if the admin could not get back out of an impersonated session', function () {
    /**
     * Auth::login() kept nothing about who was really driving, and isAdmin() then read the
     * impersonated user's email, so every /admin route redirected away and the nav items
     * disappeared. Logging out and back in was the only exit.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    $this->actingAs($user)
        ->withSession(['impersonator_id' => $admin->id])
        ->post(route('admin.stop.impersonating'))
        ->assertRedirect(route('admin.users.index'));

    $this->assertAuthenticatedAs($admin);
});

it('would be a disaster if stop-impersonating were a way to become someone else', function () {
    /**
     * The route cannot sit behind AdminMiddleware - while impersonating, isAdmin() reads the
     * impersonated user - so it has to refuse on its own when the session was never
     * impersonating in the first place.
     */
    $business = createBusiness('Business A', true);
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->post(route('admin.stop.impersonating'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

it('ends the session when the admin to return to has been deleted', function () {
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    $adminId = $admin->id;
    $admin->delete();

    $this->actingAs($user)
        ->withSession(['impersonator_id' => $adminId])
        ->post(route('admin.stop.impersonating'))
        ->assertRedirect('/');

    $this->assertGuest();
});

it('refuses to impersonate an admin account', function () {
    /**
     * Impersonating yourself would overwrite impersonator_id with your own id, which makes
     * the way back a no-op.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)
        ->from(route('admin.users.index'))
        ->post(route('admin.impersonate', $admin))
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('warning')
        ->assertSessionMissing('impersonator_id');

    expect(Auth::id())->toBe($admin->id);
});

it('would be a disaster if the admin session payload leaked into the impersonated session', function () {
    /**
     * Auth::login() rotates the session id but keeps the attributes, so the admin's flash
     * data and intended url carried straight into the session that is now the customer's.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    $this->actingAs($admin)
        ->withSession(['url.intended' => '/admin/users', 'success' => 'Admin only'])
        ->post(route('admin.impersonate', $user))
        ->assertSessionMissing('url.intended')
        ->assertSessionMissing('success');
});

it('does not offer an impersonate button on the admin own row', function () {
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    $user = createUser(2, $business, false, true);

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page
            ->where('users.data.0.isAdmin', true)
            ->where('users.data.1.isAdmin', false)
        );

    expect(User::count())->toBe(2);
});
