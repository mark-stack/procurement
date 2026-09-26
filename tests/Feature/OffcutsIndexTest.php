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

it('lists an offcut cut from another offcut even when its batch ordered nothing new', function () {
    /*
     * An offcut cut from an offcut was already in the yard - the delivery that put it there was against
     * the SOURCE offcut's batch. A batch that nests entirely out of inventory places no order at all, so
     * requiring a delivered order on its own batch hid these offcuts for good.
     */
    $user = offcutsIndexUser();
    $this->actingAs($user);

    $sourceBatch = batchWithDeliveredOrder($user, 'CERT-OLD');
    $source = create_offcut_200PFC(3000, $sourceBatch->id);

    //Consumed by a batch that bought nothing, so that batch has no order of any kind
    $nestedFromStock = Batch::factory()->forUser($user->id)->create();
    $source->batch_to_id = $nestedFromStock->id;
    $source->save();

    $child = create_offcut_200PFC(900, $nestedFromStock->id);
    $child->offcut_from_id = $source->id;
    $child->save();

    $this->withoutExceptionHandling();
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('offcuts.data', 1)
            ->where('offcuts.data.0.length', 900)
            ->where('offcuts.data.0.offcut_from_id', $source->id)
        );
});

it('keeps every certificate when one supplier certificated several source batches', function () {
    /*
     * The certificates were collected into an array KEYED by supplier name, so a merchant that supplied
     * two of the source batches kept only the last certificate read and the rest fell out of the
     * traceability trail.
     */
    $user = offcutsIndexUser();
    $this->actingAs($user);

    $supplier = Supplier::factory()->create(['name' => 'One Steel']);

    $batch = batchWithDeliveredOrder($user, 'CERT-NEW');

    foreach (['CERT-1', 'CERT-2'] as $cert) {
        $olderBatch = batchWithDeliveredOrder($user, $cert, $supplier);

        $consumed = create_offcut_200PFC(900, $olderBatch->id);
        $consumed->batch_to_id = $batch->id;
        $consumed->save();
    }

    create_offcut_200PFC(1500, $batch->id);

    $this->withoutExceptionHandling();
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('offcuts.data', 1)
            ->where('offcuts.data.0.offcutOrdersWithCertificates.certificates', [
                ['supplier_name' => 'One Steel', 'material_cert_numbers' => 'CERT-1'],
                ['supplier_name' => 'One Steel', 'material_cert_numbers' => 'CERT-2'],
            ])
        );
});

it('does not ship the source batch, and with it the whole saved nest, on every row', function () {
    //batch_from carried the Batch model, nested_state included - once per offcut on the page
    $user = offcutsIndexUser();
    $this->actingAs($user);

    $batch = batchWithDeliveredOrder($user);
    create_offcut_200PFC(1500, $batch->id);

    $this->withoutExceptionHandling();
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('offcuts.data.0.batch_from')
            ->where('offcuts.data.0.batch_from_id', $batch->id)
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

/**
 * A chain of offcuts, each one cut from the one before it, oldest first.
 *
 * Only the root batch buys steel. Every batch after it nests entirely out of inventory and so places
 * no order at all - which is exactly what makes the certificate trail hard to follow.
 *
 * @return array<int, Offcut>
 */
function offcutGenerations(User $user, Batch $rootBatch, int $generations, int $length = 9000): array
{
    $chain = [create_offcut_200PFC($length, $rootBatch->id)];

    foreach (range(2, $generations) as $generation) {
        $source = end($chain);
        $length -= 1000;

        $cuttingBatch = Batch::factory()->forUser($user->id)->create();
        $source->batch_to_id = $cuttingBatch->id;
        $source->save();

        $produced = create_offcut_200PFC($length, $cuttingBatch->id);
        $produced->offcut_from_id = $source->id;
        $produced->save();

        $chain[] = $produced;
    }

    return $chain;
}

it('keeps the certificate trail on an offcut of an offcut of an offcut', function () {
    /*
     * The trail only ever looked at the batch that CUT each offcut. That batch bought the steel for a
     * first-generation offcut, so the certificate was right there - but from the third generation on the
     * cutting batch nested straight out of inventory and bought nothing, so the lookup came back empty
     * and the print spec said "Offcuts are not traceable!" about steel that is fully traceable.
     */
    $user = offcutsIndexUser();
    $this->actingAs($user);

    $rootBatch = batchWithDeliveredOrder($user, 'CERT-ROOT', Supplier::factory()->create(['name' => 'Root Steel']));

    //9000 off a bar -> 8000 -> 7000 -> 6000, each cut from the one before it
    $chain = offcutGenerations($user, $rootBatch, 4);
    $deepest = end($chain);

    $this->withoutExceptionHandling();
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            //Only the last one is still unassigned, the rest were consumed on the way down
            ->has('offcuts.data', 1)
            ->where('offcuts.data.0.id', $deepest->id)
            ->where('offcuts.data.0.offcutOrdersWithCertificates.used_offcuts', true)
            ->where('offcuts.data.0.offcutOrdersWithCertificates.certificates', [
                ['supplier_name' => 'Root Steel', 'material_cert_numbers' => 'CERT-ROOT'],
            ])
        );
});

it('reports how far down an offcut has been cut, and the marks it came through', function () {
    /*
     * There is no limit on cutting an offcut out of an offcut - every generation is real steel in the
     * yard, and the chain ends on its own once the drop falls under the scrap threshold. So the yard has
     * to be able to see how many times a piece has already been cut down: a 6m drop that has been
     * through four batches otherwise looks exactly like one straight off a 12m bar.
     */
    $user = offcutsIndexUser();
    $this->actingAs($user);

    $rootBatch = batchWithDeliveredOrder($user, 'CERT-ROOT');
    $chain = offcutGenerations($user, $rootBatch, 4);

    //Nearest source first, back to the one that came off the bar
    $expectedMarks = [$chain[2]->unique_mark, $chain[1]->unique_mark, $chain[0]->unique_mark];

    $this->withoutExceptionHandling();
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('offcuts.data.0.generation', 4)
            ->where('offcuts.data.0.cut_from_marks', $expectedMarks)
        );
});

