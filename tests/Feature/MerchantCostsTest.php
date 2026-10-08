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
 * Not everything a fabricator buys comes from the same merchant.
 *
 * The cost model held one price per tonne for the whole yard, which is right until the catalogue
 * holds something bought from somebody else at a different rate - and the bin pays a different
 * amount back, or nothing at all.
 *
 * It was written for timber. The catalogue carried LVL, nested by the metre exactly like a section
 * and costed at the steel rate with a scrap-merchant rebate on every drop that nobody ever paid,
 * and two errors were cancelling: the LVL rows carry no kg/m, so they fell back to 10.0 against a
 * true timber mass nearer 3.4, while being priced at $2,000/t against a timber price nearer
 * $4,000/t. Correcting either alone made the answer worse.
 *
 * Timber left on 2026-10-08 as too rare to carry for an audience of steel fabricators. These tests
 * moved to the profile cutter, which is a real merchant with a real category behind it, because
 * the SEAM is what they are about and the seam outlived the material that motivated it. The thing
 * they now also pin is that SupplierGroupCosts::PLATFORM_DEFAULTS is empty on purpose.
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

/** The profile cutter as a yard would describe it: dearer per tonne, and the bin pays nothing back. */
function profileCutter(): array
{
    return [
        'PROFILE_CUTTING' => [
            'material_cost_per_tonne' => 4000.0,
            'scrap_recovery_rate' => 0.0,
            'default_kg_per_m' => 3.4,
        ],
    ];
}

it('knows which merchant a product category is bought from', function () {
    /*
     * The map the whole feature turns on. Each implementation declares its own merchant, and the
     * cost model had no way to ask until this existed.
     */
    expect(SupplierGroupCosts::forCategory('PLATE'))->toBe('PROFILE_CUTTING')
        ->and(SupplierGroupCosts::forCategory('PFC'))->toBe('STEEL_MERCHANT')
        ->and(SupplierGroupCosts::forCategory('HEX_BOLT'))->toBe('FASTENERS')
        //Case-insensitive, because a piece spec carries whatever the importer classified it as
        ->and(SupplierGroupCosts::forCategory('plate'))->toBe('PROFILE_CUTTING')
        /*
         * A category with no implementation is costed on the yard's figures rather than a
         * guess. SHS stood here until it got an implementation of its own - every case in
         * ProductEnums now has one, so this needs a string that is not a category at all.
         */
        ->and(SupplierGroupCosts::forCategory('PURLIN'))->toBeNull()
        ->and(SupplierGroupCosts::forCategory(null))->toBeNull();
});

it('prices a metre at the merchant rate, not the yard rate', function () {
    $business = yardBuying(profileCutter());

    $steel = new NestingCostModel($business, 25.1, 9000, 'STEEL_MERCHANT');
    $cut = new NestingCostModel($business, 3.4, 6000, 'PROFILE_CUTTING');

    //25.1 kg/m at $2,000/t, against 3.4 kg/m at $4,000/t
    expect(round($steel->mmToCost(1000), 2))->toBe(50.2)
        ->and(round($cut->mmToCost(1000), 2))->toBe(13.6);
});

it('falls back to the merchant mass, where the catalogue has none', function () {
    $business = yardBuying(profileCutter());

    //No kg/m at all, which is the state 492 rows of the catalogue are in
    $cut = new NestingCostModel($business, null, 6000, 'PROFILE_CUTTING');
    $steel = new NestingCostModel($business, null, 9000, 'STEEL_MERCHANT');

    /*
     * This is the half that does the most damage. At the yard-wide 10.0 a light section is
     * costed as though it weighed three times what it does - and because the price per tonne was
     * also wrong, the two mistakes landed near a plausible number.
     */
    expect($cut->mmToKg(1000))->toBe(3.4)
        ->and($steel->mmToKg(1000))->toBe(10.0);
});

