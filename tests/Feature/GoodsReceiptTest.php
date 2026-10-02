<?php

use App\Enums\GoodsReceiptNonconformanceEnums;
use App\Formatters\QuoteFormatter;
use App\Models\Batch;
use App\Models\MaterialCertificate;
use App\Models\Order;
use App\Models\User;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * The receipt block the quotes/orders modal reads for one order, as the page gets it.
 *
 * @return array<string, mixed>|null
 */
function receiptStateFor(User $user, Batch $batch, Order $order): ?array
{
    $quotesData = (new QuoteFormatter)->quotesData($user->business, $batch->fresh(), $user);

    foreach ($quotesData['supplierGroupCards'] as $card) {
        foreach ($card['rows'] ?? [] as $row) {
            if ($row['goodsReceipt']['order_id'] === $order->id) {
                return $row['goodsReceipt'];
            }
        }
    }

    return null;
}

/**
 * A placed, undelivered steel merchant order - the state the goods receipt form is drawn in.
 *
 * @return array{0: App\Models\User, 1: Batch, 2: Order}
 */
function orderAwaitingDelivery(): array
{
    $user = createUser(1, createBusiness('biz'), false, true);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);

    [, $order] = quoteAndOrder($user, $batch, quoteSent: true, orderSent: true);

    return [$user, $batch, $order];
}

it('records who booked the delivery in, when, and what they checked', function () {
    /*
     * What this route used to do, in full: $order->is_delivered = true. One boolean, no date, nobody's
     * name, and no record of the load having been looked at. ISO 9001 8.6 wants the verification and the
     * person who authorised the release retained; a checkbox retains neither.
     */
    [$user, , $order] = orderAwaitingDelivery();

    $this->actingAs($user)
        ->post(route('order.mark.delivered', $order), [
            'docket_number' => 'DN-884213',
            'quantity_verified' => true,
            'grade_verified' => true,
        ])
        ->assertRedirect();

    $order->refresh();

    expect($order->is_delivered)->toBeTrue()
        ->and($order->hasReceiptRecord())->toBeTrue()
        ->and($order->received_at)->not->toBeNull()
        ->and($order->received_by_user_id)->toBe($user->id)
        ->and($order->delivery_docket_number)->toBe('DN-884213')
        ->and($order->quantity_verified)->toBeTrue()
        ->and($order->grade_verified)->toBeTrue()
        ->and($order->receipt_nonconformance)->toBeNull()
        ->and($order->receiptAccepted())->toBeTrue();
});

it('treats an unanswered check as unanswered rather than as a pass', function () {
    /*
     * The tri-state. A delivery booked in by somebody who did not look at it is a real and common
     * thing, and it must not read the same as one that passed - which is what a boolean defaulting to
     * false (or to true) would have made it.
     */
    [$user, , $order] = orderAwaitingDelivery();

    $this->actingAs($user)
        ->post(route('order.mark.delivered', $order))
        ->assertRedirect();

    $order->refresh();

    expect($order->is_delivered)->toBeTrue()
        //Booked in - there is a receipt, with a date and a name
        ->and($order->hasReceiptRecord())->toBeTrue()
        ->and($order->received_by_user_id)->toBe($user->id)
        //But nothing was checked, and that is not a pass and not a fail
        ->and($order->quantity_verified)->toBeNull()
        ->and($order->grade_verified)->toBeNull()
        ->and($order->receiptAccepted())->toBeNull();
});

