<?php

use App\Models\Batch;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Supplier;
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
function pastProjectOrder(
    User $user,
    Batch $batch,
    string $supplierCategory = 'STEEL_MERCHANT',
    bool $orderSent = true,
    bool $isDelivered = true,
): Order {
    $supplier = Supplier::factory()->create();

    $quote = Quote::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_id' => $supplier->id,
        'supplier_category' => $supplierCategory,
        'supplier_quote_reference' => null,
        'quote_sent' => true,
        'quoted_price' => null,
        'quoted_lead_time' => null,
    ]);

    return Order::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_id' => $supplier->id,
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
    $business1 = createBusiness('biz1', true);
    $user1 = createUser(1, $business1, false, true);

    $business2 = createBusiness('biz2', true);
    $user2 = createUser(1, $business2, false, true);
    $theirBatch = Batch::factory()->forUser($user2->id)->create(['done' => false]);

    $this->actingAs($user1);

    $this->post(route('mark.as.past.project', $theirBatch))
        ->assertForbidden();

    expect((bool) $theirBatch->fresh()->done)->toBeFalse();
});

it('would be a disaster if a batch could be closed with materials still out for delivery', function () {
    /*
     * The kanban cards only offer "Move to done" once the deliveries are in, but that is the button's
     * own state - a stale tab or a replayed post reached the controller with nothing to stop it.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    pastProjectOrder($user, $batch, orderSent: true, isDelivered: false);

    $this->actingAs($user);

    $this->post(route('mark.as.past.project', $batch))
        ->assertForbidden();

    expect((bool) $batch->fresh()->done)->toBeFalse();
});

it('still closes a batch once its sent orders are delivered', function () {
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    pastProjectOrder($user, $batch, orderSent: true, isDelivered: true);

    $this->actingAs($user);

    $this->post(route('mark.as.past.project', $batch))
        ->assertRedirect();

    expect((bool) $batch->fresh()->done)->toBeTrue();
});

it('still closes a batch that never placed an order', function () {
    /*
     * A batch nested entirely out of offcuts places no order at all. Gating on the kanban's
     * "delivered rows === supplier categories" sum would have read 0 === 0 here and gating on
     * "has a delivered order" would have locked the batch open forever.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);

    $this->actingAs($user);

    $this->post(route('mark.as.past.project', $batch))
        ->assertRedirect();

    expect((bool) $batch->fresh()->done)->toBeTrue();
});

it('still ignores an order that was drafted but never sent', function () {
    /*
     * An Order row is firstOrCreate'd for every supplier group the moment someone opens the quote
     * screen. Those drafts are not outstanding deliveries.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    pastProjectOrder($user, $batch, orderSent: false, isDelivered: false);

    $this->actingAs($user);

    $this->post(route('mark.as.past.project', $batch))
        ->assertRedirect();

    expect((bool) $batch->fresh()->done)->toBeTrue();
});

it("would be a disaster if the archive listed another business's batches", function () {
    $business1 = createBusiness('biz1', true);
    $user1 = createUser(1, $business1, false, true);
    $mine = Batch::factory()->forUser($user1->id)->create(['done' => true]);
    $stillActive = Batch::factory()->forUser($user1->id)->create(['done' => false]);

    $business2 = createBusiness('biz2', true);
    $user2 = createUser(1, $business2, false, true);
    $theirs = Batch::factory()->forUser($user2->id)->create(['done' => true]);

    $this->actingAs($user1);

    $response = $this->get(route('past.projects.index'))->assertOk();

    $listedIds = collect($response->viewData('page')['props']['pastBatches'])->pluck('id');

    expect($listedIds)->toContain($mine->id)
        ->not->toContain($theirs->id)
        ->not->toContain($stillActive->id);
});

it('counts the orders a batch needed, not the drafts it accumulated', function () {
    /*
     * A plain orders count reported every draft row. BatchService::totalOrdersQty counts unique
     * supplier categories for exactly this reason, and the archive has to agree with it.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => true]);

    // Two rows for the one supplier group, plus a second group
    pastProjectOrder($user, $batch, supplierCategory: 'STEEL_MERCHANT');
    pastProjectOrder($user, $batch, supplierCategory: 'STEEL_MERCHANT', orderSent: false, isDelivered: false);
    pastProjectOrder($user, $batch, supplierCategory: 'FASTENERS');

    $this->actingAs($user);

    $response = $this->get(route('past.projects.index'))->assertOk();
    $row = collect($response->viewData('page')['props']['pastBatches'])->firstWhere('id', $batch->id);

    expect($row['ordersQty'])->toBe(2);
});

it('sends the archive nothing but the project names it renders', function () {
    /*
     * The table labels each row with project names and reads nothing else off them. This used to ship
     * Batch::projects() - every project's owner and its whole raw material quote tree - plus a
     * nestingData prop plucked from the saved nesting that no page has ever read.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => true]);
    $project = createProject($user);
    pieceOnBatch($project, $batch);

    $this->actingAs($user);

    $response = $this->get(route('past.projects.index'))->assertOk();
    $row = collect($response->viewData('page')['props']['pastBatches'])->firstWhere('id', $batch->id);

    expect(array_keys($row))->toBe(['id', 'createdAt', 'projectManager', 'projects', 'ordersQty'])
        ->and($row['projects'])->toHaveCount(1)
        ->and(array_keys($row['projects'][0]))->toBe(['id', 'name']);
});

it('reads the archive in a fixed number of queries however many batches it holds', function () {
    /*
     * This is the archive, so it only ever grows and nothing caps the row count. It used to make nine
     * queries a row - the batch's user, the project tree, and an orders count, one batch at a time.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    $addBatches = function (int $batches) use ($user): void {
        foreach (range(1, $batches) as $i) {
            $batch = Batch::factory()->forUser($user->id)->create(['done' => true]);
            pieceOnBatch(createProject($user), $batch);
            pastProjectOrder($user, $batch);
        }
    };

    // DB::listen has no matching "stop", so each leg counts into its own variable
    $countQueries = function (): int {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->get(route('past.projects.index'))->assertOk();

        return $queries;
    };

    $this->actingAs($user);
    $addBatches(2);

    /*
     * The first request of the test resolves the user's business and notifications for the shared
     * inertia props, and both stay hydrated on the guard afterwards. Warm those two off before
     * counting, or the first leg is charged for them and the second is not.
     */
    $this->get(route('past.projects.index'))->assertOk();

    $twoBatches = $countQueries();

    $addBatches(8);
    $tenBatches = $countQueries();

    expect($tenBatches)->toBe($twoBatches);
});
