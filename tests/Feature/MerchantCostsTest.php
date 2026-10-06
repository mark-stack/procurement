<?php

use App\Enums\NestingEnums;
use App\Models\Business;
use App\Models\Scrap;
use App\Services\NestingCostModel;
use App\Services\NestingSettings;
use App\Services\ScrapLedger;
use App\Services\SupplierGroupCosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Not everything a fabricator buys is steel.
 *
 * The cost model held one price per tonne for the whole yard, which is right until the catalogue
 * holds something that is not steel - and it has held LVL all along. Timber, bought from a timber
 * merchant, nested by the metre exactly like a section, and costed at the steel rate with a
 * scrap-merchant rebate on every drop that nobody ever paid.
 *
 * Two errors were cancelling, which is why it went unnoticed: the LVL rows carry no kg/m, so they
 * fell back to 10.0 against a true timber mass nearer 3.4, while being priced at $2,000/t against a
 * timber price nearer $4,000/t. Correcting either one alone makes the answer worse. These tests are
 * about being able to correct both.
 */
function yardBuying(array $overrides = []): Business
{
    $business = createBusiness('fabricator');

    $business->update([
        'material_cost_per_tonne' => 2000.0,
        'delivery_cost_per_tonne' => 0.0,
        'scrap_recovery_rate' => 0.13,
        'default_kg_per_m' => 10.0,
        'cost_overrides' => $overrides === [] ? null : $overrides,
    ]);

    return $business->fresh();
}

/**
 * A complete settings body, with the given keys changed.
 *
 * Complete because the form posts every dial at once and the request requires every one of them -
 * a partial payload is a half-written cost model. Restated here rather than shared with
 * AdminNestingAlgorithmTest for the reason that file's own copy explains: the exact shape of a
 * whole body is the thing being asserted, so a helper growing a key in one file would quietly
 * change what the other was testing.
 *
 * @return array<string, mixed>
 */
function nestingSettingsBody(Business $business, array $changes = []): array
{
    return [...NestingSettings::inForce($business), ...$changes];
}

/** The timber merchant as a yard would actually describe it: dearer per tonne, and the bin pays nothing. */
function timberMerchant(): array
{
    return [
        'TIMBER_MERCHANT' => [
            'material_cost_per_tonne' => 4000.0,
            'scrap_recovery_rate' => 0.0,
            'default_kg_per_m' => 3.4,
        ],
    ];
}

it('knows which merchant a product category is bought from', function () {
    /*
     * The map the whole feature turns on. LVL_Implementation has declared TIMBER_MERCHANT since it
     * was written - the application has always known this and the cost model had no way to ask.
     */
    expect(SupplierGroupCosts::forCategory('LVL'))->toBe('TIMBER_MERCHANT')
        ->and(SupplierGroupCosts::forCategory('PFC'))->toBe('STEEL_MERCHANT')
        ->and(SupplierGroupCosts::forCategory('HEX_BOLT'))->toBe('FASTENERS')
        //Case-insensitive, because a piece spec carries whatever the importer classified it as
        ->and(SupplierGroupCosts::forCategory('lvl'))->toBe('TIMBER_MERCHANT')
        //A category with no implementation is costed on the yard's figures rather than a guess
        ->and(SupplierGroupCosts::forCategory('SHS'))->toBeNull()
        ->and(SupplierGroupCosts::forCategory(null))->toBeNull();
});

it('prices a metre of timber at the timber merchant rate, not the steel rate', function () {
    $business = yardBuying(timberMerchant());

    $steel = new NestingCostModel($business, 25.1, 9000, 'STEEL_MERCHANT');
    $timber = new NestingCostModel($business, 3.4, 6000, 'TIMBER_MERCHANT');

    //25.1 kg/m at $2,000/t, against 3.4 kg/m at $4,000/t
    expect(round($steel->mmToCost(1000), 2))->toBe(50.2)
        ->and(round($timber->mmToCost(1000), 2))->toBe(13.6);
});

it('falls back to a timber mass for timber, where the catalogue has none', function () {
    $business = yardBuying(timberMerchant());

    //No kg/m at all, which is the state every one of the catalogue's 14 LVL rows is in
    $timber = new NestingCostModel($business, null, 6000, 'TIMBER_MERCHANT');
    $steel = new NestingCostModel($business, null, 9000, 'STEEL_MERCHANT');

    /*
     * This is the half that was doing the most damage. At the yard-wide 10.0 an LVL bearer was
     * costed as though it weighed three times what it does - and because the price per tonne was
     * also wrong, the two mistakes landed near a plausible number.
     */
    expect($timber->mmToKg(1000))->toBe(3.4)
        ->and($steel->mmToKg(1000))->toBe(10.0);
});