it('records what was wrong with a delivery without refusing it', function () {
    /*
     * The steel is in the yard whatever the form says, and a system that refused to book in a short
     * delivery would simply be lied to. So the fault is recorded, the delivery goes through, and the
     * receipt reads as not accepted.
     */
    [$user, , $order] = orderAwaitingDelivery();

    $this->actingAs($user)
        ->post(route('order.mark.delivered', $order), [
            'quantity_verified' => false,
            'grade_verified' => true,
            'nonconformance' => GoodsReceiptNonconformanceEnums::SHORT_DELIVERY->value,
            'note' => 'Two bars short, Kev is chasing Monday\'s load',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $order->refresh();

    expect($order->is_delivered)->toBeTrue()
        ->and($order->receiptNonconformance())->toBe(GoodsReceiptNonconformanceEnums::SHORT_DELIVERY)
        ->and($order->receipt_note)->toBe('Two bars short, Kev is chasing Monday\'s load')
        ->and($order->receiptAccepted())->toBeFalse();
});

it('would be a disaster if a receipt could both pass every check and name a fault', function () {
    /*
     * Two answers to one question, and whichever a later reader believes, the other was recorded for
     * nothing. The person at the gate meant one of the two.
     */
    [$user, , $order] = orderAwaitingDelivery();

    $this->actingAs($user)
        ->post(route('order.mark.delivered', $order), [
            'quantity_verified' => true,
            'grade_verified' => true,
            'nonconformance' => GoodsReceiptNonconformanceEnums::DAMAGED->value,
        ])
        ->assertSessionHasErrors('nonconformance');

    //Nothing was written: the delivery is still outstanding
    expect($order->fresh()->is_delivered)->toBeFalse()
        ->and($order->fresh()->hasReceiptRecord())->toBeFalse();
});

it('demands a note when the reason is "other"', function () {
    //A reason of "Other" with nothing beside it records that something happened and nothing about what
    [$user, , $order] = orderAwaitingDelivery();

    $this->actingAs($user)
        ->post(route('order.mark.delivered', $order), [
            'quantity_verified' => false,
            'grade_verified' => true,
            'nonconformance' => GoodsReceiptNonconformanceEnums::OTHER->value,
        ])
        ->assertSessionHasErrors('note');

    expect($order->fresh()->is_delivered)->toBeFalse();

    //With one, it goes through
    $this->post(route('order.mark.delivered', $order), [
        'quantity_verified' => false,
        'grade_verified' => true,
        'nonconformance' => GoodsReceiptNonconformanceEnums::OTHER->value,
        'note' => 'Driver left it on the wrong side of the yard',
    ])->assertRedirect();

    expect($order->fresh()->is_delivered)->toBeTrue();
});

it('would be a disaster if a second post rewrote the receipt', function () {
    /*
     * A receipt records what somebody saw at a particular moment. A repeated post, a double click or a
     * replayed form must not overwrite the docket, the checks or the name of whoever booked the steel
     * in - which is also why the screen offers no edit.
     */
    [$user, , $order] = orderAwaitingDelivery();
    $colleague = createUser(2, $user->business, false, true);

    $this->actingAs($user)
        ->post(route('order.mark.delivered', $order), [
            'docket_number' => 'DN-FIRST',
            'quantity_verified' => true,
            'grade_verified' => true,
        ]);

    $firstReceipt = $order->fresh();

    $this->actingAs($colleague)
        ->post(route('order.mark.delivered', $order), [
            'docket_number' => 'DN-SECOND',
            'quantity_verified' => false,
            'grade_verified' => false,
            'nonconformance' => GoodsReceiptNonconformanceEnums::DAMAGED->value,
        ])
        ->assertRedirect();

    $order->refresh();

    expect($order->delivery_docket_number)->toBe('DN-FIRST')
        ->and($order->received_by_user_id)->toBe($user->id)
        ->and($order->received_at->toDateTimeString())->toBe($firstReceipt->received_at->toDateTimeString())
        ->and($order->receipt_nonconformance)->toBeNull();
});

it('refuses to book in an order that was never placed', function () {
    $user = createUser(1, createBusiness('biz'), false, true);
    $batch = Batch::factory()->forUser($user->id)->create();

    [, $order] = quoteAndOrder($user, $batch, orderSent: false);

    $this->actingAs($user)
        ->post(route('order.mark.delivered', $order))
        ->assertForbidden();

    expect($order->fresh()->hasReceiptRecord())->toBeFalse();
});

it('stamps the date an order was placed, and clears it when that is undone', function () {
    /*
     * order_sent was a boolean with no date beside it, so nothing could say when a purchase order left.
     * The two have to agree in both directions - this application has been bitten repeatedly by one
     * column describing a state the other contradicts.
     */
    $user = createUser(1, createBusiness('biz'), false, true);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    $project = createProject($user);
    pieceOnBatch($project, $batch);

    [, $order] = quoteAndOrder($user, $batch, quoteSent: true, orderSent: false);

    expect($order->order_sent_at)->toBeNull();

    $this->actingAs($user);
    $this->post(route('order.sent', $batch), ['order_id' => $order->id])->assertRedirect();

    expect($order->fresh()->order_sent_at)->not->toBeNull();

    $this->post(route('order.undo.sent', $order))->assertRedirect();

    $order->refresh();

    expect($order->order_sent)->toBeFalse()
        ->and($order->order_sent_at)->toBeNull();
});

it('reports a delivery that was marked as arrived with no receipt behind it', function () {
    /*
     * Every order marked delivered before these columns existed is one of these, and nothing is
     * backfilled - updated_at was available and would have been a plausible-looking guess at a date
     * somebody checked the steel, which is the one thing a record like this must never contain.
     *
     * So it is counted and shown. An auditor asking for the goods receipt and being told the column is
     * simply null is the worst moment to find out.
     */
    [$user, $batch, $order] = orderAwaitingDelivery();

    //Exactly what the old route did, and all it did
    $order->update(['is_delivered' => true]);

    $order->refresh();

    expect($order->is_delivered)->toBeTrue()
        ->and($order->hasReceiptRecord())->toBeFalse()
        ->and($order->receiptAccepted())->toBeNull();

    $this->actingAs($user);

    $quotesData = (new QuoteFormatter)->quotesData($user->business, $batch->fresh(), $user);

    expect($quotesData['info']['deliveredWithoutReceiptQty'])->toBe(1);
});

it('hands the page the receipt state for each supplier row', function () {
    /*
     * The fuller setup the quotes/orders modal needs: a real nest, so STEEL_MERCHANT is a group this
     * batch actually requires, and a supplier of that group attached to the business so the group draws
     * rows at all. The same supplier carries the quote, so the formatter's firstOrCreate finds the order
     * already on it rather than minting a second.
     */
    [$business, $user, $batch] = nestedBatch([[2500, 5]]);

    $supplier = supplierForGroup($business);

    [, $order] = quoteAndOrder($user, $batch, supplier: $supplier, quoteSent: true, orderSent: true);

    $this->actingAs($user);

    //Placed and not yet received: the form is drawn
    $receipt = receiptStateFor($user, $batch, $order);
    expect($receipt['canReceive'])->toBeTrue()
        ->and($receipt['received'])->toBeFalse()
        ->and($receipt['deliveredWithoutReceipt'])->toBeFalse();

    $this->post(route('order.mark.delivered', $order), [
        'docket_number' => 'DN-884213',
        'quantity_verified' => true,
        'grade_verified' => true,
    ]);

    //Received: the record is drawn, read-only
    $receipt = receiptStateFor($user, $batch, $order);
    expect($receipt['received'])->toBeTrue()
        ->and($receipt['canReceive'])->toBeFalse()
        ->and($receipt['accepted'])->toBeTrue()
        ->and($receipt['docket_number'])->toBe('DN-884213')
        ->and($receipt['received_by'])->toBe($user->name);
});

it('takes the mill certificates on the delivery screen, and goes on taking them afterwards', function () {
    /*
     * What this screen asks for instead of a heat number per bar: the certificates themselves, however
     * many of them the merchant sent.
     *
     * The second half is the part the old form could not do at all. A receipt is closed once written -
     * it records what somebody saw at a particular moment - but the paperwork behind it is not, because
     * the merchant who emails the mill certs the morning after the truck came is normal. So the panel
     * keeps taking files after the steel is booked in, and they land on the same order.
     */
    [$business, $user, $batch] = nestedBatch([[2500, 5]]);

    $supplier = supplierForGroup($business);

    [, $order] = quoteAndOrder($user, $batch, supplier: $supplier, quoteSent: true, orderSent: true);

    //Faked after the nest, not before it: this is the default disk, and the master materials the nest
    //needs are seeded off the real one
    Storage::fake(MaterialCertificate::DISK);

    $this->actingAs($user);

    //Two at once, while the docket is still in somebody's hand
    $this->post(route('material.certificates.store', $order), [
        'certificates' => [
            UploadedFile::fake()->create('heat-4471880.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->create('heat-4471881.pdf', 10, 'application/pdf'),
        ],
    ])->assertRedirect();

    $receipt = receiptStateFor($user, $batch, $order);

    expect($receipt['canAttach'])->toBeTrue()
        ->and($receipt['certificates'])->toHaveCount(2);

    $this->post(route('order.mark.delivered', $order), [
        'docket_number' => 'DN-884213',
        'quantity_verified' => true,
        'grade_verified' => true,
    ])->assertRedirect();

    //The one that turned up the next morning
    $this->post(route('material.certificates.store', $order), [
        'certificates' => [UploadedFile::fake()->create('late-cert.pdf', 10, 'application/pdf')],
    ])->assertRedirect();

    $receipt = receiptStateFor($user, $batch, $order);

    expect($receipt['received'])->toBeTrue()
        ->and($receipt['canAttach'])->toBeTrue()
        ->and(collect($receipt['certificates'])->pluck('filename')->sort()->values()->all())
        ->toBe(['heat-4471880.pdf', 'heat-4471881.pdf', 'late-cert.pdf']);
});
