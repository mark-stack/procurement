<?php

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Batch;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\Piece;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Supplier;
use App\Models\User;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\BatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * pieceOnBatch() and quoteAndOrder() now live in tests/Pest.php - the offcut lifecycle tests build the
 * same batch. pieceOnBatch() is the minimum piece that makes a batch report a project: Batch::projects()
 * and Batch::projectApprovalFlags() both read project_id off the batch's pieces.
 */

it("would be a disaster if another business's order could be marked as sent", function () {
    /*
     * order.sent gated the batch but took order_id straight from the request, so your own batch id plus
     * somebody else's order id marked their order sent and pointed your pieces at it.
     */
    $business1 = createBusiness('biz1');
    $user1 = createUser(1, $business1, false, true);
    $batch1 = Batch::factory()->forUser($user1->id)->create();
    quoteAndOrder($user1, $batch1);

    $business2 = createBusiness('biz2');
    $user2 = createUser(1, $business2, false, true);
    $batch2 = Batch::factory()->forUser($user2->id)->create();
    [, $theirOrder] = quoteAndOrder($user2, $batch2);

    $this->actingAs($user1);

    $this->post(route('order.sent', $batch1), ['order_id' => $theirOrder->id])
        ->assertForbidden();

    expect($theirOrder->fresh()->order_sent)->toBeFalse();
});

it('would be a disaster if an order from a different batch could be marked as sent', function () {
    /*
     * Same business, so the policy passes - the order still has to belong to the batch in the URL, or
     * approving one batch silently orders another.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batchA = Batch::factory()->forUser($user->id)->create();
    $batchB = Batch::factory()->forUser($user->id)->create();
    [, $orderOnB] = quoteAndOrder($user, $batchB);

    $this->actingAs($user);

    $this->post(route('order.sent', $batchA), ['order_id' => $orderOnB->id])
        ->assertNotFound();

    expect($orderOnB->fresh()->order_sent)->toBeFalse();
});

it('marks an order sent when it belongs to the batch', function () {
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch);

    $this->actingAs($user);

    $this->post(route('order.sent', $batch), ['order_id' => $order->id])
        ->assertRedirect();

    expect($order->fresh()->order_sent)->toBeTrue();
});

it("would be a disaster if a quote could be moved onto another business's batch", function () {
    /*
     * UpdateQuoteRequest validated batch_id and quotes are $guarded = [], so validated() fed it straight
     * into update() - the policy only ever checked the quote's current owner.
     */
    $business1 = createBusiness('biz1');
    $user1 = createUser(1, $business1, false, true);
    $batch1 = Batch::factory()->forUser($user1->id)->create();
    [$quote] = quoteAndOrder($user1, $batch1);

    $business2 = createBusiness('biz2');
    $user2 = createUser(1, $business2, false, true);
    $batch2 = Batch::factory()->forUser($user2->id)->create();

    $this->actingAs($user1);

    $this->put(route('quotes.update', $quote), [
        'batch_id' => $batch2->id,
        'quote_sent' => false,
    ]);

    expect($quote->fresh()->batch_id)->toBe($batch1->id);
});

it('would be a disaster if a batch spanning two project managers could never be quoted', function () {
    /*
     * markQuoteAsSent described "you're a PM on at least 1 project" but was written as "you own every
     * project", so on a merged batch the checkbox was disabled for everyone and nothing could be quoted.
     */
    $business = createBusiness('biz');
    $user1 = createUser(1, $business, false, true);
    $user2 = createUser(2, $business, false, true);

    $batch = Batch::factory()->forUser($user1->id)->create();
    pieceOnBatch(createProject($user1), $batch);
    pieceOnBatch(createProject($user2), $batch);

    [$quote] = quoteAndOrder($user1, $batch);

    expect($batch->projectApprovalFlags())->toHaveCount(2);
    expect((new PrerequisiteConditions)->markQuoteAsSent($user1, $quote))->toBeTrue();
    expect((new PrerequisiteConditions)->markQuoteAsSent($user2, $quote))->toBeTrue();
});

it('still refuses to mark a quote sent for someone with no project on the batch', function () {
    $business = createBusiness('biz');
    $user1 = createUser(1, $business, false, true);
    $outsider = createUser(2, $business, false, true);

    $batch = Batch::factory()->forUser($user1->id)->create();
    pieceOnBatch(createProject($user1), $batch);

    [$quote] = quoteAndOrder($user1, $batch);

    expect((new PrerequisiteConditions)->markQuoteAsSent($outsider, $quote))->toBeFalse();
});

