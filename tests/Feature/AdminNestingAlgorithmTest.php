<?php

use App\Models\Business;
use App\Models\User;
use App\Services\NestingCostModel;
use App\Services\NestingSettings;

it('would be a disaster if a non-admin could read another business\'s nesting settings', function () {
    $business = createBusiness('Business A');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('admin.nesting.algorithm'))
        ->assertRedirect('/');
});

it('explains the algorithm with the settings of the business being viewed', function () {
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $business->scrap_threshold_mm = 1500;
    $business->labour_rate_per_hour = 72.50;
    $business->offcut_rack_base_minutes = 9.5;
    $business->save();

    $response = $this->actingAs($admin)->get(route('admin.nesting.algorithm', $business->id));

    $response->assertInertia(fn ($page) => $page
        ->component('AdminNestingAlgorithm')
        ->where('business.name', 'Business A')
        ->where('business.isUnsaved', false)
    );

    //Looked up by key rather than by position, so reordering the dials is not a test failure
    $settings = collect($response->viewData('page')['props']['settings'])->keyBy('key');

    expect($settings['scrap_threshold_mm']['value'])->toBe(1500)
        ->and((float) $settings['labour_rate_per_hour']['value'])->toBe(72.50)
        ->and((float) $settings['offcut_rack_base_minutes']['value'])->toBe(9.5)
        ->and((float) $settings['scrap_recovery_rate']['value'])->toBe(0.13);
});

