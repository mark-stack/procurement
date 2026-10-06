<?php

use App\Enums\NestingEnums;
use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Models\Business;
use App\Services\NestingCostModel;
use App\Services\NestingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function costModelBusiness(): Business
{
    return createBusiness('biz'.uniqid(), true);
}

/**
 * A nest that buys one 9,000mm bar per offcut listed, makes one cut in each, and is left with that offcut.
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

it('would be a disaster if saw kerf earned the same recycling credit as a solid offcut', function () {
    /**
     * A solid offcut goes in the bin and gets weighed in. Saw kerf leaves as swarf mixed with coolant and
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
 * Acquiring steel
 */
it('would be a disaster if freight was priced as a line of its own rather than into the steel', function () {
    /**
     * Every question the model asks about a millimetre of steel - what it is worth on the rack, what buying
     * it costs, what destroying it loses - wants the LANDED figure, because steel already in the yard has had
     * its freight paid. That is the entire reason a remnant is worth keeping.
     *
     * So freight is added to the steel price rather than charged separately, and the bare price survives for
     * the one consumer that genuinely needs it: the scrap bin.
     */
    $freighted = costModelBusiness();
    $freighted->delivery_cost_per_tonne = 200.00;

    $model = new NestingCostModel($freighted, 5.7, 12000);

    expect($model->landedCostPerTonne())->toBe(2200.0)
        //A 12m bar of 65x65x6 is 68.4kg: $136.80 of metal, $150.48 landed
        ->and($model->mmToBareCost(12000))->toBeGreaterThan(136.0)->toBeLessThan(137.0)
        ->and($model->mmToCost(12000))->toBeGreaterThan(150.0)->toBeLessThan(151.0);

    //And a business that has not set a freight rate is priced exactly as it was before freight existed
    $collected = new NestingCostModel(costModelBusiness(), 5.7, 12000);

    expect($collected->landedCostPerTonne())->toBe(2000.0)
        ->and($collected->mmToCost(12000))->toBe($collected->mmToBareCost(12000));
});

it('would be a disaster if the scrap bin paid back part of your own delivery bill', function () {
    /**
     * A merchant weighs in metal and pays for metal. The freight paid to get it here is not on the
     * weighbridge, so recovery is a share of the BARE price.
     *
     * Taking a share of the landed price instead would have the bin refunding part of the delivery - and the
     * more a business paid in freight, the more scrapping would appear to pay it back. It is the exact
     * opposite: freight makes destroying steel worse, because the delivery was bought too and none of it
     * comes back.
     */
    /*
     * Two businesses rather than one mutated between the two models. The model reads its coefficients off
     * the Business lazily, by design - see its DEFAULTS note - so raising the freight on a shared instance
     * would raise it for the model built before the change as well, and the comparison would be against
     * itself.
     */
    $bare = new NestingCostModel(costModelBusiness(), 22.9, 12000);

    $freightedBusiness = costModelBusiness();
    $freightedBusiness->delivery_cost_per_tonne = 300.00;
    $freighted = new NestingCostModel($freightedBusiness, 22.9, 12000);

    //The bin pays the same either way - it is the same weight of the same steel
    expect($freighted->scrapIncome(1000))->toBe($bare->scrapIncome(1000));

    //But binning it costs more, because the freight went in the skip with it
    expect($freighted->netScrapCost(1000))->toBeGreaterThan($bare->netScrapCost(1000));

    //Never a way to make money, at any freight rate
    expect($freighted->netScrapCost(1000))->toBeLessThan($freighted->mmToCost(1000))
        ->and($freighted->netScrapCost(1000))->toBeGreaterThan(0.0);
});

it('would be a disaster if a bought bar was fetched to the saw without ever being received', function () {
    /**
     * Receiving and fetching are two different jobs, and a bought bar pays both: off the truck, checked
     * against the docket and racked, and then - separately, possibly weeks later - carried to the saw. A
     * piece already in the yard has only the second one left to pay, which is a large part of why drawing on
     * the rack beats buying.
     *
     * Receiving scales with the mass of the bar like every other move, so two short bars cost more to take
     * in than one long one covering the same steel.
     */
    $business = costModelBusiness();
    $model = new NestingCostModel($business, 22.9, 12000);

    expect($model->receiveMinutes(12000))->toBeGreaterThan($model->barHandlingMinutes(12000));

    //Two 6m bars are two trips off the truck; one 12m bar is one
    expect($model->receiveMinutes(6000) * 2)->toBeGreaterThan($model->receiveMinutes(12000));

    //A nest that buys carries both, so the same cut is dearer from a new bar than from an offcut of it
    $fromNewBar = $model->cost(
        purchasedMm: 12000, scrapMm: 0, kerfMm: 0,
        offcutDraws: [], barsOpened: [['length' => 12000, 'drop' => 9000]], cuts: 1, unmadeCuts: 0,
    );
    $fromTheRack = $model->cost(
        purchasedMm: 0, scrapMm: 0, kerfMm: 0,
        offcutDraws: [['source' => 12000, 'drop' => 9000]], barsOpened: [], cuts: 1, unmadeCuts: 0,
    );

    expect($fromTheRack)->toBeLessThan($fromNewBar);
});