it('would be a disaster if a batch could be unwound after its materials were ordered', function () {
    /*
     * The "no order sent" condition was written as a single-argument where(), which compiles to
     * "order_sent is null" on a non-nullable boolean - so it counted 0 every time and let the batch,
     * its quotes and its orders be deleted after the materials had actually been ordered.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    pieceOnBatch(createProject($user), $batch);
    quoteAndOrder($user, $batch, orderSent: true);

    $this->actingAs($user);

    $this->delete(route('batches.destroy', $batch))->assertForbidden();

    expect(Batch::find($batch->id))->not->toBeNull();
});

it('still unwinds a batch that has no sent order', function () {
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    pieceOnBatch(createProject($user), $batch);
    quoteAndOrder($user, $batch);

    $this->actingAs($user);

    $this->delete(route('batches.destroy', $batch))->assertRedirect();

    expect(Batch::find($batch->id))->toBeNull();
});

it('would be a disaster if undoing a sent order left the approvals granted', function () {
    /*
     * Sending the order sets every approval on the batch to true. Undoing it used to leave them there,
     * so re-sending skipped the project manager approval the confirm dialog exists to collect.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch);

    $approval = OrderApproval::create([
        'batch_id' => $batch->id,
        'project_id' => $project->id,
        'project_manager_approved' => false,
    ]);

    $this->actingAs($user);

    $this->post(route('order.sent', $batch), ['order_id' => $order->id])->assertRedirect();
    expect($approval->fresh()->project_manager_approved)->toBeTrue();

    $this->post(route('order.undo.sent', $order))->assertRedirect();

    expect($order->fresh()->order_sent)->toBeFalse();
    expect($approval->fresh()->project_manager_approved)->toBeFalse();
});

it("would be a disaster if undoing one supplier group cleared another group's approval", function () {
    /*
     * OrderApproval is keyed on (batch, project), not on the supplier group, so undoing one group used
     * to clear the approval every group shares. The steel was still on order and the batch then read as
     * approved by nobody - and approved_by_user_id / approved_at, the record of who committed their
     * colleagues, were thrown away with it.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    $batch = Batch::factory()->forUser($user->id)->create();
    pieceOnBatch($project, $batch);

    [, $steel] = quoteAndOrder($user, $batch, Supplier::factory()->create(), 'STEEL_MERCHANT');
    [, $fasteners] = quoteAndOrder($user, $batch, Supplier::factory()->create(), 'FASTENERS');

    $approval = OrderApproval::create([
        'batch_id' => $batch->id,
        'project_id' => $project->id,
        'project_manager_approved' => false,
    ]);

    $this->actingAs($user);

    $this->post(route('order.sent', $batch), ['order_id' => $steel->id])->assertRedirect();
    $this->post(route('order.sent', $batch), ['order_id' => $fasteners->id])->assertRedirect();

    //Undo only the fasteners
    $this->post(route('order.undo.sent', $fasteners))->assertRedirect();

    expect($steel->fresh()->order_sent)->toBeTrue()
        ->and($approval->fresh()->project_manager_approved)->toBeTrue()
        ->and($approval->fresh()->approved_by_user_id)->toBe($user->id);

    //Once nothing is on order, the approval does go back
    $this->post(route('order.undo.sent', $steel))->assertRedirect();

    expect($approval->fresh()->project_manager_approved)->toBeFalse()
        ->and($approval->fresh()->approved_by_user_id)->toBeNull();
});

it("would be a disaster if sending one supplier's order un-sent a delivered one", function () {
    /*
     * Marking an order sent un-sends every other order in its supplier group. That used to include one
     * the steel had already arrived against, leaving a row that is delivered but not sent - which the
     * undo route refuses outright, and which the rest of the app cannot read: the certificate trail
     * filters on order_sent while the offcut inventory keys on is_delivered.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    $batch = Batch::factory()->forUser($user->id)->create();
    pieceOnBatch($project, $batch);

    [, $orderA] = quoteAndOrder($user, $batch, Supplier::factory()->create(), 'STEEL_MERCHANT');
    [, $orderB] = quoteAndOrder($user, $batch, Supplier::factory()->create(), 'STEEL_MERCHANT');

    $this->actingAs($user);

    $this->post(route('order.sent', $batch), ['order_id' => $orderA->id])->assertRedirect();
    $this->post(route('order.mark.delivered', $orderA))->assertRedirect();

    //Refused, with the reason, rather than quietly un-sending delivered steel
    $this->post(route('order.sent', $batch), ['order_id' => $orderB->id])
        ->assertSessionHasErrors('order');

    expect($orderA->fresh()->order_sent)->toBeTrue()
        ->and($orderA->fresh()->is_delivered)->toBeTrue()
        ->and($orderB->fresh()->order_sent)->toBeFalse();
});

it('would be a disaster if a second delivery post un-delivered the order', function () {
    /*
     * is_delivered was flipped rather than set, while the page disables the checkbox the moment an order
     * is delivered - so the only thing a repeated post could do was take delivered steel back out of
     * inventory, and no screen offered a way to do it deliberately.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch, null, 'STEEL_MERCHANT', false, true);

    $this->actingAs($user);

    $this->post(route('order.mark.delivered', $order))->assertRedirect();
    $this->post(route('order.mark.delivered', $order))->assertRedirect();

    expect($order->fresh()->is_delivered)->toBeTrue();
});

it('would be a disaster if an order that was never sent could be undone', function () {
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch);

    $this->actingAs($user);

    $this->post(route('order.undo.sent', $order))->assertForbidden();
});

it('would be a disaster if an order could be marked delivered before it was sent', function () {
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch);

    $this->actingAs($user);

    $this->post(route('order.mark.delivered', $order))->assertForbidden();

    expect($order->fresh()->is_delivered)->toBeFalse();
});

it('would be a disaster if the order counts fatalled on an order with no quote', function () {
    /*
     * quote_id is nullable and both models mark the relation optional, but totalOrdersQty read straight
     * through it.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    quoteAndOrder($user, $batch, supplierCategory: 'STEEL_MERCHANT');

    //An order with no quote carries no supplier category, so it is not a distinct order requirement
    Order::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_id' => Supplier::factory()->create()->id,
        'quote_id' => null,
        'order_sent' => false,
        'is_delivered' => false,
    ]);

    expect((new BatchService)->totalOrdersQty($batch))->toBe(1);
});

it('would be a disaster if saving material certs overwrote the purchase order number', function () {
    /*
     * The page shipped the supplier group's sent-order PO number as this row's form state, so saving
     * certs on any row wrote another order's PO onto it - or blanked it when nothing was sent yet.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch);

    $order->purchase_order_number = 'PO-123';
    $order->save();

    $this->actingAs($user);

    $this->put(route('orders.update', $order), [
        'purchase_order_number' => 'PO-123',
        'material_cert_numbers' => 'CERT-9',
    ])->assertRedirect();

    expect($order->fresh()->purchase_order_number)->toBe('PO-123');
    expect($order->fresh()->material_cert_numbers)->toBe('CERT-9');
});

it('leaves a field alone when the request does not carry it', function () {
    /*
     * What keeps the two editors off each other now. The PO number is edited inline on the row and
     * the certificate reference in its own panel, and each form sends only its own field - so
     * neither can write back a value it read off the row before somebody else changed it. The test
     * above pins the older, weaker guarantee: that sending both keeps both.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch);

    $order->update(['purchase_order_number' => 'PO-123', 'material_cert_numbers' => 'CERT-9']);

    $this->actingAs($user);

    //The certificates panel, saving a reference and nothing else
    $this->put(route('orders.update', $order), ['material_cert_numbers' => 'CERT-NEW'])
        ->assertRedirect();

    expect($order->fresh()->purchase_order_number)->toBe('PO-123');

    //The row's inline PO editor, saving a number and nothing else
    $this->put(route('orders.update', $order), ['purchase_order_number' => 'PO-456'])
        ->assertRedirect();

    expect($order->fresh()->material_cert_numbers)->toBe('CERT-NEW')
        ->and($order->fresh()->purchase_order_number)->toBe('PO-456');
});

it('clears a purchase order number when the box is emptied', function () {
    /*
     * There was no way to take one off. The inline editor carried "required" and "minlength" on an
     * input with no form around it, so neither did anything and an emptied box saved "" - a PO
     * number that is not there, but does not read as absent to anything asking.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch);

    $order->update(['purchase_order_number' => 'PO-123']);

    $this->actingAs($user)
        ->put(route('orders.update', $order), ['purchase_order_number' => '  '])
        ->assertRedirect();

    expect($order->fresh()->purchase_order_number)->toBeNull();
});

it('would be a disaster if discarding an unsent order threw', function () {
    /*
     * orders.destroy also firstOrCreate'd a Quote with quote_requests / quote_responses - neither column
     * exists, so with $guarded = [] the route always died on "column not found".
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch);

    $this->actingAs($user);

    $this->delete(route('orders.destroy', $order))->assertRedirect();

    /*
     * The row goes, rather than being detached with its quote_id left in place. See
     * OrderController::destroy - a detached order still referenced the quote, and orders.quote_id
     * restricts, so it made the batch permanently impossible to unwind.
     */
    expect($order->fresh())->toBeNull();
});

