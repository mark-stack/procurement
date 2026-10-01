<?php

use App\Actions\Bar\AttachBarsToOrder;
use App\Formatters\NestingFormatter;
use App\Models\Bar;
use App\Models\Batch;
use App\Models\Cut;
use App\Models\Offcut;
use App\Models\Order;
use App\Models\Piece;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records one cut per part, against the bar it came off', function () {
    /*
     * Case 1 of nestingTestCases: five 2,500s and two 1,500s into two 9,000mm bars. Seven parts, so
     * seven cuts - and the point of the table is that each one names a bar, because "which bar" is the
     * only route to "which heat" and therefore to "which certificate".
     *
     * This is also the case a bar_id column on pieces could never have carried. The BOM is two piece
     * rows (one of qty 5, one of qty 2) and the five 2,500s do not all land on the same bar.
     */
    [, , $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    $cuts = Cut::query()->where('batch_id', $batch->id)->get();

    expect($cuts)->toHaveCount(7);

    //Every cut names a part and the steel it was taken from, and never both sources
    foreach ($cuts as $cut) {
        expect($cut->piece_id)->not->toBeNull()
            ->and($cut->isFromNewStock())->toBeTrue()
            ->and($cut->offcut_id)->toBeNull();
    }

    //The lengths are the cut plan, not a guess
    expect($cuts->pluck('length')->sort()->values()->all())
        ->toBe([1500.0, 1500.0, 2500.0, 2500.0, 2500.0, 2500.0, 2500.0]);

    //Spread over the two bars the nest bought, not all hung off one
    expect($cuts->pluck('bar_id')->unique())->toHaveCount(2);

    //And every cut belongs to a piece this batch actually carries
    $pieceIds = Piece::query()->where('batch_id', $batch->id)->pluck('id');
    expect($cuts->pluck('piece_id')->unique()->diff($pieceIds))->toBeEmpty();
});

