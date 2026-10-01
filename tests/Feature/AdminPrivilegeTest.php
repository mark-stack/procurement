<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who the platform admin is, and what cannot make somebody one.
 *
 * The admin panel holds the master catalogue, every business's import templates, and
 * admin.impersonate - which logs the caller in as any user of any business. So the question
 * "is this user an admin" is the most load-bearing boolean in the application, and it used to be
 * answered by comparing the user's own email against config('env.admin_email') - a field the user
 * may change on /profile.
 */
it('would be a disaster if a user could make themselves admin by changing their email', function () {
    /*
     * The configured address on a work domain with no users row behind it - a support alias, which
     * is what the other contact addresses in .env.example are. Rule::unique only blocked this while
     * an account already held the address, and BusinessEmailDomain only while it was on a webmail
     * domain, so neither was the thing standing in the way.
     */
    config(['env.admin_email' => 'support@steelnesting.com.au']);

    expect(User::query()->where('email', 'support@steelnesting.com.au')->exists())->toBeFalse();

    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    expect($user->isAdmin())->toBeFalse();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Estimator',
        'email' => 'support@steelnesting.com.au',
    ])->assertRedirect();

    $user->refresh();

    //They may hold the address - it is theirs to type - but it confers nothing
    expect($user->email)->toBe('support@steelnesting.com.au')
        ->and($user->isAdmin())->toBeFalse()
        ->and($user->is_admin)->toBeFalse();

    //And the panel stays shut
    $this->actingAs($user)->get(route('admin.users.index'))->assertRedirect('/');
});

it('would be a disaster if the impersonation route opened to a non-admin', function () {
    /*
     * The one route worth naming on its own: it swaps the session onto another account, so it is
     * the whole of multi-tenancy in one request.
     */
    $business = createBusiness('fabricator');
    $user = createUser(1, $business, false, true);

    $victimBusiness = createBusiness('victim');
    $victim = createUser(2, $victimBusiness, false, true);

    $this->actingAs($user)
        ->post(route('admin.impersonate', $victim->id))
        ->assertRedirect('/');

    expect(auth()->id())->toBe($user->id);
});

it('opens the admin panel for the user carrying the flag', function () {
    //The other half: moving authority to a column must not have locked the admin out
    $business = createBusiness('admin');
    $admin = createUser(1, $business, true, true);

    expect($admin->isAdmin())->toBeTrue();

    $this->actingAs($admin)->get(route('admin.users.index'))->assertStatus(200);
});

it('keeps the admin panel shut until the admin has verified their address', function () {
    /*
     * The admin group was gated on identity alone. Nothing can forge that identity now, but an
     * account whose address has never been confirmed should not be editing every business's
     * templates either - see the middleware note in routes/adminRoutes.php.
     */
    $business = createBusiness('admin');
    $admin = createUser(1, $business, true, false);

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertRedirect(route('verification.notice'));
});

it('would be a disaster if the telescope gate still read the email', function () {
    /*
     * A second door onto the same mistake: the gate compared $user->email with the configured
     * address, so it would have survived the fix to isAdmin() and handed the request recorder -
     * every recorded request, query and exception - to whoever typed the alias into their profile.
     */
    config(['env.admin_email' => 'support@steelnesting.com.au']);

    $business = createBusiness('fabricator');

    //Holds the configured address, and nothing else
    $impostor = createUser(1, $business, false, true);
    $impostor->email = 'support@steelnesting.com.au';
    $impostor->save();

    //Holds the flag, and an address the config has never heard of
    $admin = createUser(2, $business, false, true);
    $admin->is_admin = true;
    $admin->save();

    expect(Gate::forUser($impostor)->allows('viewTelescope'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewTelescope'))->toBeTrue();
});