it('would be a disaster if discarding an order left the batch impossible to unwind', function () {
    /*
     * The order used to keep its quote_id when it was detached, and orders.quote_id is a restricting
     * foreign key - so BatchController::destroy deleted the batch's orders by batch_id, could not see
     * this one, and then threw deleting the quote it pointed at. The batch could never be re-nested,
     * and nothing in the app could reach the row to fix it.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    $project = createProject($user);
    pieceOnBatch($project, $batch);
    [, $order] = quoteAndOrder($user, $batch);

    $this->actingAs($user);

    $this->delete(route('orders.destroy', $order))->assertRedirect();
    $this->delete(route('batches.destroy', $batch))->assertRedirect();

    expect(Batch::find($batch->id))->toBeNull();
});

it("would be a disaster if another business's quotes and orders could be downloaded", function () {
    /*
     * The quotes/orders modal on the projects board is fed by download.quotes.data, which replaced the
     * /quote-order-management/{batch} page. The page authorised the batch; the endpoint has to do the
     * same, or every supplier, PO number and cert on somebody else's batch is one guessed id away.
     */
    $business1 = createBusiness('biz1');
    $user1 = createUser(1, $business1, false, true);

    $business2 = createBusiness('biz2');
    $user2 = createUser(1, $business2, false, true);
    $theirBatch = Batch::factory()->forUser($user2->id)->create();
    pieceOnBatch(createProject($user2), $theirBatch);

    $this->actingAs($user1);

    $this->getJson(route('download.quotes.data', $theirBatch))->assertForbidden();
});

