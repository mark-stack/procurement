<?php

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\OrderApproval;
use App\Models\Quote;
use App\Models\RawMaterialQuote;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it("would be a disaster if the one click email material list generator didn't work correctly", function () {});

it('would be a disaster if quote deadline missed', function () {});

it('would be a disaster if duplicate quotes were possible', function () {
    /*
     * The quotes/orders modal provisions a quote per supplier the first time it loads. That
     * was a lookup followed by a create, so two concurrent loads could both miss and both insert.
     * The unique index is what makes firstOrCreate actually safe.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $batch = Batch::factory()->forUser($user->id)->create();
    $supplier = Supplier::factory()->create();

    $attributes = [
        'batch_id' => $batch->id,
        'supplier_id' => $supplier->id,
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
    $business = createBusiness('biz', true);
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