it('shows what acquiring new steel costs beyond the steel', function () {
    /**
     * The page's whole claim is that nothing on it is written alongside the model. Freight, receiving and the
     * order overhead are the newest part of the model and the easiest to let drift, because they are the part
     * a reader is most likely to want spelled out in prose.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $business->material_cost_per_tonne = 1800.00;
    $business->delivery_cost_per_tonne = 220.00;
    $business->delivery_cost_per_order = 90.00;
    $business->save();

    $response = $this->actingAs($admin)->get(route('admin.nesting.algorithm', $business->id));
    $props = $response->viewData('page')['props'];

    $acquisition = $props['acquisition'];

    //Freight is shown split out, and as the landed total everything is actually priced at
    expect($acquisition['bareCostPerTonne'])->toBe(1800.0)
        ->and($acquisition['freightPerTonne'])->toBe(220.0)
        ->and($acquisition['landedCostPerTonne'])->toBe(2020.0)
        ->and($acquisition['landedUpliftPct'])->toBeGreaterThan(12.0)->toBeLessThan(12.3)
        ->and($acquisition['freightIsSet'])->toBeTrue();

    //The order overhead is the paperwork plus the drop fee, and does not depend on the section
    expect($acquisition['orderOverheadCost'])
        ->toBe(round($acquisition['orderAdminCost'] + 90.0, 2));

    //Every per-section row adds up, and is the real model rather than a restatement of it
    $model = new NestingCostModel($business, 5.7, $props['referenceLengthMm']);
    $angle = collect($acquisition['rows'])->firstWhere('label', '65x65x6 EA');

    expect($angle['steelCost'])->toBe(round($model->mmToBareCost($props['referenceLengthMm']), 2))
        ->and($angle['freightCost'])->toBeGreaterThan(0.0)
        ->and(round($angle['steelCost'] + $angle['freightCost'], 2))
            ->toBe(round($model->mmToCost($props['referenceLengthMm']), 2))
        ->and($angle['landedAndRacked'])->toBeGreaterThan($angle['steelCost'] + $angle['freightCost']);

    //Heavier steel costs more to take in, because it is a crane rather than a pair of hands
    $rows = collect($acquisition['rows'])->keyBy('label');
    expect($rows['500UB']['receiveCost'])->toBeGreaterThan($rows['65x65x6 EA']['receiveCost']);
});

it('tells a business on no freight that none of it is priced yet', function () {
    /**
     * Freight defaults to zero because material_cost_per_tonne used to be described as a delivered figure, so
     * the page has to say so rather than quietly showing a $0 line. A reader who takes the zero at face value
     * concludes delivery is free, which is the opposite of what the model now believes.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $response = $this->actingAs($admin)->get(route('admin.nesting.algorithm', $business->id));
    $acquisition = $response->viewData('page')['props']['acquisition'];

    expect($acquisition['freightIsSet'])->toBeFalse()
        ->and($acquisition['freightPerTonne'])->toBe(0.0)
        //Landed and bare agree, so the page is not claiming an uplift it does not have
        ->and($acquisition['landedCostPerTonne'])->toBe($acquisition['bareCostPerTonne']);

    //The order overhead is still real - that one does not default to nothing
    expect($acquisition['orderOverheadCost'])->toBeGreaterThan(0.0);
});

it('shows what the scrap bin pays back', function () {
    /**
     * Binning steel is a loss of most of it, not all of it. The page has to show both halves, because
     * "scrap costs you everything" is what makes a nest cling to remnants it should let go of.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $response = $this->actingAs($admin)->get(route('admin.nesting.algorithm', $business->id));
    $props = $response->viewData('page')['props'];

    foreach ($props['sectionCurves'][0]['rows'] as $row) {
        //Binning is cheaper than the full value of the steel, but never free
        expect($row['scrapIncome'])->toBeGreaterThan(0.0)
            ->and($row['binCost'])->toBeGreaterThan($row['scrapIncome']);
    }

    //Heavier sections get proportionally the same back, which is a lot more money
    $rows = collect($props['labourBySection'])->keyBy('label');
    expect($rows['500UB']['scrapIncomePerMetre'])
        ->toBeGreaterThan($rows['65x65x6 EA']['scrapIncomePerMetre']);
});

it('would be a disaster if the page still rendered when the admin has no business', function () {
    /**
     * An admin account need not have a business attached, and the settings are per business. Falling back
     * to a fresh model rather than erroring means the route still answers - and a new Business carries the
     * documented defaults, which is what a page about the defaults wants to show.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);
    $admin->business_id = null;
    $admin->save();

    $this->actingAs($admin)
        ->get(route('admin.nesting.algorithm'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('business.isUnsaved', true)
            ->where('settings.0.key', 'scrap_threshold_mm')
            ->where('settings.0.value', 1000)
        );
});

it('shows what labour costs on real sections and where a remnant stops paying for itself', function () {
    /**
     * The table that answers the question the model exists for: don't spend labour preserving $9 of equal
     * angle, but a beam worth thousands can carry a good deal of extra handling first.
     *
     * A heavier section costs more to cut and far more to move - but the steel in it is worth more still,
     * so its remnants become worth keeping at a SHORTER length, not a longer one.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $response = $this->actingAs($admin)->get(route('admin.nesting.algorithm', $business->id));

    $rows = collect($response->viewData('page')['props']['labourBySection'])->keyBy('label');

    $angle = $rows['65x65x6 EA'];
    $beam = $rows['500UB'];

    //Heavier steel is slower to cut and slower to move
    expect($beam['cutMinutes'])->toBeGreaterThan($angle['cutMinutes'])
        ->and($beam['drawMinutes'])->toBeGreaterThan($angle['drawMinutes'])
        //Fetching an offcut beats fetching a bar, because it has to be found and its mark read first
        ->and($angle['drawMinutes'])->toBeGreaterThan($angle['barMinutes']);

    //But its remnants pay for their keep much sooner
    expect($beam['worthRackingFromMm'])->toBeLessThan($angle['worthRackingFromMm'])
        ->and($angle['worthRackingFromMm'])->toBeGreaterThan(2000)
        ->and($beam['worthRackingFromMm'])->toBeLessThan(1100);
});

it('would be a disaster if the curve drawn was not the curve the nest is scored on', function () {
    /**
     * The page exists to explain what nesting does, so every figure on it has to come from the real cost
     * model. Prose written alongside the model drifts from it the first time a coefficient changes.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $response = $this->actingAs($admin)->get(route('admin.nesting.algorithm', $business->id));

    $page = $response->viewData('page');
    $referenceLength = $page['props']['referenceLengthMm'];

    expect($page['props']['sectionCurves'])->not->toBeEmpty();

    foreach ($page['props']['sectionCurves'] as $curve) {
        $model = new NestingCostModel($business, $curve['kgPerM'], $referenceLength);

        //Every point on every curve sampled from the model itself
        foreach ($curve['points'] as $point) {
            expect($point['retention'])->toBe(round($model->retention($point['lengthMm']), 4))
                ->and($point['banked'])->toBe($model->isBanked($point['lengthMm']))
                ->and($point['worth'])->toBe(round($model->mmToCost($model->inventoryValueMm($point['lengthMm'])), 2));
        }

        //Each curve starts below the threshold, so the step up out of scrap is visible
        expect($curve['points'][0]['banked'])->toBeFalse()
            ->and(end($curve['points'])['banked'])->toBeTrue()
            //And the crossover the page marks is the model's own figure
            ->and($curve['worthRackingFromMm'])->toBe($model->worthRackingFromMm());
    }
});

it('would be a disaster if the dollar curve did not move with the section', function () {
    /**
     * The selector exists because the dollar figures are the point of the chart and they move by a factor of
     * sixteen between light angle and a heavy beam. Retention is a SHARE of value, so it is identical across
     * sections - only the money differs, and that is exactly why showing shares alone was not enough.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $response = $this->actingAs($admin)->get(route('admin.nesting.algorithm', $business->id));
    $curves = collect($response->viewData('page')['props']['sectionCurves'])->keyBy('label');

    $angle = $curves['65x65x6 EA'];
    $beam = $curves['500UB'];

    //Same shares...
    expect($beam['points'][45]['retention'])->toBe($angle['points'][45]['retention']);

    //...very different money, and a crossover in a different place
    expect($beam['points'][45]['worth'])->toBeGreaterThan($angle['points'][45]['worth'] * 10)
        ->and($beam['costPerMetre'])->toBeGreaterThan($angle['costPerMetre'])
        ->and($beam['worthRackingFromMm'])->toBeLessThan($angle['worthRackingFromMm']);

    //Each curve carries the table that goes under it, so the two cannot disagree about the section
    expect($angle['rows'])->not->toBeEmpty()
        ->and($beam['rows'][0]['binCost'])->toBeGreaterThan($angle['rows'][0]['binCost']);
});

it('would be a disaster if the page did not flag settings that buy steel to rack it', function () {
    /**
     * An offcut's marginal retained value reaches 1.5 x the retention cap. Once that meets the purchase
     * weight, buying one more millimetre of bar and banking it pays for itself and the nest buys a long
     * bar to make a short cut. The two settings are individually reasonable and only wrong together, so
     * the page has to say so rather than leave it to be noticed in a quote.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)
        ->get(route('admin.nesting.algorithm', $business->id))
        ->assertInertia(fn ($page) => $page->where('invariant.holds', true));

    //A cap high enough to make racking pay for itself
    $business->offcut_retention_cap = 0.98;
    $business->save();

    $this->actingAs($admin)
        ->get(route('admin.nesting.algorithm', $business->id))
        ->assertInertia(fn ($page) => $page
            ->where('invariant.holds', false)
            ->where('invariant.marginalRetainedValue', 1.47)
        );
});

it('costs each worked example on a light and a heavy section', function () {
    /**
     * The pair of answers is the argument for denominating the model in kilograms: the same settings reach
     * opposite conclusions, because handling is a fixed number of kilograms while steel scales with kg/m.
     *
     * The first example is the stub-versus-long-length case - retire a 1,500mm stub by binning 800mm, or
     * keep it and nibble a 12,000mm offcut instead.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $response = $this->actingAs($admin)->get(route('admin.nesting.algorithm', $business->id));

    $examples = $response->viewData('page')['props']['workedExamples'];
    [$takeStub, $takeLongLength] = $examples[0]['options'];

    //Light steel: 800mm is a few kilograms, so the stub is worth retiring
    expect($takeStub['costs']['light'])->toBeLessThan($takeLongLength['costs']['light']);

    //Heavy steel: the same 800mm is real money, so the long length is the one to cut
    expect($takeLongLength['costs']['heavy'])->toBeLessThan($takeStub['costs']['heavy']);
});

it('explains why the rack is cleared on a calendar rather than by the nest', function () {
    /*
     * The page hands an admin a per-section floor and then has to say what may be done with it. The
     * dangerous reading is "bin anything under the floor", and the numbers that refute it have to be
     * the real model's, not prose: keeping a fresh stub is cheaper than binning it for every section,
     * because the bin destroys most of the steel to save a few dollars of handling.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $response = $this->actingAs($admin)->get(route('admin.nesting.algorithm', $business->id));

    $cleanout = $response->viewData('page')['props']['cleanout'];

    expect($cleanout['bankAlwaysCheaper'])->toBeTrue()
        ->and($cleanout['thresholdMm'])->toBe((int) $business->scrap_threshold_mm)
        ->and($cleanout['shelfLifeDays'])->toBe((new App\Services\OffcutCleanout)->shelfLifeDays());

    $rows = collect($cleanout['rows'])->keyBy('label');

    foreach ($rows as $row) {
        $model = new NestingCostModel($business, $row['kgPerM'], 12000);
        $length = (int) $business->scrap_threshold_mm + 1;

        $bank = $model->minutesToCost($model->offcutRackMinutes($length))
            - $model->mmToCost($model->inventoryValueMm($length));

        expect($row['bankCost'])->toBe(round($bank, 2))
            ->and($row['binCost'])->toBe(round($model->netScrapCost($length), 2))
            ->and($row['bankIsCheaper'])->toBeTrue()
            //The same floor the labour table quotes, so the two halves of the page cannot disagree
            ->and($row['worthRackingFromMm'])->toBe($model->worthRackingFromMm());
    }
});

/**
 * A complete set of this business's current figures, with the given keys changed.
 *
 * Complete because the form posts every dial at once and the request requires every one of them:
 * a partial payload is a half-written cost model, and the half that was not written would silently
 * be whatever the column default happened to be.
 *
 * @param  array<string, float|int>  $changes
 * @return array<string, float|int>
 */
function nestingSettingsPayload(Business $business, array $changes = []): array
{
    return [...NestingSettings::inForce($business), ...$changes];
}

it('saves a yard its own freight, which nothing could set before', function () {
    /*
     * The gap this closes. Every coefficient had a column, a documented default and an explainer
     * page, and no write path anywhere in the application - so every installation ran on the same
     * figures, including no freight at all. The cost model's own documentation says freight is what
     * makes a yard slower to write a remnant off and that "a business opts in by setting its own
     * rate"; there was no opt-in to make.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    /*
     * Read now and held as numbers, not as a model. A NestingCostModel keeps the Business instance
     * it was built from, and that instance is the one refreshed below - so a model held across the
     * save answers with the new figures and compares equal to itself.
     */
    $before = new NestingCostModel($business, 5.68, 12000);
    $landedBefore = $before->landedCostPerTonne();
    $floorBefore = $before->worthRackingFromMm();

    expect($landedBefore)->toBe(2000.0);

    $this->actingAs($admin)
        ->patch(route('admin.nesting.settings.update', $business->id), nestingSettingsPayload($business, [
            'delivery_cost_per_tonne' => 150.00,
            'kerf_mm' => 3,
        ]))
        ->assertRedirect();

    $business->refresh();

    expect((float) $business->delivery_cost_per_tonne)->toBe(150.00)
        ->and((int) $business->kerf_mm)->toBe(3);

    /*
     * And it reaches the thing it exists to move. Freight raises what a remnant is worth without
     * raising the labour of keeping it, so the two curves cross sooner and fewer pieces fall under
     * the floor - a yard paying for delivery should be slower to bin steel than one collecting it.
     */
    $after = new NestingCostModel($business, 5.68, 12000);

    expect($after->landedCostPerTonne())->toBe(2150.0)
        ->and($after->worthRackingFromMm())->toBeLessThan($floorBefore);
});

it('would be a disaster if the dials could be set to buy steel in order to rack it', function () {
    /*
     * The one constraint between two of these that has to hold. Retained value rises to a slope of
     * 1.5 x cap at a full stock length, so once that reaches the purchase weight, buying one more
     * millimetre of bar and racking it pays for itself - and the nest starts buying steel to bank
     * the remainder. The page already reports this; until there was a form, reporting was all
     * anything could do about it.
     *
     * Refused rather than warned, and the message has to name BOTH settings: they are individually
     * reasonable and only wrong together, so an admin told off about the one they did not touch
     * will put it back where it was and try again.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $response = $this->actingAs($admin)
        ->patch(route('admin.nesting.settings.update', $business->id), nestingSettingsPayload($business, [
            'offcut_retention_cap' => 0.98,
            'purchase_cost_weight' => 1.0,
        ]));

    $response->assertSessionHasErrors('offcut_retention_cap');

    expect(session('errors')->first('offcut_retention_cap'))
        ->toContain('0.98')
        ->toContain('1.47');

    //And nothing was written
    expect((float) $business->fresh()->offcut_retention_cap)->toBe(0.6);
});

it('refuses a scrap threshold of nothing, which would bank every chip off the saw', function () {
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)
        ->patch(route('admin.nesting.settings.update', $business->id), nestingSettingsPayload($business, [
            'scrap_threshold_mm' => 0,
        ]))
        ->assertSessionHasErrors('scrap_threshold_mm');

    expect((int) $business->fresh()->scrap_threshold_mm)->toBe(1000);
});

it('would be a disaster if a non-admin could change what a yard nests on', function () {
    $business = createBusiness('Business A');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->patch(route('admin.nesting.settings.update', $business->id), nestingSettingsPayload($business, [
            'material_cost_per_tonne' => 1.00,
        ]))
        ->assertRedirect('/');

    expect((float) $business->fresh()->material_cost_per_tonne)->toBe(2000.00);
});

it('leaves a batch already nested on the figures it was nested on', function () {
    /*
     * The claim that makes editing these safe at all, and the reason the form can exist now when it
     * could not have before the settings were retained with the nest. A yard that puts its steel
     * price up has not made February's batches more expensive to have nested - the kilograms are
     * history and so are the dollars beside them.
     */
    [$business, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    //The admin nestedBatch already made, rather than a second one: every admin fixture shares the
    //one configured address, so creating another collides on users.email
    $admin = User::query()->where('is_admin', true)->firstOrFail();

    expect((float) $batch->nesting_settings['material_cost_per_tonne'])->toBe(2000.0);

    $this->actingAs($admin)
        ->patch(route('admin.nesting.settings.update', $business->id), nestingSettingsPayload($business, [
            'material_cost_per_tonne' => 3500.00,
        ]))
        ->assertRedirect();

    $batch->refresh();

    //The snapshot is untouched, and so is what anything costing this batch again will read
    expect((float) $batch->nesting_settings['material_cost_per_tonne'])->toBe(2000.0)
        ->and((float) NestingSettings::asOf($batch, $business->fresh())->material_cost_per_tonne)->toBe(2000.0);
});