it('serves the quotes and orders for your own batch', function () {
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    pieceOnBatch(createProject($user), $batch);

    $this->actingAs($user);

    $this->getJson(route('download.quotes.data', $batch))
        ->assertOk()
        ->assertJsonStructure(['quotesData' => ['info', 'supplierGroupCards']]);
});

it('names every project the batch is buying for', function () {
    /*
     * A batch is the whole Nesting column swept into one nest, so this screen listed the suppliers
     * and the sections and never said whose jobs were in the cart - and it is the screen the order
     * actually goes out from. It matters more again since the fabrication deadline sweep started
     * creating batches nobody pressed a button for: the email that brings you here names one
     * project, and this is where you find out what came with it.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();

    $mine = createProject($user);
    $mine->update(['name' => 'Conveyor gantry']);
    pieceOnBatch($mine, $batch);

    //A colleague's project, swept in with yours - the one you would not otherwise know about
    $theirs = createProject($colleague);
    $theirs->update(['name' => 'Pump station platform']);
    pieceOnBatch($theirs, $batch);

    $this->actingAs($user);

    $names = collect($this->getJson(route('download.quotes.data', $batch))
        ->assertOk()
        ->json('quotesData.info.projects'))
        ->pluck('name');

    expect($names)->toContain('Conveyor gantry');
    expect($names)->toContain('Pump station platform');
    expect($names)->toHaveCount(2);
});

it('would be a disaster if orders.store could raise orders on another business’s batch', function () {
    /**
     * Found by the route audit - orders.store had no test at all, which is how it came to die on an
     * ArgumentCountError before doing anything and nobody noticed. Nothing in resources/js posts to
     * it, but it is registered, and it takes batch_id straight out of the request body.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $otherBusiness = createBusiness('outlook');
    $otherUser = createUser(2, $otherBusiness, false, true);
    $theirBatch = Batch::factory()->forUser($otherUser->id)->create();
    pieceOnBatch(createProject($otherUser), $theirBatch);

    $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('orders.store'), ['batch_id' => $theirBatch->id])
        ->assertForbidden();

    expect(Order::count())->toBe(0)
        ->and(OrderApproval::count())->toBe(0);
});

it('raises one order per quote on your own batch, and does not double up', function () {
    /*
     * The other half. firstOrCreate is keyed on (batch, quote), so posting twice - a double click on
     * whatever ends up wired to this - has to leave one order per quote rather than two.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    $project = createProject($user);
    pieceOnBatch($project, $batch);

    $supplier = Supplier::factory()->create();
    Quote::create([
        'batch_id' => $batch->id,
        'user_id' => $user->id,
        'supplier_id' => $supplier->id,
        'supplier_category' => 'STEEL_MERCHANT',
        'quote_sent' => false,
    ]);

    $this->actingAs($user)->from('/dashboard')->post(route('orders.store'), ['batch_id' => $batch->id]);
    $this->actingAs($user)->from('/dashboard')->post(route('orders.store'), ['batch_id' => $batch->id]);

    expect(Order::where('batch_id', $batch->id)->count())->toBe(1)
        ->and(OrderApproval::where('batch_id', $batch->id)->where('project_id', $project->id)->count())->toBe(1);
});

it('would be a disaster if orders.store accepted no batch at all', function () {
    //"nullable" means validated() has no key when the field is missing, which used to be an undefined index
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('orders.store'), [])
        ->assertStatus(401);

    expect(Order::count())->toBe(0);
});
