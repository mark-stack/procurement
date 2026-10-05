<?php

use App\Models\Batch;
use App\Models\Order;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * An order on a batch, with the quote that carries its supplier category.
 *
 * Named apart from QuoteOrderManagementTest's quoteAndOrder() - pest loads every test file into the
 * one process, so a second declaration of that name is a fatal, not an override.
 */
function pastBatchOrder(
    User $user,
    Batch $batch,
    string $supplierCategory = 'STEEL_MERCHANT',
    bool $orderSent = true,
    bool $isDelivered = true,
): Order {

    $quote = Quote::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_category' => $supplierCategory,
        'supplier_quote_reference' => null,
        'quote_sent' => true,
        'quoted_price' => null,
        'quoted_lead_time' => null,
    ]);

    return Order::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'quote_id' => $quote->id,
        'order_sent' => $orderSent,
        'is_delivered' => $isDelivered,
    ]);
}

it("would be a disaster if another business's batch could be closed", function () {
    /*
     * This was the one batch controller with no gate on it, so any onboarded user could close any
     * other business's live batch by id - and nothing in the app sets "done" back.
     */
    $business1 = createBusiness('biz1');
    $user1 = createUser(1, $business1, false, true);

    $business2 = createBusiness('biz2');
    $user2 = createUser(1, $business2, false, true);
    $theirBatch = Batch::factory()->forUser($user2->id)->create(['done' => false]);

    $this->actingAs($user1);

    $this->post(route('mark.as.past.batch', $theirBatch))
        ->assertForbidden();

    expect((bool) $theirBatch->fresh()->done)->toBeFalse();
});

