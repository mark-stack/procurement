<?php

use App\Models\Supplier;

it('would be a disaster if the suppliers page showed another business\'s suppliers', function () {
    /**
     * The route takes no business id - the business is the user's own, so
     * there is no id to tamper with. This pins that down.
     */
    $businessA = createBusiness('Business A', true);
    $businessB = createBusiness('Business B', true);

    $userA = createUser(1, $businessA, false, true);

    $ours = Supplier::create(['name' => 'Ours', 'supplier_categories' => serialize([])]);
    $theirs = Supplier::create(['name' => 'Theirs', 'supplier_categories' => serialize([])]);

    $businessA->suppliers()->attach($ours->id);
    $businessB->suppliers()->attach($theirs->id);

    $this->actingAs($userA)
        ->get(route('suppliers.index'))
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->has('suppliers.data', 1)
            ->where('suppliers.data.0.name', 'Ours')
        );
});

it('would be a disaster if a new supplier was attached to the wrong business', function () {
    /**
     * store() used to take the business from the url without authorizing it,
     * so any user could attach a supplier to any business.
     */
    $businessA = createBusiness('Business A', true);
    $businessB = createBusiness('Business B', true);

    $userA = createUser(1, $businessA, false, true);

    $this->actingAs($userA)->post(route('suppliers.store'), [
        'name' => 'New Supplier',
        'supplier_categories' => ['STEEL_MERCHANT' => true],
    ])->assertRedirect();

    expect($businessA->suppliers()->count())->toBe(1);
    expect($businessB->suppliers()->count())->toBe(0);
});

it('would be a disaster if an admin adding a supplier for a business added it to their own', function () {
    /**
     * The admin page is the one place the business is not the session's own,
     * so it posts to the admin route that still takes it as a parameter.
     */
    $adminBusiness = createBusiness('Admin Business', true);
    $theirBusiness = createBusiness('Their Business', true);

    $admin = createUser(1, $adminBusiness, true, true);

    $this->actingAs($admin)->post(route('admin.suppliers.store', $theirBusiness->id), [
        'name' => 'New Supplier',
        'supplier_categories' => ['STEEL_MERCHANT' => true],
    ])->assertRedirect();

    expect($theirBusiness->suppliers()->count())->toBe(1);
    expect($adminBusiness->suppliers()->count())->toBe(0);
});

it('would be a disaster if a non-admin could add a supplier for another business', function () {
    $businessA = createBusiness('Business A', true);
    $businessB = createBusiness('Business B', true);

    $userA = createUser(1, $businessA, false, true);

    $this->actingAs($userA)->post(route('admin.suppliers.store', $businessB->id), [
        'name' => 'New Supplier',
        'supplier_categories' => ['STEEL_MERCHANT' => true],
    ])->assertRedirect('/');

    expect($businessB->suppliers()->count())->toBe(0);
});

it('would be a disaster if a user without a business hit a 500 on the suppliers page', function () {
    $user = \App\Models\User::factory()->create(['business_id' => null]);

    $this->actingAs($user)
        ->get(route('suppliers.index'))
        ->assertRedirect(route('onboarding'));
});