it('lets a merchant say the bin pays nothing, which a yard-wide rate could not', function () {
    $business = yardBuying(timberMerchant());

    $timber = new NestingCostModel($business, 3.4, 6000, 'TIMBER_MERCHANT');
    $steel = new NestingCostModel($business, 25.1, 9000, 'STEEL_MERCHANT');

    /*
     * A ZERO IS AN ANSWER, not an absent setting. A weighbridge buys metal; a timber merchant does
     * not buy LVL offcuts back, and a skip of timber costs tip fees. Nought has to beat the yard's
     * 0.13 rather than read as "nothing was set here" - which is the one thing a naive
     * "override if truthy" would have got wrong.
     */
    expect($timber->scrapIncome(1000))->toBe(0.0)
        ->and($steel->scrapIncome(1000))->toBeGreaterThan(0.0);
});

it('leaves every other merchant exactly where it was', function () {
    $plain = new NestingCostModel(yardBuying(), 25.1, 9000, 'STEEL_MERCHANT');
    $alongsideTimber = new NestingCostModel(yardBuying(timberMerchant()), 25.1, 9000, 'STEEL_MERCHANT');
    $noGroupAtAll = new NestingCostModel(yardBuying(timberMerchant()), 25.1, 9000);

    /*
     * Setting a timber rate must not move a single steel figure, and a nest costed without a
     * supplier group at all - which is every nest before this existed - must answer as it always
     * did. Three readings of one number.
     */
    expect($alongsideTimber->mmToCost(1000))->toBe($plain->mmToCost(1000))
        ->and($noGroupAtAll->mmToCost(1000))->toBe($plain->mmToCost(1000))
        ->and($alongsideTimber->scrapIncome(1000))->toBe($plain->scrapIncome(1000));
});

it('leaves the yard coefficients alone even for a merchant that carries its own prices', function () {
    $business = yardBuying(timberMerchant());

    $timber = new NestingCostModel($business, 3.4, 6000, 'TIMBER_MERCHANT');
    $steel = new NestingCostModel($business, 3.4, 6000, 'STEEL_MERCHANT');

    /*
     * One crew, one wage. A bundle of LVL is carried by the same people who carry a beam, so
     * handling the same mass has to cost the same whoever sold it - otherwise the model is
     * expressing a preference for timber rather than a fact about it.
     */
    expect($timber->minutesToCost(60))->toBe($steel->minutesToCost(60))
        ->and($timber->cutMinutes())->toBe($steel->cutMinutes());
});

it('drops an override that names something it cannot honour, and keeps a nought', function () {
    $normalised = SupplierGroupCosts::normalise([
        'TIMBER_MERCHANT' => [
            'material_cost_per_tonne' => 4000,
            //A yard coefficient, not a merchant's - see NestingCostModel::MERCHANT_COEFFICIENTS
            'labour_rate_per_hour' => 90,
            //How the form says "use the yard figure"
            'delivery_cost_per_tonne' => '',
            //A real answer, and the one a naive truthiness check would throw away
            'scrap_recovery_rate' => 0,
        ],
        'A_MERCHANT_THAT_NO_LONGER_EXISTS' => ['material_cost_per_tonne' => 1],
    ]);

    /*
     * Dropped rather than refused, on the reading side only. A retained snapshot is written once
     * and read back months later, by which time it may mention a group or a coefficient that has
     * been removed - and a nest that throws is not an option there. The FORM refuses the same input
     * rather than silently discarding what somebody just typed.
     */
    expect($normalised)->toBe([
        'TIMBER_MERCHANT' => [
            'material_cost_per_tonne' => 4000.0,
            'scrap_recovery_rate' => 0.0,
        ],
    ]);
});

