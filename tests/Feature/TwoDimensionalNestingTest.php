<?php

use App\Models\Bar;
use App\Models\Batch;
use App\Models\Offcut;
use App\Services\TwoDimensionalNesting;
use App\Services\TwoDimensionalNestingCost;
use App\Services\TwoDimensionalNestingProof;

/**
 * The admin "2D Nesting" page runs three plate lifecycles and reports what it checked about them.
 *
 * The important test here is the third one. The page's checks ARE assertions - every part placed, every
 * sheet balancing, no two parts claiming the same steel, no steel appearing or vanishing, the three-part
 * remnant test honoured, and a remnant of a remnant cut again - run against plans the live engine
 * produced. Asserting that they all pass turns the page into a regression test of the 2D nesting itself:
 * the scenarios are fixed, so nothing but a change to the engine can make one fail.
 *
 * The rest are about the thing the page exists to settle - that "worthwhile" in two dimensions is not a
 * threshold on area - and about the invariants a cutting plan has to hold whatever the inputs.
 */
it('would be a disaster if a non-admin could read the 2D nesting page', function () {
    $business = createBusiness('Business A');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('admin.nesting.2d'))
        ->assertRedirect('/');
});

it('shows an admin three plate lifecycles', function () {
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $response = $this->actingAs($admin)->get(route('admin.nesting.2d'));

    $response->assertInertia(fn ($page) => $page
        ->component('TwoDimensionalNesting')
        ->has('scenarios', 3)
        ->where('settings.minOffcutSideMm', TwoDimensionalNestingProof::MIN_OFFCUT_SIDE_MM)
        ->where('settings.minOffcutAreaMm2', TwoDimensionalNestingProof::MIN_OFFCUT_AREA_MM2)
        ->where('settings.kerfMm', TwoDimensionalNestingProof::KERF_MM)
    );
});

