<?php

use App\Services\NestingCostModel;

it('would be a disaster if a non-admin could read another business\'s nesting settings', function () {
    $business = createBusiness('Business A', true);
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('admin.nesting.algorithm'))
        ->assertRedirect('/');
});

it('explains the algorithm with the settings of the business being viewed', function () {
    $business = createBusiness('Business A', true);
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

it('shows what the scrap bin pays back', function () {
    /**
     * Binning steel is a loss of most of it, not all of it. The page has to show both halves, because
     * "scrap costs you everything" is what makes a nest cling to remnants it should let go of.
     */
    $business = createBusiness('Business A', true);
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
    $business = createBusiness('Business A', true);
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
    $business = createBusiness('Business A', true);
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
    $business = createBusiness('Business A', true);
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
    $business = createBusiness('Business A', true);
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
    $business = createBusiness('Business A', true);
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
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $response = $this->actingAs($admin)->get(route('admin.nesting.algorithm', $business->id));

    $examples = $response->viewData('page')['props']['workedExamples'];
    [$takeStub, $takeLongLength] = $examples[0]['options'];

    //Light steel: 800mm is a few kilograms, so the stub is worth retiring
    expect($takeStub['costs']['light'])->toBeLessThan($takeLongLength['costs']['light']);

    //Heavy steel: the same 800mm is real money, so the long length is the one to cut
    expect($takeLongLength['costs']['heavy'])->toBeLessThan($takeStub['costs']['heavy']);
});
