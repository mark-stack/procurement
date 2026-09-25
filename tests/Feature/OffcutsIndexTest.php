<?php

use App\Models\Bar;
use App\Models\Batch;
use App\Models\Offcut;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * A batch with a delivered, certificated STEEL_MERCHANT order - which is what makes its offcuts
 * "available" on the offcuts index.
 */
function batchWithDeliveredOrder(User $user, ?string $cert = null, ?Supplier $supplier = null): Batch
{
    $batch = Batch::factory()->forUser($user->id)->create();
    $supplier ??= Supplier::factory()->create();

    $quote = Quote::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_id' => $supplier->id,
        'supplier_category' => 'STEEL_MERCHANT',
        'supplier_quote_reference' => null,
        'quote_sent' => true,
        'quoted_price' => null,
        'quoted_lead_time' => null,
    ]);

    Order::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_id' => $supplier->id,
        'quote_id' => $quote->id,
        'order_sent' => true,
        'order_confirmation_received' => true,
        'purchase_order_number' => '123',
        'is_delivered' => true,
        'material_cert_numbers' => $cert,
    ]);

    return $batch;
}

function offcutsIndexUser(): User
{
    $business = createBusiness('biz', true);

    return createUser(1, $business, false, true);
}

it('renders offcuts that have no bar', function () {
    /*
     * bar_id is nullable, and reading the label off the missing bar used to fatal:
     * "Attempt to read property product_derived_label on null".
     */
    $user = offcutsIndexUser();
    $this->actingAs($user);

    $batch = batchWithDeliveredOrder($user);
    $offcut = create_offcut_200PFC(1500, $batch->id);

    expect($offcut->bar_id)->toBeNull();

    $this->withoutExceptionHandling();
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('OffcutsIndex')
            ->has('offcuts.data', 1)
            ->where('offcuts.data.0.label', fn (string $label) => trim($label) === '200PFC')
        );
});

it('labels an offcut from its own product spec, not from its bar', function () {
    /*
     * An offcut cut from another offcut has no bar at all, so the label has to come off the offcut.
     */
    $user = offcutsIndexUser();
    $this->actingAs($user);

    $batch = batchWithDeliveredOrder($user);

    $source = create_offcut_200PFC(3000, $batch->id);
    $offcutOfOffcut = create_offcut_200PFC(900, $batch->id);
    $offcutOfOffcut->offcut_from_id = $source->id;
    $offcutOfOffcut->save();

    //A bar for an unrelated product - the old code would have read its label by mistake
    $unrelatedBar = Bar::create([
        'product_category' => 'RHS',
        'material' => 'PLAIN CARBON STEEL',
        'grade' => 'GR350',
        'surface' => 'NONE',
        'nominal_width' => 50,
        'nominal_height' => 75,
        'wall' => 2.5,
        'product_derived_label' => '75X50X2.5RHS',
        'length' => 8000,
    ]);

    expect($unrelatedBar->id)->toBe(1)
        ->and($offcutOfOffcut->offcut_from_id)->toBe($unrelatedBar->id);

    $this->withoutExceptionHandling();
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('offcuts.data', 2)
            ->where('offcuts.data.0.label', fn (string $label) => trim($label) === '200PFC')
            ->where('offcuts.data.1.label', fn (string $label) => trim($label) === '200PFC')
        );
});

it('ships the product category on each offcut', function () {
    //This was spelled "roduct_category" in the resource, so it always serialised as null
    $user = offcutsIndexUser();
    $this->actingAs($user);

    $batch = batchWithDeliveredOrder($user);
    create_offcut_200PFC(1500, $batch->id);

    $this->withoutExceptionHandling();
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('offcuts.data.0.product_category', 'PFC')
        );
});

