<?php

use App\Formatters\NestingFormatter;
use App\Models\Business;
use App\Services\NestingCostModel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function costModelBusiness(): Business
{
    return createBusiness('biz'.uniqid(), true);
}

/**
 * A nest that buys one 9,000mm bar per drop listed, makes one cut in each, and is left with that drop.
 *
 * @param  array<int, int>  $drops
 */
function costOfDrops(NestingCostModel $model, array $drops): float
{
    $destroyed = 0;
    $bars = [];

    foreach ($drops as $drop) {
        $bars[] = ['length' => 9000, 'drop' => $drop];

        if (! $model->isBanked($drop)) {
            $destroyed += $drop;
        }
    }

    return $model->cost(
        purchasedMm: 9000 * count($drops),
        scrapMm: $destroyed,
        kerfMm: 0,
        offcutDraws: [],
        barsOpened: $bars,
        cuts: count($drops),
        unmadeCuts: 0,
    );
}

/**
 * Money
 */
it('prices steel by the tonne and labour by the hour', function () {
    /**
     * Millimetres are not comparable across sections and are not comparable to time at all. Dollars are
     * the only currency in which "is this remnant worth the labour of keeping it" is even a question.
     */
    $model = new NestingCostModel(costModelBusiness(), 5.7, 12000);

    //$2,000/t is $2.00/kg. 800mm of 65x65x6 equal angle is 4.56kg, so a shade over $9
    expect($model->mmToKg(800))->toBeGreaterThan(4.5)->toBeLessThan(4.6)
        ->and($model->mmToCost(800))->toBeGreaterThan(9.0)->toBeLessThan(9.2);

    //$50/hr is 83.3c a minute
    expect($model->minutesToCost(60))->toBe(50.0)
        ->and($model->minutesToCost(6))->toBeGreaterThan(4.9)->toBeLessThan(5.1);
});

it('would be a disaster if scrapping steel was treated as a total loss', function () {
    /**
     * Steel cut off and binned is weighed in and credited at roughly 13% of the new price. That does not
     * make scrap cheap, but it is the difference between a write-off and a legitimately cheap way out of a
     * remnant nobody wants - and on a heavy section it is real money: 800mm of 500UB is $144 of steel and
     * nearly $19 of it comes back.
     */
    $business = costModelBusiness();
    $angle = new NestingCostModel($business, 5.7, 12000);
    $beam = new NestingCostModel($business, 90.0, 12000);

    expect($angle->scrapIncome(800))->toBeGreaterThan(1.1)->toBeLessThan(1.3)
        ->and($angle->netScrapCost(800))->toBeGreaterThan(7.8)->toBeLessThan(8.0)
        ->and($beam->scrapIncome(800))->toBeGreaterThan(18.5)->toBeLessThan(18.9);

    //Still a loss of most of the steel, so it never becomes a way to make money
    expect($angle->netScrapCost(800))->toBeGreaterThan($angle->mmToCost(800) * 0.8)
        ->and($beam->netScrapCost(800))->toBeLessThan($beam->mmToCost(800));
});

it('would be a disaster if saw kerf earned the same recycling credit as a solid drop', function () {
    /**
     * A solid drop goes in the bin and gets weighed in. Saw kerf leaves as swarf mixed with coolant and
     * whatever else was cut that day, which is not what a merchant pays for - so the same millimetres cost
     * more as kerf than as scrap.
     */
    $model = new NestingCostModel(costModelBusiness(), 22.9, 12000);

    $asScrap = $model->cost(
        purchasedMm: 9000, scrapMm: 300, kerfMm: 0,
        offcutDraws: [], barsOpened: [['length' => 9000, 'drop' => 300]], cuts: 1, unmadeCuts: 0,
    );
    $asKerf = $model->cost(
        purchasedMm: 9000, scrapMm: 0, kerfMm: 300,
        offcutDraws: [], barsOpened: [['length' => 9000, 'drop' => 300]], cuts: 1, unmadeCuts: 0,
    );

    expect($asKerf)->toBeGreaterThan($asScrap);
});

/**
 * Labour scales with the section
 */
it('would be a disaster if a 500UB was costed as quick to cut as a light angle', function () {
    /**
     * Cutting scales with mass per metre, which stands in for how much section the blade has to get
     * through. It does not scale with the length of the piece - a cut is a cut.
     */
    $business = costModelBusiness();

    $angle = (new NestingCostModel($business, 5.7, 12000))->cutMinutes();
    $beam = (new NestingCostModel($business, 90.0, 12000))->cutMinutes();

    expect($angle)->toBeLessThan(2.0)
        ->and($beam)->toBeGreaterThan(6.0)
        ->and($beam)->toBeGreaterThan($angle * 3);
});