it('would be a disaster if one piece row of several parts collapsed to one cut', function () {
    /*
     * The failure mode the cuts table exists to prevent. pieces.actual_qty of 5 is five physical parts;
     * recording the piece against a single bar would answer "which heat" with one bar's number for all
     * five, four of which may have come off different steel entirely.
     */
    [, , $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    $fiveOff = Piece::query()
        ->where('batch_id', $batch->id)
        ->where('actual_qty', 5)
        ->sole();

    expect($fiveOff->cuts)->toHaveCount(5);
});

it('keeps identical bars consolidated even though every cut now names its piece', function () {
    /*
     * Threading piece ids through the nest is what made the cuts table possible, and it very nearly
     * broke every nesting screen on the way: no two cuts share a piece id, so leaving the ids in the
     * shape that decides grouping would make every bar unique. "3 x 9000mm cut 2500|2500|2500|1500"
     * would have become three rows.
     *
     * So "result" has to come back exactly as it always did, with the ids travelling beside it.
     */
    $nestingFormatter = new NestingFormatter;

    $identicalBars = [
        [
            'bar_length' => 9000,
            'unused' => 1500,
            'kerf' => 0,
            'scrap_threshold_mm' => 1000,
            'pieces' => [
                ['cutLength' => 2500, 'projectId' => 1, 'letter' => 'A', 'pieceId' => 11],
                ['cutLength' => 2500, 'projectId' => 1, 'letter' => 'A', 'pieceId' => 12],
            ],
            'offcut_id' => null,
        ],
        [
            'bar_length' => 9000,
            'unused' => 1500,
            'kerf' => 0,
            'scrap_threshold_mm' => 1000,
            //Same cut plan, different parts
            'pieces' => [
                ['cutLength' => 2500, 'projectId' => 1, 'letter' => 'A', 'pieceId' => 13],
                ['cutLength' => 2500, 'projectId' => 1, 'letter' => 'A', 'pieceId' => 14],
            ],
            'offcut_id' => null,
        ],
    ];

    $consolidated = $nestingFormatter->consolidateStockNestingResults($identicalBars);

    //One row, counted twice - which is what the screens draw
    expect($consolidated)->toHaveCount(1)
        ->and($consolidated[0]['count'])->toBe(2);

    //The display shape carries no piece ids at all
    foreach ($consolidated[0]['result']['pieces'] as $cut) {
        expect($cut)->not->toHaveKey('pieceId');
    }

    //They are here instead, one set per identical bar, in the order the bars were packed
    expect($consolidated[0]['pieceIdSets'])->toBe([[11, 12], [13, 14]]);
});

it('attaches the batch bars to the order that buys them, and lets go when it is undone', function () {
    /*
     * The join between a cut and a certificate. Certificates hang off the order, the heat number is
     * written on the bar, and the cuts hang off the bar - so without this column the chain has a hole
     * exactly where somebody asks about it.
     */
    [, $user, $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    [, $order] = quoteAndOrder($user, $batch, quoteSent: true, orderSent: false);

    expect(Bar::query()->where('batch_id', $batch->id)->whereNotNull('order_id')->count())->toBe(0);

    $this->actingAs($user);
    $this->post(route('order.sent', $batch), ['order_id' => $order->id])->assertRedirect();

    $barCount = Bar::query()->where('batch_id', $batch->id)->count();
    expect($barCount)->toBeGreaterThan(0)
        ->and(Bar::query()->where('order_id', $order->id)->count())->toBe($barCount);

    //Withdrawing the order means it never bought them
    $this->post(route('order.undo.sent', $order))->assertRedirect();

    expect(Bar::query()->where('order_id', $order->id)->count())->toBe(0);
});

it('would be a disaster if an order took bars belonging to another supplier group', function () {
    /*
     * A batch that needs steel and bolts places two orders, and neither of them bought the other's
     * bars. The PFC bars here belong to STEEL_MERCHANT, so a FASTENER order must come away empty.
     */
    [, $user, $batch] = nestedBatch([[2500, 5]]);

    [, $fastenerOrder] = quoteAndOrder($user, $batch, supplierCategory: 'FASTENER_SUPPLIER');

    AttachBarsToOrder::run($batch, $fastenerOrder);

    expect(Bar::query()->where('order_id', $fastenerOrder->id)->count())->toBe(0);
});

it('names the heat a part was cut from once the delivery is booked in', function () {
    /*
     * The whole chain, end to end: piece -> cut -> bar -> heat number. This is the question
     * AS/NZS 5131 and EN 1090 ask, and the one this application could not answer - its best was the
     * list of certificates the steel "could have come from".
     */
    [, $user, $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    [, $order] = quoteAndOrder($user, $batch, quoteSent: true, orderSent: false);

    $this->actingAs($user);
    $this->post(route('order.sent', $batch), ['order_id' => $order->id]);

    $bars = Bar::query()->where('order_id', $order->id)->orderBy('id')->get();

    //Heat numbers off the docket, one per bar
    $heatNumbers = [];
    foreach ($bars as $index => $bar) {
        $heatNumbers[$bar->id] = 'HEAT-'.(4471880 + $index);
    }

    $this->post(route('order.mark.delivered', $order), [
        'quantity_verified' => true,
        'grade_verified' => true,
        'heat_numbers' => $heatNumbers,
    ])->assertRedirect();

    //Every cut can now name its own heat, and they are not all the same one
    $cuts = Cut::query()->where('batch_id', $batch->id)->with('bar')->get();

    foreach ($cuts as $cut) {
        expect($cut->heatNumber())->toBe($heatNumbers[$cut->bar_id]);
    }

    expect($cuts->map(fn (Cut $cut) => $cut->heatNumber())->unique())->toHaveCount($bars->count());
});

it('would be a disaster if a heat number could be written onto another order\'s bar', function () {
    /*
     * The ids arrive in a request body. A bar that is not this order's is somebody else's steel, and
     * writing a heat number onto it would put this delivery's paperwork against material that came off
     * a different truck - at best a colleague's, at worst another business's.
     */
    [, $user, $batch] = nestedBatch([[2500, 5]]);

    [, $order] = quoteAndOrder($user, $batch, quoteSent: true, orderSent: false);

    $this->actingAs($user);
    $this->post(route('order.sent', $batch), ['order_id' => $order->id]);

    //A bar that belongs to nothing this order bought
    $strangersBar = Bar::factory()->create(['heat_number' => null]);

    $this->post(route('order.mark.delivered', $order), [
        'heat_numbers' => [$strangersBar->id => 'HEAT-NOT-MINE'],
    ])->assertRedirect();

    expect($strangersBar->fresh()->heat_number)->toBeNull();
});

it('traces a cut taken off the rack back to the bar its steel was rolled as', function () {
    /*
     * A cut off an offcut has no bar of its own, and an offcut of an offcut of an offcut was never
     * bought by anybody - the purchase is several generations back up the chain. Reading one generation
     * is the bug Batch::resolveOffcutOrdersWithCertificates documents: it reported fully traceable
     * steel as untraceable from the third generation on.
     */
    $user = createUser(1, createBusiness('biz', true), false, true);

    $rootBatch = Batch::factory()->forUser($user->id)->create();

    //The bar that was actually bought, carrying the heat off its certificate
    $bar = Bar::factory()->forBatch($rootBatch->id)->withHeatNumber('HEAT-4471882')->create();

    //Three generations of offcut, the first one cut from that bar
    $chain = offcutGenerations($user, $rootBatch, 3);
    $chain[0]->bar_id = $bar->id;
    $chain[0]->save();

    $thirdGeneration = $chain[2];
    expect($thirdGeneration->generation())->toBe(3)
        ->and($thirdGeneration->bar_id)->toBeNull();

    $cuttingBatch = Batch::factory()->forUser($user->id)->create();
    $piece = pieceOnBatch(createProject($user), $cuttingBatch);

    $cut = Cut::create([
        'piece_id' => $piece->id,
        'batch_id' => $cuttingBatch->id,
        'offcut_id' => $thirdGeneration->id,
        'length' => 1500,
    ]);

    expect($cut->isFromNewStock())->toBeFalse()
        ->and($cut->originBar()?->id)->toBe($bar->id)
        ->and($cut->heatNumber())->toBe('HEAT-4471882');
});

it('would be a disaster if a cut could be saved naming neither a bar nor an offcut', function () {
    /*
     * A traceability row that traces to nothing is worse than no row, because it counts. $guarded is
     * empty on every model here, so the guard has to be in the model rather than in the one caller that
     * happens to write them today.
     */
    $user = createUser(1, createBusiness('biz', true), false, true);
    $batch = Batch::factory()->forUser($user->id)->create();
    $piece = pieceOnBatch(createProject($user), $batch);

    $orphan = fn () => Cut::create([
        'piece_id' => $piece->id,
        'batch_id' => $batch->id,
        'length' => 1500,
    ]);

    expect($orphan)->toThrow(InvalidArgumentException::class);

    //And naming both is just as wrong
    $bar = Bar::factory()->forBatch($batch->id)->create();
    $offcut = Offcut::factory()->withBatchFrom($batch->id)->withLength(2000)->create();

    $both = fn () => Cut::create([
        'piece_id' => $piece->id,
        'batch_id' => $batch->id,
        'bar_id' => $bar->id,
        'offcut_id' => $offcut->id,
        'length' => 1500,
    ]);

    expect($both)->toThrow(InvalidArgumentException::class);
});

it('deletes the cuts with the nest that planned them', function () {
    /*
     * Unwinding a batch means the cuts were never made - the same reasoning BatchController already
     * applies to the bars and offcuts it deletes. Done by the foreign keys rather than by a fourth
     * thing to remember in that method.
     */
    [, $user, $batch] = nestedBatch([[2500, 5], [1500, 2]]);

    expect(Cut::query()->where('batch_id', $batch->id)->count())->toBe(7);

    $this->actingAs($user);
    $this->delete(route('batches.destroy', $batch))->assertRedirect();

    expect(Batch::query()->whereKey($batch->id)->exists())->toBeFalse()
        ->and(Cut::query()->where('batch_id', $batch->id)->count())->toBe(0);
});
