<?php

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Formatters\KanbanFormatter;
use App\Models\Batch;
use App\Models\Order;
use App\Models\Quote;
use App\Models\User;
use App\Services\DeliveredBatchAutoDone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * An order on a batch, with the quote that carries its supplier category and the receipt that dates
 * its delivery.
 *
 * Named apart from the other files' helpers - pest loads every test file into the one process, so a
 * second declaration of an existing name is a fatal, not an override.
 */
function doneBatchOrder(
    User $user,
    Batch $batch,
    string $supplierCategory = 'STEEL_MERCHANT',
    bool $orderSent = true,
    bool $isDelivered = true,
    ?string $receivedAt = null,
    ?string $materialCertNumbers = 'CERT-1',
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
        //The receipt OrderMarkDeliveredController writes beside the flag. Null is a delivery booked in
        //before those columns existed - see the add_goods_receipt_to_orders migration
        'received_at' => $receivedAt,
        'material_cert_numbers' => $materialCertNumbers,
    ]);
}

/**
 * A batch in the Delivering column, delivered and booked in on the day given.
 *
 * Every raw material row on its project points at a sent order, which is what puts it in that column
 * rather than in Ordering - see BatchStages.
 *
 * @return array{0: Batch, 1: Order}
 */
function deliveredBatch(User $user, ?string $receivedAt, ?string $materialCertNumbers = 'CERT-1'): array
{
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    $project = createProject($user);
    $piece = pieceOnBatch($project, $batch);

    $order = doneBatchOrder(
        $user,
        $batch,
        receivedAt: $receivedAt,
        materialCertNumbers: $materialCertNumbers,
    );

    $piece->order_id = $order->id;
    $piece->save();

    return [$batch, $order];
}

