<?php

use App\Actions\Piece\AttachPiecesToBatch;
use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\Offcut;
use App\Models\OrderApproval;
use App\Models\Piece;
use App\Models\Quote;
use App\Models\RawMaterialQuote;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Still a stub. There was briefly a server-side copy of this - the fabrication deadline email
 * printed the same tables - and it is gone with that email's material lists: the one generator is
 * Shared/shared.js::sendSupplierBatchEmail again, which builds a mailto out of the open modal and
 * cannot be reached from here.
 */
it("would be a disaster if the one click email material list generator didn't work correctly", function () {});

it('would be a disaster if quote deadline missed', function () {});

it('would be a disaster if duplicate quotes were possible', function () {
    /*
     * The quotes/orders modal provisions a quote per supplier the first time it loads. That
     * was a lookup followed by a create, so two concurrent loads could both miss and both insert.
     * The unique index is what makes firstOrCreate actually safe.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);
    $batch = Batch::factory()->forUser($user->id)->create();

    $attributes = [
        'batch_id' => $batch->id,
        'supplier_category' => 'STEEL_MERCHANT',
    ];

    $first = Quote::firstOrCreate($attributes, ['user_id' => $user->id, 'quote_sent' => false]);

    //The second render reads the first one back rather than minting another
    $second = Quote::firstOrCreate($attributes, ['user_id' => $user->id, 'quote_sent' => false]);

    expect($second->id)->toBe($first->id);
    expect(Quote::count())->toBe(1);

    //And the database refuses a duplicate outright, whoever writes it
    expect(fn () => Quote::create($attributes + ['user_id' => $user->id, 'quote_sent' => false]))
        ->toThrow(UniqueConstraintViolationException::class);
});

/**
 * Start quoting while a concurrent request commits its own batch first.
 *
 * Everything the controller reads happens outside the transaction, so a double click, a second tab
 * or a retried request all reach it holding the same list of unbatched pieces. This hook fires
 * inside the transaction immediately after Batch::create and before the pieces are claimed, which
 * is exactly where the other request's commit would land.
 */
function claimPiecesOnceABatchIsCreated(Batch $rival): void
{
    $claimed = false;

    Batch::created(function (Batch $batch) use ($rival, &$claimed) {
        if ($claimed || $batch->id === $rival->id) {
            return;
        }

        $claimed = true;

        Piece::query()->whereNull('batch_id')->update(['batch_id' => $rival->id]);
    });
}

it('would be a disaster if two presses of start quoting cut the same steel twice', function () {
    /**
     * The write that made it possible. AttachPiecesToBatch updated by id alone, so the second request
     * moved the pieces off the batch the first one had already nested, costed and consumed offcuts
     * against. What that leaves cannot be unwound by anything in the application: the first batch
     * keeps its order approvals, its saved nesting and the offcuts it consumed, while the steel those
     * offcuts were cut for now belongs to the second batch - two batches in Quoting for one set of
     * projects, one of them empty, and the same steel quoted and ordered twice.
     *
     * Asserted on the action rather than through the route, because this is the property that has to
     * hold whoever calls it: a piece that already has a batch is left exactly where it is, and the
     * count says the claim was short.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $mine = pieceReadyForBatching($project);
    $taken = pieceReadyForBatching($project);

    //Already claimed, the way a concurrent "start quoting" would have claimed it
    $rival = Batch::factory()->forUser($user->id)->create();
    $taken->update(['batch_id' => $rival->id]);

    $batch = Batch::factory()->forUser($user->id)->create();
    $claimed = AttachPiecesToBatch::run(collect([$mine, $taken]), $batch);

    expect($claimed)->toBe(1)
        ->and($taken->fresh()->batch_id)->toBe($rival->id)
        ->and($mine->fresh()->batch_id)->toBe($batch->id);
});

it('leaves nothing behind when start quoting loses the race', function () {
    /*
     * And the route's answer to a short claim. A rolled-back batch is not enough on its own - the
     * approvals, the quotes and the offcuts SaveNesting points at a batch are all written inside the
     * same transaction, and an approval or a consumed offcut surviving a batch that does not exist is
     * worse than the duplicate was.
     *
     * The hook claims the pieces inside this transaction, so unlike a real concurrent request its own
     * claim rolls back with everything else - which is why what is asserted here is that nothing was
     * left behind and the presser was told, not where the steel ended up. That is the assertion above.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    pieceReadyForBatching($project);

    $rival = Batch::factory()->forUser($user->id)->create();
    claimPiecesOnceABatchIsCreated($rival);

    $this->actingAs($user)->post(route('quotes.store'))->assertInvalid('batch');

    expect(Batch::pluck('id')->all())->toBe([$rival->id])
        ->and(OrderApproval::count())->toBe(0)
        ->and(Quote::count())->toBe(0)
        ->and(Offcut::count())->toBe(0);
});

it('still starts quoting when nothing is racing it', function () {
    //The guard has to let the ordinary press through, which is the whole point of it
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $piece = pieceReadyForBatching($project);

    $this->actingAs($user)->post(route('quotes.store'))->assertValid();

    $batch = Batch::firstOrFail();

    expect($piece->fresh()->batch_id)->toBe($batch->id)
        ->and(OrderApproval::where('batch_id', $batch->id)->count())->toBe(1);
});

it('would be a disaster if duplicate email notifications were happening', function () {});

it('would be a disaster if not sending quote request to all available suppliers', function () {});

it('would be a disaster if quote follow up email not working', function () {});

it('would be a disaster if a project awaiting clarification were nested with no approval row', function () {
    /*
     * The approvals used to be created from projectsReadyForBatching while the pieces came from
     * piecesReadyForBatching, and the two sets are known to differ: a project with an unconfirmed price
     * book match is excluded from the first while its already-matched pieces are swept into the nest
     * anyway. So the one project manager on the batch who was never asked was also the one with no row
     * recording that their work had been committed - and the confirm dialog names them out loud.
     */
    $business = createBusiness('biz');
    $me = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $mine = createProject($me);
    pieceReadyForBatching($mine);

    //Their project has a matched row with a piece, plus one the price book cannot settle
    $theirs = createProject($colleague);
    pieceReadyForBatching($theirs);

    RawMaterialQuote::create([
        'csv_index' => 1000,
        'description' => 'something ambiguous',
        'product_category' => ProductEnums::PFC->value,
        'material' => MaterialEnums::PLAIN_CARBON_STEEL->value,
        'grade' => GradeEnums::GR300->value,
        'surface' => SurfaceEnums::NONE->value,
        'nominal_units' => MeasurementUnitEnums::MILLIMETERS->value,
        'length_required' => 6000,
        'sub_qty' => 1,
        'project_id' => $theirs->id,
        //Two candidates, which ProductService::getProductMatchOptions calls a PARTIAL match
        'general_product_matches' => serialize([
            'supplierGroup' => 'STEEL_MERCHANT',
            'results' => [
                ['product_category' => ProductEnums::PFC->value, 'nominal_height' => 200, 'grade' => GradeEnums::GR300->value, 'surface' => SurfaceEnums::NONE->value],
                ['product_category' => ProductEnums::PFC->value, 'nominal_height' => 250, 'grade' => GradeEnums::GR300->value, 'surface' => SurfaceEnums::NONE->value],
            ],
        ]),
        'assembly_mark' => '',
    ]);

    //The set the approvals used to be built from leaves their project out
    $business = $business->fresh();
    $piecesReady = (new NestingFormatter)->piecesReadyForBatching($business);
    expect($business->projectsReadyForBatching($piecesReady)->pluck('id')->all())->not->toContain($theirs->id);

    $this->actingAs($me)->post(route('quotes.store'))->assertRedirect();

    $batch = Batch::firstOrFail();

    //Their pieces were nested, so their project is owed an approval row
    expect($batch->pieces()->where('project_id', $theirs->id)->exists())->toBeTrue()
        ->and(OrderApproval::pluck('project_id')->sort()->values()->all())
        ->toBe([$mine->id, $theirs->id]);
});

//todo more
