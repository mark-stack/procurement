<?php

use App\Formatters\KanbanFormatter;
use App\Models\Batch;
use App\Models\Order;
use App\Models\Piece;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * An order on a batch, with the quote that carries its supplier category.
 *
 * Named apart from the other files' helpers - pest loads every test file into the one process, so a
 * second declaration of an existing name is a fatal, not an override.
 */
function deliveredColumnOrder(
    User $user,
    Batch $batch,
    string $supplierCategory = 'STEEL_MERCHANT',
    bool $orderSent = true,
    bool $isDelivered = true,
    ?string $materialCertNumbers = 'CERT-1',
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
        'material_cert_numbers' => $materialCertNumbers,
    ]);
}

/**
 * A batch in the Delivering column: every raw material row on its project points at a sent order, so
 * KanbanFormatter reads 100% order coverage.
 *
 * @return array{0: Batch, 1: Order}
 */
function batchInDeliveringColumn(User $user, bool $isDelivered = true): array
{
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    $project = createProject($user);
    $piece = pieceOnBatch($project, $batch);

    $order = deliveredColumnOrder($user, $batch, isDelivered: $isDelivered);

    $piece->order_id = $order->id;
    $piece->save();

    return [$batch, $order];
}

function deliveredColumnRow(Batch $batch): ?array
{
    $rows = (new KanbanFormatter())->deliveredColumn($batch->user->business);

    return collect($rows)->first(fn ($row) => $row['info']['batch']['id'] === $batch->id)['info'] ?? null;
}

it('offers "Move to done" once the sent orders are in', function () {
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    [$batch] = batchInDeliveringColumn($user);

    $this->actingAs($user);

    $row = deliveredColumnRow($batch);

    expect($row)->not->toBeNull()
        ->and($row['allDelivered'])->toBeTrue();
});

it('would be a disaster if a batch delivered by two suppliers in one group could never be closed', function () {
    /*
     * Orders are created one per quote and quotes one per supplier in a group, so two steel merchants
     * on the one batch is the ordinary shape, not an edge case. Counting delivered ROWS against unique
     * supplier CATEGORIES read 2 === 1 here, the button never appeared, and nothing else in the app
     * sets "done" - the batch was stuck on the board for good.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    [$batch] = batchInDeliveringColumn($user);
    deliveredColumnOrder($user, $batch, supplierCategory: 'STEEL_MERCHANT');

    $this->actingAs($user);

    expect(deliveredColumnRow($batch)['allDelivered'])->toBeTrue();
});

it('would be a disaster if it offered "Move to done" with a delivery still out', function () {
    /*
     * The mirror of the count above: two delivered steel rows and one outstanding fasteners row also
     * read 2 === 2, so the card offered a button the controller then refused with a 403.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    [$batch] = batchInDeliveringColumn($user);
    deliveredColumnOrder($user, $batch, supplierCategory: 'STEEL_MERCHANT');
    deliveredColumnOrder($user, $batch, supplierCategory: 'FASTENERS', isDelivered: false);

    $this->actingAs($user);

    expect(deliveredColumnRow($batch)['allDelivered'])->toBeFalse();
});

it('ignores a drafted but never sent order when deciding everything is in', function () {
    /*
     * An Order row is created for every supplier group the moment someone opens the quote screen.
     * Those drafts are not outstanding deliveries - the controller ignores them and so must the card.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    [$batch] = batchInDeliveringColumn($user);
    deliveredColumnOrder($user, $batch, orderSent: false, isDelivered: false, materialCertNumbers: null);

    $this->actingAs($user);

    expect(deliveredColumnRow($batch)['allDelivered'])->toBeTrue();
});

it('warns when a delivered steel merchant order has no material certs', function () {
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    $project = createProject($user);
    $piece = pieceOnBatch($project, $batch);

    $order = deliveredColumnOrder($user, $batch, materialCertNumbers: null);
    $piece->order_id = $order->id;
    $piece->save();

    $this->actingAs($user);

    expect(deliveredColumnRow($batch)['steelMerchantDeliveredButNoCertsYet'])->toBeTrue();
});

it('would be a disaster if a second steel merchant\'s draft row withheld the certs warning forever', function () {
    /*
     * The warning hides "Move to done" behind it. It used to match any steel merchant row with no cert
     * numbers, drafts included - and a business with two steel merchants always has one, so the button
     * was hidden for good however complete the real order's certs were.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    [$batch] = batchInDeliveringColumn($user);
    deliveredColumnOrder($user, $batch, orderSent: false, isDelivered: false, materialCertNumbers: null);

    $this->actingAs($user);

    $row = deliveredColumnRow($batch);

    expect($row['steelMerchantDeliveredButNoCertsYet'])->toBeFalse()
        ->and($row['allDelivered'])->toBeTrue();
});

it('leaves a batch whose orders are all delivered closeable by the controller it draws the button for', function () {
    /*
     * The card's test and the controller's guard have to agree, or the button lies about what the post
     * will do. This is the same batch as the first test, put through the actual route.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    [$batch] = batchInDeliveringColumn($user);
    deliveredColumnOrder($user, $batch, supplierCategory: 'STEEL_MERCHANT');

    $this->actingAs($user);

    expect(deliveredColumnRow($batch)['allDelivered'])->toBeTrue();

    $this->post(route('mark.as.past.project', $batch))->assertRedirect();

    expect((bool) $batch->fresh()->done)->toBeTrue();
});