it('reports offcut certificates as a list, and says when no offcuts were used', function () {
    /*
     * offcutOrdersWithCertificates used to return either the "NO_OFFCUTS" string or a collection keyed
     * by supplier name. Both are truthy JSON objects, and a keyed one has no .forEach.
     */
    $user = offcutsIndexUser();
    $this->actingAs($user);

    $olderBatch = batchWithDeliveredOrder($user, 'CERT-OLD', Supplier::factory()->create(['name' => 'Old Steel']));
    $batch = batchWithDeliveredOrder($user, 'CERT-NEW', Supplier::factory()->create(['name' => 'New Steel']));

    //An offcut consumed BY $batch, so $batch's own offcuts inherit its certificate
    $consumed = create_offcut_200PFC(900, $olderBatch->id);
    $consumed->batch_to_id = $batch->id;
    $consumed->save();

    create_offcut_200PFC(1500, $batch->id);

    $this->withoutExceptionHandling();
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('offcuts.data', 1)
            ->where('offcuts.data.0.offcutOrdersWithCertificates.used_offcuts', true)
            ->where('offcuts.data.0.offcutOrdersWithCertificates.certificates', [
                ['supplier_name' => 'Old Steel', 'material_cert_numbers' => 'CERT-OLD'],
            ])
        );
});

it('flags a batch that used no offcuts', function () {
    $user = offcutsIndexUser();
    $this->actingAs($user);

    $batch = batchWithDeliveredOrder($user, 'CERT-NEW');
    create_offcut_200PFC(1500, $batch->id);

    $this->withoutExceptionHandling();
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('offcuts.data.0.offcutOrdersWithCertificates.used_offcuts', false)
            ->where('offcuts.data.0.offcutOrdersWithCertificates.certificates', [])
        );
});

it('hides offcuts that are already assigned, or whose batch has no delivered order', function () {
    $user = offcutsIndexUser();
    $this->actingAs($user);

    $available = batchWithDeliveredOrder($user);
    create_offcut_200PFC(1500, $available->id);

    //Assigned to another batch
    $assigned = create_offcut_200PFC(1200, $available->id);
    $assigned->batch_to_id = Batch::factory()->forUser($user->id)->create()->id;
    $assigned->save();

    //Source batch has no delivered order at all
    $undelivered = Batch::factory()->forUser($user->id)->create();
    create_offcut_200PFC(1100, $undelivered->id);

    $this->withoutExceptionHandling();
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('offcuts.data', 1)
            ->where('offcuts.data.0.length', 1500)
        );
});

it('does not leak offcuts from another business', function () {
    $user = offcutsIndexUser();

    $otherBusiness = createBusiness('other', true);
    $otherUser = createUser(2, $otherBusiness, false, true);
    $otherBatch = batchWithDeliveredOrder($otherUser);
    create_offcut_200PFC(4000, $otherBatch->id);

    $mine = batchWithDeliveredOrder($user);
    create_offcut_200PFC(1500, $mine->id);

    $this->actingAs($user);
    $this->withoutExceptionHandling();
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('offcuts.data', 1)
            ->where('offcuts.data.0.length', 1500)
        );
});

it('does not grow its query count with the number of offcuts', function () {
    /*
     * Was ~21 queries per offcut - 218 for 10 rows, 638 for 30 - because availableOffcuts() called
     * Offcut::deliveredOrder() per row and the resource re-resolved the source batch five times.
     */
    $user = offcutsIndexUser();
    $this->actingAs($user);

    $batch = batchWithDeliveredOrder($user, 'CERT-NEW');
    create_offcut_200PFC(1500, $batch->id);

    //The first request of a process resolves a couple of shared props that later ones reuse, so warm up
    //before measuring - otherwise the two counts are not comparable
    $this->get(route('offcuts.index'))->assertOk();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->get(route('offcuts.index'))->assertOk();
    $queriesForOne = $queries;

    foreach (range(1, 19) as $n) {
        create_offcut_200PFC(1000 + $n, $batch->id);
    }

    $queries = 0;
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('offcuts.data', 20));

    expect($queries)->toBe($queriesForOne);
});

it('only exposes the index route', function () {
    //The other five resource verbs were unimplemented and had no business scoping
    $user = offcutsIndexUser();
    $this->actingAs($user);

    expect(route('offcuts.index'))->toBeString();

    foreach (['offcuts.show', 'offcuts.store', 'offcuts.update', 'offcuts.destroy', 'offcuts.create', 'offcuts.edit'] as $name) {
        expect(Route::has($name))->toBeFalse("route {$name} should not be registered");
    }
});