it('lets a merchant say the bin pays nothing, which a yard-wide rate could not', function () {
    $business = yardBuying(profileCutter());

    $cut = new NestingCostModel($business, 3.4, 6000, 'PROFILE_CUTTING');
    $steel = new NestingCostModel($business, 25.1, 9000, 'STEEL_MERCHANT');

    /*
     * A ZERO IS AN ANSWER, not an absent setting. A weighbridge buys metal; a timber merchant does
     * not buy LVL offcuts back, and a skip of timber costs tip fees. Nought has to beat the yard's
     * 0.13 rather than read as "nothing was set here" - which is the one thing a naive
     * "override if truthy" would have got wrong.
     */
    expect($cut->scrapIncome(1000))->toBe(0.0)
        ->and($steel->scrapIncome(1000))->toBeGreaterThan(0.0);
});

it('leaves every other merchant exactly where it was', function () {
    $plain = new NestingCostModel(yardBuying(), 25.1, 9000, 'STEEL_MERCHANT');
    $alongsideTheCutter = new NestingCostModel(yardBuying(profileCutter()), 25.1, 9000, 'STEEL_MERCHANT');
    $noGroupAtAll = new NestingCostModel(yardBuying(profileCutter()), 25.1, 9000);

    /*
     * Setting a timber rate must not move a single steel figure, and a nest costed without a
     * supplier group at all - which is every nest before this existed - must answer as it always
     * did. Three readings of one number.
     */
    expect($alongsideTheCutter->mmToCost(1000))->toBe($plain->mmToCost(1000))
        ->and($noGroupAtAll->mmToCost(1000))->toBe($plain->mmToCost(1000))
        ->and($alongsideTheCutter->scrapIncome(1000))->toBe($plain->scrapIncome(1000));
});

it('leaves the yard coefficients alone even for a merchant that carries its own prices', function () {
    $business = yardBuying(profileCutter());

    $cut = new NestingCostModel($business, 3.4, 6000, 'PROFILE_CUTTING');
    $steel = new NestingCostModel($business, 3.4, 6000, 'STEEL_MERCHANT');

    /*
     * One crew, one wage. A bundle of plate is carried by the same people who carry a beam, so
     * handling the same mass has to cost the same whoever sold it - otherwise the model is
     * expressing a preference for one merchant rather than a fact about the yard.
     */
    expect($cut->minutesToCost(60))->toBe($steel->minutesToCost(60))
        ->and($cut->cutMinutes())->toBe($steel->cutMinutes());
});