it('would be a disaster if a tonne of beam was as quick to move as a 6m angle', function () {
    /**
     * Moving scales with the mass of the piece actually being moved: a 6m angle is carried by one person, a
     * 12m 500UB at over a tonne is a crane, slings and a second pair of hands.
     *
     * This is why the same handling decision comes out differently on light and heavy steel - and why the
     * heavy case is not simply "everything costs more", since the steel is worth more too.
     */
    $business = costModelBusiness();

    $angle = new NestingCostModel($business, 5.7, 12000);
    $beam = new NestingCostModel($business, 90.0, 12000);

    //A 6m angle is 34kg; a 12m 500UB is 1,080kg
    expect($angle->offcutDrawMinutes(6000))->toBeLessThan(5.0)
        ->and($beam->offcutDrawMinutes(12000))->toBeGreaterThan(19.0);

    //Fetching an offcut costs more than taking a new bar off the lift: it has to be found and its mark read
    expect($angle->offcutDrawMinutes(6000))->toBeGreaterThan($angle->barHandlingMinutes(6000));
});

/**
 * Labour against material - the rule the model turns on
 */
it('would be a disaster if labour was spent preserving a remnant worth less than the labour', function () {
    /**
     * The rule: keeping a remnant is only worth it while the remnant is worth more than the labour of
     * keeping it. Going to lengths over $9 of equal angle is never right; a heavy beam can carry a great
     * deal of handling before it stops paying.
     *
     * On light angle that lands past two metres - anything shorter costs more in marking, recording and
     * shifting than the steel will ever give back. On a 500UB almost anything over the scrap threshold is
     * worth having, because a metre of it is worth far more than the quarter hour it takes to deal with.
     */
    $business = costModelBusiness();

    $angleFloor = (new NestingCostModel($business, 5.7, 12000))->worthRackingFromMm();
    $beamFloor = (new NestingCostModel($business, 90.0, 12000))->worthRackingFromMm();

    expect($angleFloor)->toBeGreaterThan(2000)
        ->and($beamFloor)->toBeLessThan(1100)
        ->and($beamFloor)->toBeLessThan($angleFloor);
});

it('would be a disaster if a remnant cost more labour to keep than the remnant is worth', function () {
    /**
     * The same 1,200mm drop, on two sections, asked the way the rule is actually framed: is what the
     * remnant retains worth more than the labour of keeping it?
     *
     * On angle it retains about a dollar against five dollars of marking, recording and shifting, so it is
     * not worth preserving. On a 500UB the same 1,200mm retains seventeen dollars against six, so it is.
     *
     * Note this is NOT the same question as bank-versus-bin at a fixed drop length. Binning forfeits every
     * millimetre of the steel, so racking beats binning almost whatever the section - you would have to be
     * very pessimistic about ever using it to prefer the skip. What the rule governs is whether the nest
     * should ARRANGE itself to produce a remnant in the first place, which is where it bites: engineering a
     * stub gains almost nothing and costs a rack slot for years.
     */
    $business = costModelBusiness();

    $angle = new NestingCostModel($business, 5.7, 12000);
    $angleWorth = $angle->mmToCost($angle->inventoryValueMm(1200));
    $angleLabour = $angle->minutesToCost($angle->offcutRackMinutes(1200));

    expect($angleWorth)->toBeLessThan($angleLabour);

    $beam = new NestingCostModel($business, 90.0, 12000);
    $beamWorth = $beam->mmToCost($beam->inventoryValueMm(1200));
    $beamLabour = $beam->minutesToCost($beam->offcutRackMinutes(1200));

    expect($beamWorth)->toBeGreaterThan($beamLabour);
});

/**
 * The scrap threshold cliff
 */
it('would be a disaster if a drop one millimetre over the scrap threshold was still free', function () {
    /**
     * This is the defect the cost model was built for. Scrap was penalised and banked material was not, so
     * a 999mm drop cost 999 and a 1,001mm drop cost nothing at all. With a thousand search iterations the
     * solver reliably found the arrangement that landed just past the line, and the rack filled with stubs
     * at the least useful reusable length there is - while the yield figure went up.
     *
     * Two millimetres of difference must not move the cost by anything like two metres of steel.
     */
    $model = new NestingCostModel(costModelBusiness(), 10.0, 12000);

    //A 1,001mm drop is worth essentially nothing on the retention curve
    expect($model->retention(1001))->toBeLessThan(0.01)
        ->and($model->inventoryValueMm(1001))->toBeLessThan(10.0);

    /*
     * The incentive is what mattered. Banking a threshold stub used to cost NOTHING, so the search spent
     * its iterations engineering them; now it costs the labour that stub will take over its life, and that
     * labour is more than the stub retains. There is nothing left to game.
     */
    $stubLabour = $model->minutesToCost($model->offcutRackMinutes(1001));

    expect(costOfDrops($model, [1001]))->toBeGreaterThan(costOfDrops($model, [0]))
        ->and($model->mmToCost($model->inventoryValueMm(1001)))->toBeLessThan($stubLabour);

    /*
     * A step remains at the threshold, and it should: the threshold is what decides whether the steel is
     * kept at all, and binning forfeits every millimetre of it. What matters is that the step is now
     * smaller than simply writing the drop off, where the old ranking put the entire value of the steel
     * against zero.
     */
    expect(abs(costOfDrops($model, [999]) - costOfDrops($model, [1001])))
        ->toBeLessThan($model->mmToCost(999));
});