it('closes a batch whose steel has all been in for a week', function () {
    /*
     * The column it leaves is the last one on the board, and the button it is replacing has nothing to
     * do but admit the job is over - so nobody presses it, and the column silts up with every batch
     * that was ever finished.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    [$batch] = deliveredBatch($user, now()->subDays(7)->toDateTimeString());

    $doneProject = (new DeliveredBatchAutoDone)->sweepBusiness($business);

    expect($doneProject)->toHaveCount(1)
        ->and((bool) $batch->fresh()->done)->toBeTrue();

    //And it is off the board, which is the only thing the user sees
    $this->actingAs($user);
    expect((new KanbanFormatter)->deliveredColumn($business))->toBeEmpty();
});

it('leaves a batch alone while its delivery is still recent', function () {
    /*
     * The wait is the point, not a pause before the real behaviour. The days after a delivery are when
     * the certs get chased, the docket gets queried and the short bundle gets argued about, and all of
     * those happen with the card in front of you.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $receivedAt = now()->subDays(2);
    [$batch] = deliveredBatch($user, $receivedAt->toDateTimeString());

    expect((new DeliveredBatchAutoDone)->sweepBusiness($business))->toBeEmpty()
        ->and((bool) $batch->fresh()->done)->toBeFalse();

    //Counted from the receipt, so the date does not drift forward every time the board is drawn
    expect((new DeliveredBatchAutoDone)->doneDueDate($batch)->toDateString())
        ->toBe($receivedAt->copy()->addDays(DeliveredBatchAutoDone::DAYS_AFTER_DELIVERY)->toDateString());
});

it('counts from the last delivery booked in, not the first', function () {
    /*
     * A batch is quoted and ordered as one thing but it arrives on several trucks. The fasteners
     * landing a fortnight before the steel must not start the clock - the batch was not finished until
     * the last of it was in.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    [$batch] = deliveredBatch($user, now()->subDays(14)->toDateTimeString());

    $lastDelivery = now()->subDay();
    doneBatchOrder(
        $user,
        $batch,
        supplierCategory: 'FASTENERS',
        receivedAt: $lastDelivery->toDateTimeString(),
        materialCertNumbers: null,
    );

    expect((new DeliveredBatchAutoDone)->sweepBusiness($business))->toBeEmpty()
        ->and((new DeliveredBatchAutoDone)->doneDueDate($batch)->toDateString())
        ->toBe($lastDelivery->copy()->addDays(DeliveredBatchAutoDone::DAYS_AFTER_DELIVERY)->toDateString());
});

it('would be a disaster if a batch with a delivery still out closed itself', function () {
    /*
     * A batch sits in this column from the moment the last order is SENT, so most of the cards in it
     * have steel still on the road. Reading "the oldest delivery was ages ago" as "the job is done"
     * would close a live batch - and nothing in the application re-opens one.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    [$batch] = deliveredBatch($user, now()->subDays(30)->toDateTimeString());

    doneBatchOrder(
        $user,
        $batch,
        supplierCategory: 'FASTENERS',
        isDelivered: false,
        materialCertNumbers: null,
    );

    expect((new DeliveredBatchAutoDone)->sweepBusiness($business))->toBeEmpty()
        ->and((new DeliveredBatchAutoDone)->doneDueDate($batch))->toBeNull()
        ->and((bool) $batch->fresh()->done)->toBeFalse();
});

it('would be a disaster if it closed a batch whose steel has no material certs', function () {
    /*
     * The warning on the card is the only thing chasing those certificates, and closing the batch takes
     * it off the board - while the offcuts that steel becomes stay in the rack, and stay untraceable.
     * The board hides "Move to done" behind the same warning, so holding here is the sweep agreeing
     * with the button rather than inventing a rule of its own.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    [$batch] = deliveredBatch($user, now()->subDays(30)->toDateTimeString(), materialCertNumbers: null);

    expect((new DeliveredBatchAutoDone)->sweepBusiness($business))->toBeEmpty()
        ->and((new DeliveredBatchAutoDone)->doneDueDate($batch))->toBeNull();

    //And the card goes on asking for them, which is the whole reason it is still there
    $this->actingAs($user);
    $row = collect((new KanbanFormatter)->deliveredColumn($business))->first()['info'];

    expect($row['steelMerchantDeliveredButNoCertsYet'])->toBeTrue()
        ->and($row['doneDueDate'])->toBeNull();
});

it('never guesses at when a delivery nobody dated arrived', function () {
    /*
     * Deliveries booked in before the goods receipt columns existed have is_delivered and no date. The
     * migration refused to backfill those from updated_at rather than retain a plausible-looking guess
     * at when somebody checked the steel, and this refuses to read it for the same reason - except that
     * here the guess would close a batch, which cannot be undone.
     *
     * The card keeps its button, so these are closed by a person or not at all.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    [$batch] = deliveredBatch($user, null);

    expect((new DeliveredBatchAutoDone)->sweepBusiness($business))->toBeEmpty()
        ->and((new DeliveredBatchAutoDone)->doneDueDate($batch))->toBeNull();

    $this->actingAs($user);
    expect(collect((new KanbanFormatter)->deliveredColumn($business))->first()['info']['allDelivered'])
        ->toBeTrue();

    $this->post(route('mark.as.past.project', $batch))->assertRedirect();

    expect((bool) $batch->fresh()->done)->toBeTrue();
});

it('would be a disaster if it closed a batch carrying material nobody ever ordered', function () {
    /*
     * A material row that never matched a product has no piece, so it can never be ordered and it holds
     * the batch in the Ordering column - BatchStages says so, and the board draws it there. Its sent
     * orders can still all have arrived, and closing on that alone would make a past project out of a
     * job with steel missing from it.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    [$batch] = deliveredBatch($user, now()->subDays(30)->toDateTimeString());

    //A second material row on the same project, with no piece behind it
    $project = $batch->projects()->first();
    createRawMaterialQuote200Pfc($project, MaterialEnums::PLAIN_CARBON_STEEL, GradeEnums::GR300, 6000);

    expect((new DeliveredBatchAutoDone)->sweepBusiness($business))->toBeEmpty()
        ->and((new DeliveredBatchAutoDone)->doneDueDate($batch->fresh()))->toBeNull();
});

it('leaves the board of a lapsed account alone', function () {
    /*
     * A lapsed trial is read-only (BillingWriteAccessMiddleware), and closing a batch is not something
     * they could put back once they have paid - so the board must not empty itself while they cannot
     * touch it.
     */
    $business = lapsedTrialBusiness();
    $user = createUser(1, $business, false, true);

    [$batch] = deliveredBatch($user, now()->subDays(30)->toDateTimeString());

    expect((new DeliveredBatchAutoDone)->sweepBusiness($business))->toBeEmpty()
        ->and((bool) $batch->fresh()->done)->toBeFalse();
});

it('has nothing left to do on a second run', function () {
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    deliveredBatch($user, now()->subDays(7)->toDateTimeString());

    expect((new DeliveredBatchAutoDone)->sweepBusiness($business))->toHaveCount(1)
        ->and((new DeliveredBatchAutoDone)->sweepBusiness($business))->toBeEmpty();
});