it('drops an override that names something it cannot honour, and keeps a nought', function () {
    $normalised = SupplierGroupCosts::normalise([
        'PROFILE_CUTTING' => [
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
        'PROFILE_CUTTING' => [
            'material_cost_per_tonne' => 4000.0,
            'scrap_recovery_rate' => 0.0,
        ],
    ]);
});

it('retains the merchant rates a nest was run on', function () {
    $business = yardBuying(profileCutter());

    $snapshot = NestingSettings::inForce($business);

    expect($snapshot[NestingSettings::OVERRIDES_KEY]['PROFILE_CUTTING']['material_cost_per_tonne'])->toBe(4000.0);

    //The yard renegotiates with its timber merchant after that nest was saved
    $business->update(['cost_overrides' => [
        'PROFILE_CUTTING' => ['material_cost_per_tonne' => 5500.0],
    ]]);

    $batch = new App\Models\Batch(['nesting_settings' => $snapshot]);
    $asNested = NestingSettings::asOf($batch, $business->fresh());

    /*
     * The whole reason the snapshot exists, reaching the merchant layer too. A yard that puts its
     * timber price up this month did not make last month's LVL nests more expensive to have run.
     */
    expect((new NestingCostModel($asNested, 3.4, 6000, 'PROFILE_CUTTING'))->mmToCost(1000))
        ->toBe(round(3.4 * 4000 / 1000, 10));
});

it('records the effective merchant rates, not the ones a yard happened to type', function () {
    /*
     * The snapshot stores the EFFECTIVE set - what the business typed laid over what the platform
     * says. It matters even though PLATFORM_DEFAULTS is empty today, because the day one is added
     * a snapshot that had recorded only the typed figures would silently read the new default back:
     * a batch nested in March would cost differently in May because the platform changed its mind.
     */
    $typed = profileCutter();
    $snapshot = NestingSettings::inForce(yardBuying($typed));

    expect($snapshot[NestingSettings::OVERRIDES_KEY]['PROFILE_CUTTING'])
        ->toBe(SupplierGroupCosts::effectiveFor(yardBuying($typed), 'PROFILE_CUTTING'));
});

it('carries no platform figures at all, which is a decision and not an oversight', function () {
    /**
     * PLATFORM_DEFAULTS held exactly one entry, TIMBER_MERCHANT, because the catalogue carried LVL
     * that was being priced as steel. Timber went on 2026-10-08 and nothing in the catalogue is
     * bought from anyone but a steel merchant now, so every figure here would be invented.
     *
     * Asserted rather than assumed, so that adding one is a deliberate act with a test to update -
     * the rule being that a default nobody has measured is indistinguishable from a real one once
     * it is sitting there. A yard that wants different figures still overrides them per merchant.
     */
    expect(SupplierGroupCosts::PLATFORM_DEFAULTS)->toBe([]);

    //So a yard that has said nothing records nothing - the key is absent, not an empty object
    $snapshot = NestingSettings::inForce(yardBuying());

    expect($snapshot)->not->toHaveKey(NestingSettings::OVERRIDES_KEY);
});

it('says nothing about a merchant nobody has an opinion on', function () {
    //This yard has an opinion about its profile cutter and none about anybody else
    $snapshot = NestingSettings::inForce(yardBuying(profileCutter()));

    /*
     * Absent, not an empty object. Nobody has given a figure for purlins - purlin steel really is
     * a different price per tonne from structural sections - and an invented one would be
     * indistinguishable from a real one once it was sitting in the snapshot.
     */
    expect($snapshot[NestingSettings::OVERRIDES_KEY])->toHaveKey('PROFILE_CUTTING')
        ->and($snapshot[NestingSettings::OVERRIDES_KEY])->not->toHaveKey('PURLINS')
        ->and($snapshot[NestingSettings::OVERRIDES_KEY])->not->toHaveKey('STEEL_MERCHANT');
});

it('costs a merchant nobody has priced exactly as the yard prices everything', function () {
    /**
     * With PLATFORM_DEFAULTS empty this is every business in the system, for every merchant: the
     * seam is present and says nothing, so the answer is the yard's own figures.
     *
     * Stated as an identity against the no-group model rather than as numbers, because the point
     * is that naming a merchant changes NOTHING until somebody gives that merchant a figure. The
     * day a platform default is added, this test is how you find out it reached further than the
     * one merchant it was written for.
     */
    $business = yardBuying();

    $named = new NestingCostModel($business, null, 6000, 'PROFILE_CUTTING');
    $yard = new NestingCostModel($business, null, 6000);

    expect($named->mmToKg(1000))->toBe($yard->mmToKg(1000))
        ->and($named->scrapIncome(1000))->toBe($yard->scrapIncome(1000))
        ->and($named->mmToCost(1000))->toBe($yard->mmToCost(1000));
});

it('lets a yard correct one coefficient without restating the ones it agrees with', function () {
    //This yard's profile cutter is dearer per tonne than the steel it buys, and that is all it says
    $business = yardBuying(['PROFILE_CUTTING' => ['material_cost_per_tonne' => 4600.0]]);

    $cutter = new NestingCostModel($business, 3.4, 6000, 'PROFILE_CUTTING');
    $yard = new NestingCostModel($business, 3.4, 6000);

    /*
     * Merged per coefficient, not per merchant. Overriding a whole merchant at once would mean
     * filling in two boxes you agree with in order to change the third, so only the figure named
     * is the merchant's and everything else falls through to the yard.
     */
    expect(SupplierGroupCosts::effectiveFor($business, 'PROFILE_CUTTING'))
        ->toBe(['material_cost_per_tonne' => 4600.0]);

    /*
     * Scrap income is deliberately NOT asserted equal. The recovery RATE is still the yard's 13% -
     * nobody overrode it - but the income is that rate against the material price, so it moves
     * with the price by arithmetic rather than by anything having been overridden. The mass
     * fallback is the one that can be compared directly, and it is untouched.
     */
    expect($cutter->mmToCost(1000))->not->toBe($yard->mmToCost(1000))
        ->and((new NestingCostModel($business, null, 6000, 'PROFILE_CUTTING'))->mmToKg(1000))
        ->toBe((new NestingCostModel($business, null, 6000))->mmToKg(1000));
});

it('values a drop through its own merchant when the scrap ledger reads the nest back', function () {
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
        $product->product_category = 'PLATE';
        $product->kg_per_m = 3.4;
    }

    $batch->nested_state = $state;
    $batch->nesting_settings = [...$batch->nesting_settings, 'cost_overrides' => profileCutter()];
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
        $product->product_category = 'PLATE';
    }

    $batch->nested_state = $state;

    /*
     * The yard's figure for its profile cutter. It has to be said out loud now: PLATFORM_DEFAULTS
     * is empty, so without an override there is no second opinion to re-price through and the
     * command would correctly find nothing to do.
     */
    $batch->nesting_settings = [...$batch->nesting_settings, 'cost_overrides' => profileCutter()];
    $batch->save();

    $batch->user->business->update(['cost_overrides' => profileCutter()]);

    Scrap::query()->where('batch_id', $batch->id)->update([
        'product_category' => 'PLATE',
        'material' => 'PLAIN_CARBON_STEEL',
    ]);

    $before = Scrap::query()->where('batch_id', $batch->id)->orderBy('id')->first();

    expect($before->recovered_value)->toBeGreaterThan(0);

    //A dry run by default, because this restates history and nobody should do that by accident
    test()->artisan('scrap:revalue')->assertSuccessful();

    expect($before->fresh()->recovered_value)->toBe($before->recovered_value);

    test()->artisan('scrap:revalue --apply')->assertSuccessful();

    $after = $before->fresh();

    /*
     * The cutter keeps its own skeleton, so the bin pays nothing, and the mass is the cutter's.
     * The length and the bar it came off are untouched - that the drop happened is a fact, and
     * only the money derived from it was wrong.
     */
    expect($after->recovered_value)->toBe(0.0)
        ->and($after->kg_per_m)->toBe(3.4)
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
                'PROFILE_CUTTING' => [
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
        'PROFILE_CUTTING' => [
            'material_cost_per_tonne' => 4000.0,
            'scrap_recovery_rate' => 0.0,
            'default_kg_per_m' => 3.4,
        ],
    ]);
});

