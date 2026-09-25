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
    $business1 = createBusiness('biz1', true);
    $user1 = createUser(1, $business1, false, true);
    $batch1 = Batch::factory()->forUser($user1->id)->create();
    quoteAndOrder($user1, $batch1);

    $business2 = createBusiness('biz2', true);
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
    $business = createBusiness('biz', true);
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
    $business = createBusiness('biz', true);
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
    $business1 = createBusiness('biz1', true);
    $user1 = createUser(1, $business1, false, true);
    $batch1 = Batch::factory()->forUser($user1->id)->create();
    [$quote] = quoteAndOrder($user1, $batch1);

    $business2 = createBusiness('biz2', true);
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
    $business = createBusiness('biz', true);
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
    $business = createBusiness('biz', true);
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
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    pieceOnBatch(createProject($user), $batch);
    quoteAndOrder($user, $batch, orderSent: true);

    $this->actingAs($user);

    $this->delete(route('batches.destroy', $batch))->assertForbidden();

    expect(Batch::find($batch->id))->not->toBeNull();
});

it('still unwinds a batch that has no sent order', function () {
    $business = createBusiness('biz', true);
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
    $business = createBusiness('biz', true);
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

it('would be a disaster if an order that was never sent could be undone', function () {
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch);

    $this->actingAs($user);

    $this->post(route('order.undo.sent', $order))->assertForbidden();
});

it('would be a disaster if an order could be marked delivered before it was sent', function () {
    $business = createBusiness('biz', true);
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
    $business = createBusiness('biz', true);
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
    $business = createBusiness('biz', true);
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

it('would be a disaster if detaching an order from its batch threw', function () {
    /*
     * orders.destroy also firstOrCreate'd a Quote with quote_requests / quote_responses - neither column
     * exists, so with $guarded = [] the route always died on "column not found".
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch);

    $this->actingAs($user);

    $this->delete(route('orders.destroy', $order))->assertRedirect();

    expect($order->fresh()->batch_id)->toBeNull();
});