it('would be a disaster if buying three hundred millimetres of steel cost nothing to order', function () {
    /**
     * Raising an order has a fixed cost - getting a price, placing it, checking the invoice against what
     * turned up, and a truck turning up with whatever is on it. Without it, a nest could buy a short bar to
     * save a walk to the rack and pay only for the metal.
     *
     * Charged on WHETHER a plan buys rather than how much, which is the comparison it exists for. It cannot
     * re-rank the plans that buy, because they all carry exactly the same charge.
     */
    $business = costModelBusiness();
    $business->delivery_cost_per_order = 85.00;

    $model = new NestingCostModel($business, 5.7, 12000);

    //15 minutes at $50/hr is $12.50, plus the drop fee
    expect($model->orderOverheadCost())->toBeGreaterThan(97.0)->toBeLessThan(98.0);

    $buysNothing = $model->cost(
        purchasedMm: 0, scrapMm: 0, kerfMm: 0,
        offcutDraws: [['source' => 3000, 'drop' => 2000]], barsOpened: [], cuts: 1, unmadeCuts: 0,
    );
    $buysOneShortBar = $model->cost(
        purchasedMm: 1000, scrapMm: 0, kerfMm: 0,
        offcutDraws: [], barsOpened: [['length' => 1000, 'drop' => 0]], cuts: 1, unmadeCuts: 0,
    );

    expect($buysOneShortBar)->toBeGreaterThan($buysNothing + $model->orderOverheadCost());

    /*
     * Flat, not per bar and not per tonne: buying twenty times the steel raises the bill by the steel and the
     * handling, never by a second order's worth of overhead.
     */
    $overhead = fn (int $bars): float => $model->cost(
        purchasedMm: 12000 * $bars, scrapMm: 0, kerfMm: 0,
        offcutDraws: [],
        barsOpened: array_fill(0, $bars, ['length' => 12000, 'drop' => 0]),
        cuts: $bars,
        unmadeCuts: 0,
    );

    $perBarCost = $overhead(2) - $overhead(1);

    expect($overhead(3) - $overhead(2))->toBeGreaterThan($perBarCost - 0.01)
        ->and($overhead(3) - $overhead(2))->toBeLessThan($perBarCost + 0.01);
});

it('would be a disaster if freight did not make the yard slower to write a remnant off', function () {
    /**
     * The point of pricing delivery at all. The scrap floor weighs steel against a worker's time, and a
     * worker's time does not get dearer because the truck did - so paying real freight makes every remnant
     * worth more against an unchanged handling cost, and the floor comes down.
     *
     * A business collecting its own steel should be quicker to bin a stub than one paying to have it
     * delivered, and that difference is now in the model rather than in somebody's judgement.
     */
    //Separate instances: coefficients are read off the Business lazily, so one mutated in place would
    //change the model built before the change as well
    $collected = new NestingCostModel(costModelBusiness(), 5.7, 12000);

    $deliveredBusiness = costModelBusiness();
    $deliveredBusiness->delivery_cost_per_tonne = 300.00;
    $delivered = new NestingCostModel($deliveredBusiness, 5.7, 12000);

    expect($delivered->worthRackingFromMm())->toBeLessThan($collected->worthRackingFromMm());

    //The labour of keeping it is untouched - that is the asymmetry doing the work
    expect($delivered->minutesToCost($delivered->offcutRackMinutes(2000)))
        ->toBe($collected->minutesToCost($collected->offcutRackMinutes(2000)));

    //And a business on no freight sees the floors it always saw
    expect($collected->worthRackingFromMm())->toBeGreaterThan(2000);
});