it('would be a disaster if a batch could be closed with materials still out for delivery', function () {
    /*
     * The kanban cards only offer "Move to done" once the deliveries are in, but that is the button's
     * own state - a stale tab or a replayed post reached the controller with nothing to stop it.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    pastBatchOrder($user, $batch, orderSent: true, isDelivered: false);

    $this->actingAs($user);

    $this->post(route('mark.as.past.batch', $batch))
        ->assertForbidden();

    expect((bool) $batch->fresh()->done)->toBeFalse();
});

/**
 * A batch with one of this user's jobs on it, which is what the press now asks for.
 *
 * Closing a batch takes every job on it off the Nesting page, colleagues' included, so it is gated
 * the way the rest of that card's menu is: your own work has to be on it - see
 * PrerequisiteConditions::markBatchDone. The fixtures below carried no project at all, which is a
 * shape nothing in the application makes: a batch exists because projects were nested into it.
 */
function pastBatchFor(User $user, bool $deliveredByHand = false): Batch
{
    $batch = Batch::factory()->forUser($user->id)->create([
        'done' => false,
        'delivered_at' => $deliveredByHand ? now() : null,
    ]);

    pieceOnBatch(createProject($user), $batch);

    return $batch;
}

it('still closes a batch once its sent orders are delivered', function () {
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = pastBatchFor($user);
    $order = pastBatchOrder($user, $batch, orderSent: true, isDelivered: true);

    //Every material row pointing at that sent order, which is what reads as delivered - see BatchStages
    $batch->pieces()->update(['order_id' => $order->id]);

    $this->actingAs($user);

    $this->post(route('mark.as.past.batch', $batch))
        ->assertRedirect();

    expect((bool) $batch->fresh()->done)->toBeTrue();
});

it('still closes a batch that never placed an order', function () {
    /*
     * A batch nested entirely out of offcuts places no order at all. Gating on the kanban's
     * "delivered rows === supplier categories" sum would have read 0 === 0 here and gating on
     * "has a delivered order" would have locked the batch open forever.
     *
     * It says its steel is in the same way every batch bought off the application says it - the
     * Nesting card's "All delivered" mark, which is also the card that then offers to close it.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = pastBatchFor($user, deliveredByHand: true);

    $this->actingAs($user);

    $this->post(route('mark.as.past.batch', $batch))
        ->assertRedirect();

    expect((bool) $batch->fresh()->done)->toBeTrue();
});

it('still ignores an order that was drafted but never sent', function () {
    /*
     * An Order row is firstOrCreate'd for every supplier group the moment someone opens the quote
     * screen. Those drafts are not outstanding deliveries.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = pastBatchFor($user, deliveredByHand: true);
    pastBatchOrder($user, $batch, orderSent: false, isDelivered: false);

    $this->actingAs($user);

    $this->post(route('mark.as.past.batch', $batch))
        ->assertRedirect();

    expect((bool) $batch->fresh()->done)->toBeTrue();
});

it('would be a disaster if a batch nobody has delivered could be closed', function () {
    /*
     * The other half of the gate, and the reason the three above now name a delivery: closing a batch
     * is a one-way door - nothing in the application re-opens one - so a job whose steel has not
     * arrived must not go through it. The card only offers the press on a delivered batch; this is the
     * press arriving without the card.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = pastBatchFor($user);

    $this->actingAs($user);

    $this->post(route('mark.as.past.batch', $batch))
        ->assertForbidden();

    expect((bool) $batch->fresh()->done)->toBeFalse();
});

it('would be a disaster if a colleague with no job on the batch could close it', function () {
    //Every other press on that card's menu draws this line, and this one takes the card away entirely
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $batch = pastBatchFor($user, deliveredByHand: true);

    $this->actingAs($colleague);

    $this->post(route('mark.as.past.batch', $batch))
        ->assertForbidden();

    expect((bool) $batch->fresh()->done)->toBeFalse();
});

it("would be a disaster if past batches listed another business's batches", function () {
    $business1 = createBusiness('biz1');
    $user1 = createUser(1, $business1, false, true);
    $mine = Batch::factory()->forUser($user1->id)->create(['done' => true]);
    $stillActive = Batch::factory()->forUser($user1->id)->create(['done' => false]);

    $business2 = createBusiness('biz2');
    $user2 = createUser(1, $business2, false, true);
    $theirs = Batch::factory()->forUser($user2->id)->create(['done' => true]);

    $this->actingAs($user1);

    $response = $this->get(route('past.batches.index'))->assertOk();

    $listedIds = collect($response->viewData('page')['props']['pastBatches'])->pluck('id');

    expect($listedIds)->toContain($mine->id)
        ->not->toContain($theirs->id)
        ->not->toContain($stillActive->id);
});

it('counts a closed batch the way the live card counts it', function () {
    /*
     * The two numbers under the card's buttons. Both are read off the material on the batch, the way
     * NestingIndexController reads them for a live card, so a batch says the same thing the day
     * after it closes as the day before: the cuts are the pieces' quantities summed rather than the
     * rows counted, and the categories are the supplier groups the material falls into.
     *
     * This page used to count Order rows instead, which is a different question with a different
     * answer - a batch bought over the phone and marked ordered by hand has no order row at all.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => true]);
    $project = createProject($user);

    //Two lines of demand, twelve cuts off the saw - and both of them steel
    pieceOnBatch($project, $batch)->update(['actual_qty' => 10]);
    pieceOnBatch($project, $batch)->update(['actual_qty' => 2]);

    $this->actingAs($user);

    $response = $this->get(route('past.batches.index'))->assertOk();
    $row = collect($response->viewData('page')['props']['pastBatches'])->firstWhere('id', $batch->id);

    expect($row['cutCount'])->toBe(12)
        ->and($row['categoryCount'])->toBe(1);
});

it('sends past batches nothing but the project names it renders', function () {
    /*
     * The table labels each row with project names and reads nothing else off them. This used to ship
     * Batch::projects() - every project's owner and its whole raw material quote tree - plus a
     * nestingData prop plucked from the saved nesting that no page has ever read.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => true]);
    $project = createProject($user);
    pieceOnBatch($project, $batch);

    $this->actingAs($user);

    $response = $this->get(route('past.batches.index'))->assertOk();
    $row = collect($response->viewData('page')['props']['pastBatches'])->firstWhere('id', $batch->id);

    expect(array_keys($row))->toBe(['id', 'createdAt', 'projectManagers', 'batchedBy', 'projects', 'cutCount', 'categoryCount'])
        ->and($row['projects'])->toHaveCount(1)
        //Still just the names: the managers arrive as one joined string, not as the owners themselves
        ->and(array_keys($row['projects'][0]))->toBe(['id', 'name'])
        ->and($row['projectManagers'])->toBeString();
});

it('would be a disaster if past batches credited a batch to the wrong person', function () {
    /*
     * "Start quoting" sweeps in every project that was ready, colleagues' included, and the batch
     * belongs to whoever pressed it. This row used to be labelled with that person - so a batch
     * spanning two project managers named one of them at most, and often neither.
     */
    $business = createBusiness('biz');
    $batcher = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $batch = Batch::factory()->forUser($batcher->id)->create(['done' => true]);
    pieceOnBatch(createProject($colleague), $batch);

    $this->actingAs($batcher);

    $response = $this->get(route('past.batches.index'))->assertOk();
    $row = collect($response->viewData('page')['props']['pastBatches'])->firstWhere('id', $batch->id);

    //The owner of the work, not the person who nested it
    expect($row['projectManagers'])->toBe($colleague->name)
        ->and($row['batchedBy'])->toBe($batcher->name);
});

it('would be a disaster if a batch spanning two managers named only one', function () {
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => true]);
    pieceOnBatch(createProject($user), $batch);
    pieceOnBatch(createProject($colleague), $batch);

    $this->actingAs($user);

    $response = $this->get(route('past.batches.index'))->assertOk();
    $row = collect($response->viewData('page')['props']['pastBatches'])->firstWhere('id', $batch->id);

    expect($row['projectManagers'])->toContain($user->name)
        ->and($row['projectManagers'])->toContain($colleague->name);
});

it('reads past batches in a fixed number of queries however many batches it holds', function () {
    /*
     * This list only ever grows and nothing caps the row count. It used to make nine
     * queries a row - the batch's user, the project tree, and an orders count, one batch at a time.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $addBatches = function (int $batches) use ($user): void {
        foreach (range(1, $batches) as $i) {
            $batch = Batch::factory()->forUser($user->id)->create(['done' => true]);
            pieceOnBatch(createProject($user), $batch);
            pastBatchOrder($user, $batch);
        }
    };

    // DB::listen has no matching "stop", so each leg counts into its own variable
    $countQueries = function (): int {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->get(route('past.batches.index'))->assertOk();

        return $queries;
    };

    $this->actingAs($user);
    $addBatches(2);

    /*
     * The first request of the test resolves the user's business and notifications for the shared
     * inertia props, and both stay hydrated on the guard afterwards. Warm those two off before
     * counting, or the first leg is charged for them and the second is not.
     */
    $this->get(route('past.batches.index'))->assertOk();

    $twoBatches = $countQueries();

    $addBatches(8);
    $tenBatches = $countQueries();

    expect($tenBatches)->toBe($twoBatches);
});
