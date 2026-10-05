<?php

use App\Models\Offcut;
use App\Services\NestingProof;

/**
 * The /proof page runs three nesting lifecycles and reports what it checked about them.
 *
 * The important test here is the third one. The page's checks ARE assertions - every cut made, every bar
 * balancing, no steel appearing or vanishing, the scrap threshold honoured, and an offcut-of-offcut cut
 * again - run against plans the live algorithm produced. Asserting that they all pass turns the page into
 * a regression test of the nesting itself: the scenarios are fixed, so nothing but a change to the
 * algorithm can make one fail.
 */
it('would be a disaster if a non-admin could read the proof page', function () {
    $business = createBusiness('Business A');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('proof'))
        ->assertRedirect('/');
});

it('shows an admin three nesting lifecycles', function () {
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $response = $this->actingAs($admin)->get(route('proof'));

    $response->assertInertia(fn ($page) => $page
        ->component('Proof')
        ->has('scenarios', 3)
        ->where('settings.scrapThresholdMm', NestingProof::SCRAP_THRESHOLD_MM)
        ->where('settings.kerfMm', NestingProof::KERF_MM)
    );
});

it('passes every check it makes about the plans the algorithm produced', function () {
    $scenarios = (new NestingProof)->scenarios();

    expect($scenarios)->toHaveCount(3);

    foreach ($scenarios as $scenario) {
        expect($scenario['checks'])->not->toBeEmpty();

        foreach ($scenario['checks'] as $check) {
            expect($check['passed'])->toBeTrue(
                $scenario['title'].' failed its check: '.$check['label'].' - '.$check['detail'],
            );
        }
    }
});

it('cuts an offcut that was itself cut from an offcut, in all three', function () {
    /*
     * The one thing the page exists for. A scenario that merely banked a remnant and never came back for it
     * would still pass every balance check, and would be proving nothing about the lifecycle - so the depth
     * is asserted separately rather than left to the checks panel.
     */
    foreach ((new NestingProof)->scenarios() as $scenario) {
        $deepest = max(array_column($scenario['lineage'], 'generation'));

        expect($deepest)->toBeGreaterThanOrEqual(2, $scenario['title'].' never produced an offcut-of-offcut');

        //And it was drawn again rather than left on the rack to be counted
        $drawnGenerations = [];
        foreach ($scenario['steps'] as $step) {
            foreach ($step['draws'] as $draw) {
                $drawnGenerations[] = $draw['generation'];
            }
        }

        expect(max($drawnGenerations))->toBeGreaterThanOrEqual(2, $scenario['title'].' banked an offcut-of-offcut but never cut it');
    }
});

it('reaches a third generation in the scenario that claims to', function () {
    //The first scenario's whole claim is depth, so it is asserted rather than described
    $generations = (new NestingProof)->scenarios()[0];

    expect(max(array_column($generations['lineage'], 'generation')))->toBe(3)
        ->and($generations['key'])->toBe('generations');
});

it('writes nothing while it runs', function () {
    /*
     * The rack is carried in memory on purpose - see Services\NestingProof. A proof that wrote Offcut rows
     * would pollute whichever installation it was opened on, and would stop being the same proof the second
     * time somebody looked at it.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->get(route('proof'))->assertOk();

    expect(Offcut::query()->count())->toBe(0);
});

it('answers the same way twice', function () {
    /*
     * Nesting searches a thousand candidate plans with a seeded randomiser, so two runs of the same inputs
     * have to agree. On the live screens that is what keeps the plan a customer approved and the plan saved
     * against their batch the same plan; here it is what makes the page worth calling proof at all.
     */
    $first = (new NestingProof)->scenarios();
    $second = (new NestingProof)->scenarios();

    expect($second)->toEqual($first);
});