it('retains the merchant rates a nest was run on', function () {
    $business = yardBuying(timberMerchant());

    $snapshot = NestingSettings::inForce($business);

    expect($snapshot[NestingSettings::OVERRIDES_KEY]['TIMBER_MERCHANT']['material_cost_per_tonne'])->toBe(4000.0);

    //The yard renegotiates with its timber merchant after that nest was saved
    $business->update(['cost_overrides' => [
        'TIMBER_MERCHANT' => ['material_cost_per_tonne' => 5500.0],
    ]]);

    $batch = new App\Models\Batch(['nesting_settings' => $snapshot]);
    $asNested = NestingSettings::asOf($batch, $business->fresh());

    /*
     * The whole reason the snapshot exists, reaching the merchant layer too. A yard that puts its
     * timber price up this month did not make last month's LVL nests more expensive to have run.
     */
    expect((new NestingCostModel($asNested, 3.4, 6000, 'TIMBER_MERCHANT'))->mmToCost(1000))
        ->toBe(round(3.4 * 4000 / 1000, 10));
});

it('retains the platform figures too, for a yard that has never said anything', function () {
    $snapshot = NestingSettings::inForce(yardBuying());

    /*
     * The EFFECTIVE set, not the business's own overrides - and this is the case that says why. A
     * snapshot recording only what a business had typed would record nothing here, then silently
     * read tomorrow's platform defaults back: a batch nested in March would cost differently in
     * May because the platform changed its mind about what timber is worth.
     */
    expect($snapshot[NestingSettings::OVERRIDES_KEY]['TIMBER_MERCHANT'])
        ->toBe(SupplierGroupCosts::PLATFORM_DEFAULTS['TIMBER_MERCHANT']);
});

it('says nothing about a merchant nobody has an opinion on', function () {
    $snapshot = NestingSettings::inForce(yardBuying());

    /*
     * Absent, not an empty object. The platform has no figure for purlins - purlin steel really is
     * a different price per tonne from structural sections, and an invented one would be
     * indistinguishable from a real one once it was sitting in the defaults.
     */
    expect($snapshot[NestingSettings::OVERRIDES_KEY])->not->toHaveKey('PURLINS')
        ->and($snapshot[NestingSettings::OVERRIDES_KEY])->not->toHaveKey('STEEL_MERCHANT');
});

it('costs timber as timber before anybody has filled in a form', function () {
    //A yard that has said nothing at all. This is every business in the system
    $business = yardBuying();

    $timber = new NestingCostModel($business, null, 6000, 'TIMBER_MERCHANT');

    /*
     * The reason the platform carries figures at all. Left empty, every business - including one
     * created tomorrow - prices timber as steel until somebody notices and fills in a form, and
     * "somebody notices" is exactly what did not happen for the whole life of the LVL rows. A
     * platform default is wrong by a margin; no default was wrong by a factor.
     */
    expect($timber->mmToKg(1000))->toBe(3.5)
        ->and($timber->scrapIncome(1000))->toBe(0.0)
        ->and(round($timber->mmToCost(1000), 2))->toBe(14.35);
});

it('lets a yard correct one platform figure without restating the two it agrees with', function () {
    //This yard's timber merchant is dearer than the platform assumes, and that is all it says
    $business = yardBuying(['TIMBER_MERCHANT' => ['material_cost_per_tonne' => 4600.0]]);

    $timber = new NestingCostModel($business, 3.4, 6000, 'TIMBER_MERCHANT');

    /*
     * Merged per coefficient, not per merchant. Overriding a whole merchant at once would mean
     * filling in two boxes you agree with in order to change the third.
     */
    expect(round($timber->mmToCost(1000), 2))->toBe(15.64)
        //Still the platform's, because this yard said nothing about either
        ->and($timber->scrapIncome(1000))->toBe(0.0)
        ->and((new NestingCostModel($business, null, 6000, 'TIMBER_MERCHANT'))->mmToKg(1000))->toBe(3.5);
});