it('would be a disaster if the cost of acquiring steel inflated what an offcut was valued at', function () {
    /**
     * The tempting mistake. A remnant genuinely saves you the freight, the unloading and a share of the
     * order, so valuing it at full replacement cost looks more honest than valuing it at the steel alone.
     *
     * It breaks the model. Retained value must never reach what a millimetre of bar costs to buy, or buying
     * one more millimetre and racking it pays for itself and the nest buys steel in order to bank it. Freight
     * is safe because retention is a SHARE - it scales the racked millimetre and the bought millimetre
     * equally and cancels. Amortised acquisition LABOUR does not cancel: it lifts the value of a racked
     * millimetre against an unchanged purchase cost, and the default receive and order labour over a 12m bar
     * is roughly a 15% uplift, which is more than the 1.5 x cap = 0.9 against 1.0 here has to give.
     *
     * Measured as a finite difference so it holds whatever the valuation is made of, rather than by
     * re-deriving the formula the model already uses.
     */
    $business = costModelBusiness();
    $business->delivery_cost_per_tonne = 400.00;
    $business->delivery_cost_per_order = 120.00;

    foreach ([5.7, 22.9, 90.0] as $kgPerM) {
        $model = new NestingCostModel($business, $kgPerM, 12000);

        //Marginal value of one more racked millimetre, at its steepest - a full stock length
        $marginalRacked = ($model->mmToCost($model->inventoryValueMm(12000))
            - $model->mmToCost($model->inventoryValueMm(11990))) / 10;

        //What one more millimetre of bar costs to buy
        $marginalBought = (float) $business->purchase_cost_weight * $model->mmToCost(1);

        expect($marginalRacked)->toBeLessThan($marginalBought);
    }
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
     * The same 1,200mm offcut, on two sections, asked the way the rule is actually framed: is what the
     * remnant retains worth more than the labour of keeping it?
     *
     * On angle it retains about a dollar against five dollars of marking, recording and shifting, so it is
     * not worth preserving. On a 500UB the same 1,200mm retains seventeen dollars against six, so it is.
     *
     * Note this is NOT the same question as bank-versus-bin at a fixed offcut length. Binning forfeits every
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
it('would be a disaster if an offcut one millimetre over the scrap threshold was still free', function () {
    /**
     * This is the defect the cost model was built for. Scrap was penalised and banked material was not, so
     * a 999mm offcut cost 999 and a 1,001mm offcut cost nothing at all. With a thousand search iterations the
     * solver reliably found the arrangement that landed just past the line, and the rack filled with stubs
     * at the least useful reusable length there is - while the yield figure went up.
     *
     * Two millimetres of difference must not move the cost by anything like two metres of steel.
     */
    $model = new NestingCostModel(costModelBusiness(), 10.0, 12000);

    //A 1,001mm offcut is worth essentially nothing on the retention curve
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
     * smaller than simply writing the offcut off, where the old ranking put the entire value of the steel
     * against zero.
     */
    expect(abs(costOfDrops($model, [999]) - costOfDrops($model, [1001])))
        ->toBeLessThan($model->mmToCost(999));
});

/**
 * Retention
 */
it('would be a disaster if two short offcuts were worth as much as one long one', function () {
    /**
     * One 2,500mm offcut is a more useful offcut than a 1,000mm and a 1,500mm, though the totals are
     * identical. The old ranking reached for this with a separate "largest offcut" tiebreak; here it falls
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
     * Charging the offcut for its own shortness instead of differencing the two values gets this backwards,
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

    //A 50mm offcut is scrap, not inventory
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

it('would be a disaster if a batch paid for one order per product rather than one per merchant', function () {
    /*
     * The fixed cost of buying - the paperwork, and the truck turning up - is charged once to any
     * nest that buys anything (NestingCostModel::cost, step 2a). Within a single product that is
     * right, and it cannot mis-rank a thing: every candidate that buys carries the same charge.
     *
     * Summed across a batch it stops being right. Five products that each buy carried five lots of
     * paperwork for what is one trip to one merchant, and that figure is what lands in
     * batch_measurements.cost - so a batch of many small sections was recorded as having cost most
     * of an hour of admin it never did. The per-product scores stay as the search struck them; the
     * duplication comes off the batch total.
     */
    $business = costModelBusiness();
    $user = createUser(1, $business, false, true);
    $batch = Batch::factory()->forUser($user->id)->create();

    //Two PFC products, both buying new stock, both therefore charged the overhead
    $batch->nested_state = [
        NestingEnums::METERAGE->value => [
            ['product_category' => 'PFC', 'nested' => ['cost' => 400.0, 'totals' => ['newStock' => ['total' => 9000]]]],
            ['product_category' => 'PFC', 'nested' => ['cost' => 300.0, 'totals' => ['newStock' => ['total' => 6000]]]],
        ],
    ];
    $batch->nesting_settings = NestingSettings::inForce($business);
    $batch->save();

    $overhead = (new NestingCostModel($business))->orderOverheadCost();

    expect($overhead)->toBeGreaterThan(0.0)
        //700 summed, less the one duplicated order: both sections come from the same merchant
        ->and($batch->fresh()->nestCost())->toEqualWithDelta(700.0 - $overhead, 0.001);
});

it('leaves the order overhead alone when only one product bought anything', function () {
    /*
     * Nothing is duplicated when nothing was charged twice. The second product here nested
     * entirely out of the offcut rack, so it never paid for an order and there is nothing to take
     * off - and a deduction made on the product COUNT rather than on who actually bought would
     * refund an order this batch really did place.
     */
    $business = costModelBusiness();
    $user = createUser(1, $business, false, true);
    $batch = Batch::factory()->forUser($user->id)->create();

    $batch->nested_state = [
        NestingEnums::METERAGE->value => [
            ['product_category' => 'PFC', 'nested' => ['cost' => 400.0, 'totals' => ['newStock' => ['total' => 9000]]]],
            ['product_category' => 'PFC', 'nested' => ['cost' => 120.0, 'totals' => ['newStock' => ['total' => 0]]]],
        ],
    ];
    $batch->nesting_settings = NestingSettings::inForce($business);
    $batch->save();

    expect($batch->fresh()->nestCost())->toEqualWithDelta(520.0, 0.001);
});