/**
 * Retention
 */
it('would be a disaster if two short drops were worth as much as one long one', function () {
    /**
     * One 2,500mm drop is a more useful offcut than a 1,000mm and a 1,500mm, though the totals are
     * identical. The old ranking reached for this with a separate "largest drop" tiebreak; here it falls
     * out of the retention curve being concave, which makes retained value rise faster than length - and
     * out of two pieces on the rack costing two pieces' worth of handling.
     */
    $model = new NestingCostModel(costModelBusiness(), 10.0, 12000);

    expect($model->inventoryValueMm(2500))
        ->toBeGreaterThan($model->inventoryValueMm(1000) + $model->inventoryValueMm(1500));

    //Two bars either way, so the same steel is bought - only what is left over differs
    expect(costOfDrops($model, [2500, 0]))->toBeLessThan(costOfDrops($model, [1000, 1500]));
});

it('would be a disaster if consuming a stub was costed as though its shortness were a loss', function () {
    /**
     * A 2,000mm offcut cut down to 1,500mm has given up very little - it was worth very little to start
     * with. A 9,000mm length cut down to 8,500mm has given up far more, even though the same 500mm came
     * off both.
     *
     * Charging the drop for its own shortness instead of differencing the two values gets this backwards,
     * and has the nest nibbling its long lengths while the stubs sit there forever.
     */
    $model = new NestingCostModel(costModelBusiness(), 10.0, 9000);

    $fromStub = $model->inventoryValueMm(2000) - $model->inventoryValueMm(1500);
    $fromLongLength = $model->inventoryValueMm(9000) - $model->inventoryValueMm(8500);

    expect($fromStub)->toBeLessThan($fromLongLength);
});

/**
 * Buying
 */
it('would be a disaster if steel was bought in order to bank the remainder', function () {
    /**
     * Retained value must never be worth as much per millimetre as new steel costs, or buying one more
     * millimetre of bar and racking it pays for itself.
     *
     * Retained value is V(L) = cap x L x sqrt((L-t)/(ref-t)), so dV/dL rises to 1.5 x cap at L = ref. At a
     * retention cap of 0.98 against a purchase weight of 1.0 that marginal value reached 1.47, and the nest
     * bought a 12,000mm bar to put a 2,500mm cut in and racked the other 8,000mm - over two 9,000mm bars
     * that covered the same cuts for 3,000mm less steel.
     */
    $business = costModelBusiness();

    expect(1.5 * (float) $business->offcut_retention_cap)
        ->toBeLessThan((float) $business->purchase_cost_weight);

    //And the nest it used to get wrong
    $result = (new NestingFormatter())->meterageAlgorithm(
        nestingCuts([[2500, 5], [1500, 2]]),
        [9000, 12000],
        [nestingOffcut(1, 2500)],
        [1 => 'A'],
        $business,
        nestingSection(22.9),
    );

    expect($result['totals']['newStock']['total'])->toBe(18000);
});

/**
 * Settings
 */
it('would be a disaster if a newly created business nested with no scrap threshold', function () {
    /**
     * A column default only fills the row. Business::create() is called with a handful of fields, so the
     * instance handed back had no scrap_threshold_mm attribute at all - and reading a missing attribute
     * gives null, which means "$drop >= $business->scrap_threshold_mm" was really "$drop >= 0". Every drop
     * down to a millimetre counted as reusable stock and was banked as an offcut, unless the model happened
     * to be re-read from the database first.
     */
    $business = createBusiness('fresh'.uniqid(), true);

    expect($business->scrap_threshold_mm)->toBe(1000)
        //Same class of bug: falsy meant the 12m delivery cap silently did not apply
        ->and($business->cap_12m_stock)->toBeTrue();

    //A 50mm drop is scrap, not inventory
    $model = new NestingCostModel($business, 10.0, 12000);
    expect($model->isBanked(50))->toBeFalse()
        ->and($model->inventoryValueMm(50))->toBe(0.0);
});

it('would be a disaster if a business missing the cost settings nested with no opinion', function () {
    /**
     * Every coefficient at zero does not make the model cautious, it makes it silent: the cost collapses to
     * "everything not cut into a piece", which is the same number for every candidate nest, so the search
     * stops discriminating and returns whichever plan it built first.
     *
     * A Business built without these attributes has to fall back to the defaults, not to nothing.
     */
    $stripped = new Business();
    $stripped->setRawAttributes([]);

    $model = new NestingCostModel($stripped, 10.0, 12000);

    //Still has a threshold, still prices steel and time, still discriminates
    expect($model->isBanked(50))->toBeFalse()
        ->and($model->retention(11000))->toBeGreaterThan(0.0)
        ->and($model->mmToCost(1000))->toBeGreaterThan(0.0)
        ->and($model->minutesToCost(60))->toBeGreaterThan(0.0)
        ->and(costOfDrops($model, [2500, 0]))->not->toBe(costOfDrops($model, [1000, 1500]));
});