it('clears a merchant rate when its boxes are emptied', function () {
    $business = yardBuying(profileCutter());
    $admin = createUser(1, createBusiness('admin'), true, true);

    test()->actingAs($admin)->patch(route('admin.nesting.settings.update', $business), nestingSettingsBody($business, [
        'cost_overrides' => ['PROFILE_CUTTING' => array_fill_keys(NestingCostModel::MERCHANT_COEFFICIENTS, '')],
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
            'cost_overrides' => ['PROFILE_CUTTING' => ['labour_rate_per_hour' => 90]],
        ]))
        /*
         * Refused rather than dropped. A figure silently discarded on the way in is the worst
         * outcome available: the form comes back looking saved and the nest does not change.
         */
        ->assertSessionHasErrors('cost_overrides.PROFILE_CUTTING');

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
            'cost_overrides' => ['PROFILE_CUTTING' => ['material_cost_per_tonne' => 999999]],
        ]))
        ->assertSessionHasErrors('cost_overrides.PROFILE_CUTTING.material_cost_per_tonne');
});

it('shows the admin what each merchant has been given', function () {
    $business = yardBuying(profileCutter());
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
            ->where('merchants.3.value', 'PROFILE_CUTTING')
            ->where('merchants.3.overrides.material_cost_per_tonne', 4000)
            //Null, not zero: nobody has answered this one for the cutter
            ->where('merchants.3.overrides.delivery_cost_per_tonne', null)
        );
});