it('prints the day it will close on the card, on the date the schedule keeps', function () {
    /*
     * A card that is about to disappear on its own has to say so first, and it has to say the date the
     * sweep is actually working to - which is why both read the one method.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $receivedAt = now()->subDay();
    [$batch] = deliveredBatch($user, $receivedAt->toDateTimeString());

    $this->actingAs($user);
    $row = collect((new KanbanFormatter)->deliveredColumn($business))->first()['info'];

    expect($row['doneDueDate'])
        ->toBe($receivedAt->copy()->addDays(DeliveredBatchAutoDone::DAYS_AFTER_DELIVERY)->toDateString());
});

it('closes the batch when the schedule runs it', function () {
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    [$batch] = deliveredBatch($user, now()->subDays(7)->toDateTimeString());

    $this->artisan('batches:mark-delivered-done')
        ->expectsOutputToContain('Batches moved to past projects: 1')
        ->assertSuccessful();

    expect((bool) $batch->fresh()->done)->toBeTrue();
});

it('waits exactly the five days it says it does', function () {
    /*
     * The boundary, asked both ways round from one fixture: the card is still on the board on the
     * fourth day and gone on the fifth.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    [$batch] = deliveredBatch($user, now()->toDateTimeString());

    Carbon::setTestNow(now()->addDays(DeliveredBatchAutoDone::DAYS_AFTER_DELIVERY - 1));
    expect((new DeliveredBatchAutoDone)->sweepBusiness($business))->toBeEmpty();

    Carbon::setTestNow(now()->addDay());
    expect((new DeliveredBatchAutoDone)->sweepBusiness($business))->toHaveCount(1)
        ->and((bool) $batch->fresh()->done)->toBeTrue();

    Carbon::setTestNow();
});

/**
 * A batch bought over the phone: nested, no order anywhere, and marked delivered by hand on the day
 * given. The Nesting card's "All delivered" writes exactly this - see BatchMarkDeliveredController.
 */
function handDeliveredBatch(User $user, ?string $deliveredAt, ?string $cutAt = null): Batch
{
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    $project = createProject($user);
    pieceOnBatch($project, $batch);

    $batch->update([
        'delivered_at' => $deliveredAt,
        'cut_at' => $cutAt,
    ]);

    return $batch->fresh();
}

it('closes a batch bought over the phone, five days after somebody said the steel was in', function () {
    /*
     * The shop that rings the merchant has no order to book in, so its finished batches never reach
     * the Delivering column at all - they sit in Quoting with "Delivered" on the card. This sweep
     * only ever looked at that column, so those batches were closed by nothing: the Nesting page grew
     * by a card a job and Past Projects could gain no rows.
     *
     * Counted from the mark, which is the same event a goods receipt records - the day somebody said
     * the steel arrived - just written by a person rather than by the orders screen.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = handDeliveredBatch($user, now()->subDays(DeliveredBatchAutoDone::DAYS_AFTER_DELIVERY)->toDateTimeString());

    $doneProject = (new DeliveredBatchAutoDone)->sweepBusiness($business);

    expect($doneProject)->toHaveCount(1)
        ->and((bool) $batch->fresh()->done)->toBeTrue();
});

it('leaves a batch delivered by hand alone until the same five days are up', function () {
    //The wait is the wait, whichever way the delivery was recorded
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = handDeliveredBatch($user, now()->subDays(DeliveredBatchAutoDone::DAYS_AFTER_DELIVERY - 1)->toDateTimeString());

    expect((new DeliveredBatchAutoDone)->sweepBusiness($business))->toBeEmpty()
        ->and((bool) $batch->fresh()->done)->toBeFalse();
});

it('counts a batch cut after its delivery from the cut, which is the later of the two', function () {
    /*
     * The saw going through it is the last thing that happens to a job, and a batch is plainly still
     * in use until it has. Delivered a fortnight ago and cut this morning stays on the page.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = handDeliveredBatch(
        $user,
        now()->subDays(14)->toDateTimeString(),
        now()->toDateTimeString(),
    );

    expect((new DeliveredBatchAutoDone)->sweepBusiness($business))->toBeEmpty();

    Carbon::setTestNow(now()->addDays(DeliveredBatchAutoDone::DAYS_AFTER_DELIVERY));

    expect((new DeliveredBatchAutoDone)->sweepBusiness($business))->toHaveCount(1)
        ->and((bool) $batch->fresh()->done)->toBeTrue();

    Carbon::setTestNow();
});

it('would be a disaster if an ordinary batch nobody has delivered closed itself', function () {
    /*
     * The Quoting column is where the work in progress lives - every batch on the Nesting page is in
     * it now - so reading that column at all means being sure about the one thing that separates a
     * finished batch from an unfinished one. No mark, no closing, however old the batch is.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $batch = handDeliveredBatch($user, null);
    $batch->update(['created_at' => now()->subMonths(6)]);

    expect((new DeliveredBatchAutoDone)->sweepBusiness($business))->toBeEmpty()
        ->and((bool) $batch->fresh()->done)->toBeFalse();
});