it('passes every check it makes about the plans the engine produced', function () {
    $scenarios = (new TwoDimensionalNestingProof)->scenarios();

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

it('cuts a remnant that was itself cut from a remnant, in all three', function () {
    /*
     * The one thing the page exists for, on the inventory side. A scenario that merely banked remnants and
     * never came back for them would still pass every balance check and would be proving nothing about the
     * lifecycle - so the depth is asserted separately rather than left to the checks panel.
     */
    foreach ((new TwoDimensionalNestingProof)->scenarios() as $scenario) {
        $deepDraws = 0;

        foreach ($scenario['steps'] as $step) {
            foreach ($step['pieces'] as $piece) {
                if ($piece['fromRack'] && $piece['generation'] >= 2) {
                    $deepDraws++;
                }
            }
        }

        expect($deepDraws)->toBeGreaterThan(0, $scenario['title'].' never drew a remnant of a remnant');
    }
});

it('reaches a third generation in every scenario', function () {
    //Depth is the claim, so it is asserted rather than described
    foreach ((new TwoDimensionalNestingProof)->scenarios() as $scenario) {
        $deepest = max(array_column($scenario['lineage'], 'generation'));

        expect($deepest)->toBeGreaterThanOrEqual(3, $scenario['title'].' never reached a third generation');
    }
});

it('writes nothing while it runs', function () {
    /*
     * The rack is carried in memory on purpose - see Services\TwoDimensionalNestingProof. There is no
     * sheet-remnant table for this to write to, and it must not reach for the bar nesting's Offcut rows
     * either: a proof of concept that polluted whichever installation it was opened on would stop being
     * the same proof the second time somebody looked at it.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->get(route('admin.nesting.2d'))->assertOk();

    expect(Offcut::query()->count())->toBe(0)
        ->and(Bar::query()->count())->toBe(0)
        ->and(Batch::query()->count())->toBe(0);
});

it('answers the same way twice', function () {
    /*
     * The engine searches a hundred-odd candidate plans with a seeded randomiser, so two runs of the same
     * inputs have to agree - which is what makes the page worth calling a proof at all, and what the live
     * nesting needs for the plan a customer approves to be the plan that gets cut.
     */
    $first = (new TwoDimensionalNestingProof)->scenarios();
    $second = (new TwoDimensionalNestingProof)->scenarios();

    expect($second)->toEqual($first);
});

it('refuses to bank a strip that has plenty of area and no usable width', function () {
    /*
     * The finding this whole page is here to make. The band is 0.54m2 - more than three times the minimum
     * area - and nothing anybody orders is under 180mm wide, so it is worth nothing. A single threshold
     * cannot express that, which is why the 1D rule does not survive the second dimension intact.
     */
    $cost = new TwoDimensionalNestingCost(
        settings: [],
        kgPerM2: 47.1,
        minSideMm: 250,
        minAreaMm2: 160_000,
        referenceAreaMm2: 4_500_000,
    );

    expect($cost->isBanked(3000, 180))->toBeFalse()
        ->and(3000 * 180)->toBeGreaterThan(160_000);

    //And the square of less than half the area is kept
    expect($cost->isBanked(600, 600))->toBeTrue()
        ->and(600 * 600)->toBeLessThan(3000 * 180);
});

it('values a square remnant above a strip of the same area', function () {
    /*
     * The term with no 1D counterpart. Two remnants of 720,000mm2 - one 1,200 x 600, one 2,400 x 300 - are
     * both banked, and they are not worth the same, because only one of them holds a 600mm part.
     */
    $cost = new TwoDimensionalNestingCost(
        settings: [],
        kgPerM2: 47.1,
        minSideMm: 250,
        minAreaMm2: 160_000,
        referenceAreaMm2: 4_500_000,
    );

    $square = $cost->inventoryValueMm2(1200, 600);
    $strip = $cost->inventoryValueMm2(2400, 300);

    expect(1200 * 600)->toBe(2400 * 300)
        ->and($square)->toBeGreaterThan($strip);
});

it('conserves every square millimetre of every piece it opens', function () {
    /*
     * The invariant the whole thing rests on, asserted against the engine directly rather than through the
     * fixed scenarios: parts + kerf + racked + binned is the piece they all came off, on every piece, for
     * inputs the proof page never runs.
     */
    $parts = [];
    $id = 1;

    foreach ([[1200, 600, 3], [900, 400, 4], [500, 500, 5], [1500, 300, 2], [260, 260, 6]] as [$width, $height, $qty]) {
        for ($i = 0; $i < $qty; $i++) {
            $parts[] = ['width' => $width, 'height' => $height, 'letter' => 'A', 'piece_id' => $id++];
        }
    }

    $plan = (new TwoDimensionalNesting)->nest(
        $parts,
        [['width' => 2400, 'height' => 1200], ['width' => 3000, 'height' => 1500]],
        [['id' => 1, 'unique_mark' => 'AAA', 'width' => 1400, 'height' => 900, 'generation' => 1]],
        ['iterations' => 30],
    );

    expect($plan['pieces'])->not->toBeEmpty();
    expect($plan['unmade'])->toBeEmpty();

    foreach ($plan['pieces'] as $piece) {
        expect($piece['balance']['ok'])->toBeTrue(
            $piece['width'].'x'.$piece['height'].' did not balance: '
                .$piece['balance']['total'].' against '.$piece['areaMm2'],
        );
    }
});

it('never lets two rectangles claim the same steel, or any of them run off the edge', function () {
    $parts = [];
    $id = 1;

    foreach ([[1100, 700, 4], [640, 480, 6], [380, 290, 7]] as [$width, $height, $qty]) {
        for ($i = 0; $i < $qty; $i++) {
            $parts[] = ['width' => $width, 'height' => $height, 'letter' => 'A', 'piece_id' => $id++];
        }
    }

    $plan = (new TwoDimensionalNesting)->nest(
        $parts,
        [['width' => 2400, 'height' => 1200], ['width' => 3000, 'height' => 1500]],
        [],
        ['iterations' => 30],
    );

    foreach ($plan['pieces'] as $piece) {
        $rects = [...$piece['placements'], ...$piece['banked'], ...$piece['scrap']];

        foreach ($rects as $a => $first) {
            expect($first['x'])->toBeGreaterThanOrEqual(0)
                ->and($first['y'])->toBeGreaterThanOrEqual(0)
                ->and($first['x'] + $first['width'])->toBeLessThanOrEqual($piece['width'])
                ->and($first['y'] + $first['height'])->toBeLessThanOrEqual($piece['height']);

            foreach ($rects as $b => $second) {
                if ($a >= $b) {
                    continue;
                }

                $apart = $first['x'] >= $second['x'] + $second['width']
                    || $first['x'] + $first['width'] <= $second['x']
                    || $first['y'] >= $second['y'] + $second['height']
                    || $first['y'] + $first['height'] <= $second['y'];

                expect($apart)->toBeTrue('Two rectangles overlap on a '.$piece['width'].'x'.$piece['height'].' piece');
            }
        }
    }
});

it('gives the same plan for the same inputs every time', function () {
    /*
     * The same requirement the 1D nesting has, and for the same reason: the search is random, so without a
     * seed tied to the inputs the plan somebody approves on screen is not the plan that gets cut.
     */
    $parts = [
        ['width' => 1200, 'height' => 600, 'letter' => 'A', 'piece_id' => 1],
        ['width' => 800, 'height' => 500, 'letter' => 'A', 'piece_id' => 2],
        ['width' => 650, 'height' => 400, 'letter' => 'B', 'piece_id' => 3],
        ['width' => 400, 'height' => 300, 'letter' => 'B', 'piece_id' => 4],
    ];

    $sheets = [['width' => 2400, 'height' => 1200], ['width' => 3000, 'height' => 1500]];
    $rack = [['id' => 1, 'unique_mark' => 'AAA', 'width' => 1400, 'height' => 900, 'generation' => 1]];

    $first = (new TwoDimensionalNesting)->nest($parts, $sheets, $rack, ['iterations' => 40]);
    $second = (new TwoDimensionalNesting)->nest($parts, $sheets, $rack, ['iterations' => 40]);

    expect($second['cost'])->toBe($first['cost'])
        ->and($second['pieces'])->toEqual($first['pieces']);
});

it('reports a part nothing could hold rather than quietly leaving it out', function () {
    /*
     * A cheaper plan that drops a part is not a cheaper plan. The penalty is what stops the search
     * preferring one, and the count is what the page reports - see TwoDimensionalNestingCost.
     */
    $plan = (new TwoDimensionalNesting)->nest(
        [
            ['width' => 400, 'height' => 400, 'letter' => 'A', 'piece_id' => 1],
            //Wider than any sheet in the shop
            ['width' => 4000, 'height' => 900, 'letter' => 'A', 'piece_id' => 2],
        ],
        [['width' => 2400, 'height' => 1200]],
        [],
        ['iterations' => 5],
    );

    expect($plan['unmade'])->toHaveCount(1)
        ->and($plan['unmade'][0]['piece_id'])->toBe(2)
        ->and($plan['cost'])->toBeGreaterThan(TwoDimensionalNestingCost::UNMADE_PART_PENALTY);
});

it('draws a remnant off the rack rather than buying a sheet for a job that fits it', function () {
    /*
     * The behaviour the whole offcut philosophy is for, and the one that is easy to lose: a small job that
     * the rack covers must not go to the merchant. The 1D proof makes the same point in prose - freight,
     * receiving, the paperwork of an order, and a sheet nobody asked for leaning against the wall.
     */
    $plan = (new TwoDimensionalNesting)->nest(
        [
            ['width' => 700, 'height' => 500, 'letter' => 'A', 'piece_id' => 1],
            ['width' => 600, 'height' => 400, 'letter' => 'A', 'piece_id' => 2],
        ],
        [['width' => 2400, 'height' => 1200], ['width' => 3000, 'height' => 1500]],
        [['id' => 1, 'unique_mark' => 'AAA', 'width' => 1400, 'height' => 900, 'generation' => 1]],
        ['iterations' => 40],
    );

    expect($plan['pieces'])->toHaveCount(1)
        ->and($plan['pieces'][0]['fromRack'])->toBeTrue()
        ->and($plan['pieces'][0]['mark'])->toBe('AAA')
        ->and($plan['totals']['boughtMm2'])->toBe(0);
});
