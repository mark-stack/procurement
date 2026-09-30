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

it('would be a disaster if a user could rewrite another business\'s supplier', function () {
    /**
     * update() took an implicitly bound {supplier} and wrote to it with nothing narrowing which
     * supplier that could be, so PUT /suppliers/{id} renamed any row in the table. A supplier row
     * is shared - several businesses attach the same merchant, and the pivot is the only record of
     * who has - so "yours" means attached to your business, which is what SupplierPolicy asks.
     *
     * Not a cosmetic write: the name goes out on quote requests and purchase orders.
     */
    $mine = createBusiness('Mine', true);
    $theirs = createBusiness('Theirs', true);

    $me = createUser(1, $mine, false, true);

    $theirSupplier = Supplier::create([
        'name' => 'Their Merchant',
        'supplier_categories' => serialize(['STEEL_MERCHANT' => true]),
    ]);
    $theirs->suppliers()->attach($theirSupplier->id);

    $this->actingAs($me)->put(route('suppliers.update', $theirSupplier->id), [
        'name' => 'RENAMED BY OUTSIDER',
        'supplier_categories' => ['STEEL_MERCHANT' => true],
    ])->assertForbidden();

    expect($theirSupplier->fresh()->name)->toBe('Their Merchant');
});

it('lets a user rename a supplier their own business has attached', function () {
    //The other half of the rule above: scoping it must not have taken the feature away
    $mine = createBusiness('Mine', true);
    $me = createUser(1, $mine, false, true);

    $supplier = Supplier::create([
        'name' => 'Before',
        'supplier_categories' => serialize(['STEEL_MERCHANT' => true]),
    ]);
    $mine->suppliers()->attach($supplier->id);

    $this->actingAs($me)->put(route('suppliers.update', $supplier->id), [
        'name' => 'After',
        'supplier_categories' => ['STEEL_MERCHANT' => true],
    ])->assertRedirect();

    expect($supplier->fresh()->name)->toBe('After');
});

it('lets an admin rename a supplier for a business that is not theirs', function () {
    /**
     * Deliberate, and the reason SupplierPolicy passes admins: the admin supplier screen edits
     * another business's list, and its form posts to this same route.
     */
    $adminBusiness = createBusiness('admin', true);
    $admin = createUser(1, $adminBusiness, true, true);

    $theirs = createBusiness('Theirs', true);
    $supplier = Supplier::create([
        'name' => 'Before',
        'supplier_categories' => serialize(['STEEL_MERCHANT' => true]),
    ]);
    $theirs->suppliers()->attach($supplier->id);

    $this->actingAs($admin)->put(route('suppliers.update', $supplier->id), [
        'name' => 'After',
        'supplier_categories' => ['STEEL_MERCHANT' => true],
    ])->assertRedirect();

    expect($supplier->fresh()->name)->toBe('After');
});

it('would be a disaster if removing a supplier took the orders placed with it', function () {
    /**
     * Found by the route audit - suppliers.destroy had no test at all, and it is the one supplier
     * route that can destroy something. A user's press detaches the supplier from their business;
     * an admin's deletes the row outright, but only when nothing uses it.
     *
     * The row surviving is what keeps the certificate trail readable: Batch::newStockOrdersWithCertificates
     * and the offcut ancestry both read $order->supplier->name, and orders.supplier_id is nullable,
     * so a deleted supplier would leave steel already installed reporting "Unknown supplier".
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $supplier = Supplier::create(['name' => 'Merchant', 'supplier_categories' => serialize([])]);
    $business->suppliers()->attach($supplier->id);

    $batch = App\Models\Batch::factory()->forUser($user->id)->create();
    $order = App\Models\Order::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_id' => $supplier->id,
        'order_sent' => true,
    ]);

    $this->actingAs($user)
        ->from(route('suppliers.index'))
        ->delete(route('suppliers.destroy', $supplier->id))
        ->assertRedirect();

    //Off this business's list, but still the supplier the order was placed with
    expect($business->suppliers()->count())->toBe(0)
        ->and(Supplier::find($supplier->id))->not->toBeNull()
        ->and($order->fresh()->supplier_id)->toBe($supplier->id);
});

it('would be a disaster if an admin could delete a supplier that is in use', function () {
    //The admin branch is the only one that deletes the row rather than detaching it
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $adminBusiness = createBusiness('Admin Business', true);
    $admin = createUser(2, $adminBusiness, true, true);

    $supplier = Supplier::create(['name' => 'Merchant', 'supplier_categories' => serialize([])]);
    $business->suppliers()->attach($supplier->id);
    $adminBusiness->suppliers()->attach($supplier->id);

    $batch = App\Models\Batch::factory()->forUser($user->id)->create();
    App\Models\Order::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_id' => $supplier->id,
        'order_sent' => true,
    ]);

    $this->actingAs($admin)
        ->from(route('suppliers.index'))
        ->delete(route('suppliers.destroy', $supplier->id))
        ->assertRedirect();

    expect(Supplier::find($supplier->id))->not->toBeNull();
});

it('would be a disaster if the admin supplier page showed the wrong business', function () {
    //Found by the route audit - admin.suppliers.index had no test, and it takes the business by id
    $theirBusiness = createBusiness('Their Business', true);
    $otherBusiness = createBusiness('Other Business', true);

    $adminBusiness = createBusiness('Admin Business', true);
    $admin = createUser(1, $adminBusiness, true, true);

    $theirs = Supplier::create(['name' => 'Theirs', 'supplier_categories' => serialize([])]);
    $others = Supplier::create(['name' => 'Others', 'supplier_categories' => serialize([])]);
    $theirBusiness->suppliers()->attach($theirs->id);
    $otherBusiness->suppliers()->attach($others->id);

    $this->actingAs($admin)
        ->get(route('admin.suppliers.index', $theirBusiness->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('suppliers.data', 1)
            ->where('suppliers.data.0.name', 'Theirs')
            ->where('business.id', $theirBusiness->id)
            ->where('adminView', true)
            ->etc()
        );
});

it('would be a disaster if a non-admin could read another business’s supplier page', function () {
    $theirBusiness = createBusiness('Their Business', true);
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('admin.suppliers.index', $theirBusiness->id))
        ->assertRedirect();
});