it('values a timber drop as timber when the scrap ledger reads the nest back', function () {
    [, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $asSteel = Scrap::query()->where('batch_id', $batch->id)->orderBy('id')->get();

    expect($asSteel)->toHaveCount(3)
        ->and($asSteel->first()->recovered_value)->toBeGreaterThan(0);

    /*
     * The same nest, the same drops, bought from a timber merchant instead. Rewritten on the batch
     * rather than built from an LVL bill of materials, so the ONLY thing that differs between the
     * two readings is the merchant - which is what makes the comparison below mean anything.
     */
    $state = $batch->nested_state;

    foreach ($state[NestingEnums::METERAGE->value] as $product) {
        $product->product_category = 'LVL';
        $product->kg_per_m = 3.4;
    }

    $batch->nested_state = $state;
    $batch->nesting_settings = [...$batch->nesting_settings, 'cost_overrides' => timberMerchant()];
    $batch->save();

    Scrap::query()->where('batch_id', $batch->id)->delete();
    (new ScrapLedger)->recordNest($batch->fresh());

    $asTimber = Scrap::query()->where('batch_id', $batch->id)->orderBy('id')->get();

    expect($asTimber)->toHaveCount(3);

    foreach ($asTimber as $row) {
        /*
         * The live bug, in one assertion. Ten real LVL drops were sitting in this table valued at
         * the steel price with 13% credited back from a weighbridge that never saw them.
         */
        expect($row->recovered_value)->toBe(0.0)
            ->and($row->kg_per_m)->toBe(3.4)
            ->and($row->netLoss())->toBe($row->value);
    }
});

it('re-prices scrap that was valued through the wrong material, and writes nothing without being told to', function () {
    [, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    /*
     * The live bug, reconstructed: drops recorded as timber but valued as steel, because at the
     * time the cost model had one price per tonne and no idea what it was pricing.
     */
    $state = $batch->nested_state;

    foreach ($state[NestingEnums::METERAGE->value] as $product) {
        $product->product_category = 'LVL';
    }

    $batch->nested_state = $state;
    $batch->save();

    Scrap::query()->where('batch_id', $batch->id)->update([
        'product_category' => 'LVL',
        'material' => 'TIMBER',
    ]);

    $before = Scrap::query()->where('batch_id', $batch->id)->orderBy('id')->first();

    expect($before->recovered_value)->toBeGreaterThan(0);

    //A dry run by default, because this restates history and nobody should do that by accident
    test()->artisan('scrap:revalue')->assertSuccessful();

    expect($before->fresh()->recovered_value)->toBe($before->recovered_value);

    test()->artisan('scrap:revalue --apply')->assertSuccessful();

    $after = $before->fresh();

    /*
     * The bin pays nothing for timber, and the mass is a timber mass. The length and the bar it
     * came off are untouched - that the drop happened is a fact, and only the money derived from
     * it was wrong.
     */
    expect($after->recovered_value)->toBe(0.0)
        ->and($after->kg_per_m)->toBe(3.5)
        ->and($after->length)->toBe($before->length)
        ->and($after->bar_id)->toBe($before->bar_id)
        ->and($after->id)->toBe($before->id);
});

it('leaves a scrap row alone when its valuation was already right', function () {
    [, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $before = Scrap::query()->where('batch_id', $batch->id)->orderBy('id')->get();

    test()->artisan('scrap:revalue --apply')->assertSuccessful();

    /*
     * Steel costed as steel. The guard that matters: this command matches a row back to the
     * catalogue on the category's MANDATORY spec columns, not on the full product key - a scrap row
     * describes a remnant and carries no nominal_length, so matching on one finds nothing and every
     * row would be "corrected" onto the yard default. The first dry run of this command did exactly
     * that to a 17.70 kg/m PFC.
     */
    foreach ($before as $row) {
        $fresh = $row->fresh();

        expect($fresh->kg_per_m)->toBe($row->kg_per_m)
            ->and($fresh->value)->toBe($row->value)
            ->and($fresh->recovered_value)->toBe($row->recovered_value);
    }
});

it('saves what a merchant charges from the nesting settings form', function () {
    $business = yardBuying();
    $admin = createUser(1, createBusiness('admin'), true, true);

    test()->actingAs($admin)
        ->patch(route('admin.nesting.settings.update', $business), nestingSettingsBody($business, [
            'cost_overrides' => [
                'TIMBER_MERCHANT' => [
                    'material_cost_per_tonne' => 4000,
                    'scrap_recovery_rate' => 0,
                    //Left blank, which is how a merchant says "whatever the yard charges"
                    'delivery_cost_per_tonne' => '',
                    'delivery_cost_per_order' => '',
                    'default_kg_per_m' => 3.4,
                ],
                //Every box empty, so this merchant should not be stored at all
                'STEEL_MERCHANT' => [
                    'material_cost_per_tonne' => '',
                    'scrap_recovery_rate' => '',
                    'delivery_cost_per_tonne' => '',
                    'delivery_cost_per_order' => '',
                    'default_kg_per_m' => '',
                ],
            ],
        ]))
        ->assertRedirect();

    /*
     * toEqual, not toBe. A round figure comes back out of JSON as an int - json_encode writes
     * 4000.0 as "4000" - which costs nothing because every reader of these casts, and which
     * NestingSettings documents for the same reason. An identity assertion here would be testing
     * json_encode.
     */
    expect($business->fresh()->cost_overrides)->toEqual([
        'TIMBER_MERCHANT' => [
            'material_cost_per_tonne' => 4000.0,
            'scrap_recovery_rate' => 0.0,
            'default_kg_per_m' => 3.4,
        ],
    ]);
});

it('clears a merchant rate when its boxes are emptied', function () {
    $business = yardBuying(timberMerchant());
    $admin = createUser(1, createBusiness('admin'), true, true);

    test()->actingAs($admin)->patch(route('admin.nesting.settings.update', $business), nestingSettingsBody($business, [
        'cost_overrides' => ['TIMBER_MERCHANT' => array_fill_keys(NestingCostModel::MERCHANT_COEFFICIENTS, '')],
    ]));

    /*
     * Null rather than an empty object. Nothing downstream tells the two apart, but the column then
     * reads the same as it does for a business that has never opened the section.
     */
    expect($business->fresh()->cost_overrides)->toBeNull();
});

it('refuses to let a merchant decide what the yard pays its own people', function () {
    $business = yardBuying();
    $admin = createUser(1, createBusiness('admin'), true, true);

    test()->actingAs($admin)
        ->patch(route('admin.nesting.settings.update', $business), nestingSettingsBody($business, [
            'cost_overrides' => ['TIMBER_MERCHANT' => ['labour_rate_per_hour' => 90]],
        ]))
        /*
         * Refused rather than dropped. A figure silently discarded on the way in is the worst
         * outcome available: the form comes back looking saved and the nest does not change.
         */
        ->assertSessionHasErrors('cost_overrides.TIMBER_MERCHANT');

    expect($business->fresh()->cost_overrides)->toBeNull();
});

it('refuses an override against a merchant the application does not buy from', function () {
    $business = yardBuying();
    $admin = createUser(1, createBusiness('admin'), true, true);

    test()->actingAs($admin)
        ->patch(route('admin.nesting.settings.update', $business), nestingSettingsBody($business, [
            'cost_overrides' => ['THE_SHED_OUT_THE_BACK' => ['material_cost_per_tonne' => 10]],
        ]))
        ->assertSessionHasErrors('cost_overrides');
});

it('still refuses a merchant price that is certainly a typo', function () {
    $business = yardBuying();
    $admin = createUser(1, createBusiness('admin'), true, true);

    //The same ceiling the yard's own price carries - a timber price is still a price per tonne
    test()->actingAs($admin)
        ->patch(route('admin.nesting.settings.update', $business), nestingSettingsBody($business, [
            'cost_overrides' => ['TIMBER_MERCHANT' => ['material_cost_per_tonne' => 999999]],
        ]))
        ->assertSessionHasErrors('cost_overrides.TIMBER_MERCHANT.material_cost_per_tonne');
});

it('shows the admin what each merchant has been given', function () {
    $business = yardBuying(timberMerchant());
    $admin = createUser(1, createBusiness('admin'), true, true);

    test()->actingAs($admin)
        ->get(route('admin.nesting.algorithm', $business))
        ->assertInertia(fn (Assert $page) => $page
            ->component('AdminNestingAlgorithm')
            //The five a merchant may differ on, worded off the full dial list so the two agree
            ->has('merchantCoefficients', count(NestingCostModel::MERCHANT_COEFFICIENTS))
            ->where('merchantCoefficients.0.key', 'material_cost_per_tonne')
            //The yard's own figure rides along as the placeholder - an empty box only means
            //something if you can see what it falls back to. Int, not float: see json_encode above
            ->where('merchantCoefficients.0.yardValue', 2000)
            //Every merchant, including the ones this business has said nothing about
            ->has('merchants', count(SupplierGroupCosts::all()))
            ->where('merchants.2.value', 'TIMBER_MERCHANT')
            ->where('merchants.2.overrides.material_cost_per_tonne', 4000)
            //Null, not zero: nobody has answered this one for timber
            ->where('merchants.2.overrides.delivery_cost_per_tonne', null)
        );
});