it('calls an offcut straight off a bar the first generation', function () {
    $user = offcutsIndexUser();
    $this->actingAs($user);

    $batch = batchWithDeliveredOrder($user, 'CERT-NEW');
    create_offcut_200PFC(1500, $batch->id);

    $this->withoutExceptionHandling();
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('offcuts.data.0.generation', 1)
            ->where('offcuts.data.0.cut_from_marks', [])
        );
});

it('does not stamp an offcut of an offcut with its cutting batch\'s own new stock certificate', function () {
    /*
     * A batch that reuses an offcut can buy new steel for other products in the same nest. That
     * purchase has nothing to do with the drop left on the reused offcut, so crediting its certificate
     * to that drop labels the steel with a certificate it was never part of. The offcut's own trail runs
     * back up the chain.
     */
    $user = offcutsIndexUser();
    $this->actingAs($user);

    $rootBatch = batchWithDeliveredOrder($user, 'CERT-ROOT', Supplier::factory()->create(['name' => 'Root Steel']));
    $source = create_offcut_200PFC(3000, $rootBatch->id);

    //The batch that reuses it also buys unrelated new steel, certificated
    $cuttingBatch = batchWithDeliveredOrder($user, 'CERT-UNRELATED', Supplier::factory()->create(['name' => 'Other Steel']));
    $source->batch_to_id = $cuttingBatch->id;
    $source->save();

    $produced = create_offcut_200PFC(1200, $cuttingBatch->id);
    $produced->offcut_from_id = $source->id;
    $produced->save();

    $this->withoutExceptionHandling();
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('offcuts.data', 1)
            ->where('offcuts.data.0.id', $produced->id)
            ->where('offcuts.data.0.newStockOrdersWithCertificates', [])
            ->where('offcuts.data.0.offcutOrdersWithCertificates.certificates', [
                ['supplier_name' => 'Root Steel', 'material_cert_numbers' => 'CERT-ROOT'],
            ])
        );
});

it('keeps a deep chain out of inventory once each generation has been consumed', function () {
    /*
     * Every generation but the last has been cut up, so only the last is still steel anybody can nest
     * into. A released or double-counted ancestor would have the yard promising material it has already
     * turned into pieces.
     */
    $user = offcutsIndexUser();
    $this->actingAs($user);

    $rootBatch = batchWithDeliveredOrder($user, 'CERT-ROOT');
    $chain = offcutGenerations($user, $rootBatch, 5);

    $this->withoutExceptionHandling();
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('offcuts.data', 1)
            ->where('offcuts.data.0.id', end($chain)->id)
            ->where('offcuts.data.0.generation', 5)
        );
});
