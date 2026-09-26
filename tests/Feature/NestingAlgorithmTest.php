<?php

use App\Formatters\NestingFormatter;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function nestingBusiness(int $scrapThreshold = 1000, int $kerf = 0): App\Models\Business
{
    $business = createBusiness('biz'.uniqid(), true);
    $business->scrap_threshold_mm = $scrapThreshold;
    $business->kerf_mm = $kerf;
    $business->save();

    return $business;
}

/**
 * @param  array<int, array{0: int, 1: int}>  $lengthAndQty
 */
function nestingCuts(array $lengthAndQty, int $projectId = 1): array
{
    $cuts = [];
    $pieceId = 0;

    foreach ($lengthAndQty as [$length, $qty]) {
        for ($i = 0; $i < $qty; $i++) {
            $cuts[] = ['project' => $projectId, 'piece_id' => ++$pieceId, 'length' => $length];
        }
    }

    return $cuts;
}

function nestingOffcut(int $id, int $length): array
{
    return ['id' => $id, 'length' => $length, 'unique_mark' => 'M'.$id, 'batch_from_id' => 1];
}

/**
 * Offcut selection
 */
it('would be a disaster if an offcut was opened that left a drop too small to reuse', function () {
    /**
     * A 700mm cut, with a 1,500mm and a 12,000mm offcut on the shelf. Taking the first offcut that
     * merely fits burns the 1,500mm and bins the 800mm left over - below the 1,000mm threshold, so it
     * is destroyed. The 12,000mm gives 11,300mm straight back to inventory instead.
     */
    $business = nestingBusiness();

    $result = (new NestingFormatter())->meterageAlgorithm(
        nestingCuts([[700, 1]]),
        [6000],
        [nestingOffcut(1, 1500), nestingOffcut(2, 12000)],
        [1 => 'A'],
        $business,
    );

    $oldStock = $result['totals']['oldStock'];

    expect($oldStock['total'])->toBe(12000)
        ->and($oldStock['used'])->toBe(700)
        ->and($oldStock['scrap'])->toBe(0)
        ->and($oldStock['reusable'])->toBe(11300);
});

it('would be a disaster if the order offcuts came back in changed the nest', function () {
    /**
     * Nothing orders the offcut inventory query for us, and nesting runs twice for the same pieces -
     * once for the suggestion and once when the batch is saved. Both runs have to agree.
     */
    $business = nestingBusiness();
    $formatter = new NestingFormatter();
    $cuts = nestingCuts([[700, 1], [2400, 2]]);

    $inventory = [nestingOffcut(1, 1500), nestingOffcut(2, 12000), nestingOffcut(3, 2600)];

    $first = $formatter->meterageAlgorithm($cuts, [6000], $inventory, [1 => 'A'], $business);
    $second = $formatter->meterageAlgorithm($cuts, [6000], array_reverse($inventory), [1 => 'A'], $business);

    expect($second)->toEqual($first);
});

it('would be a disaster if an offcut was spent on a cut a smaller one covered', function () {
    /**
     * Both offcuts leave a reusable drop, so neither destroys anything - and then the shorter one is
     * the one to cut, keeping the long piece whole for a job that needs it.
     */
    $business = nestingBusiness();

    $result = (new NestingFormatter())->meterageAlgorithm(
        nestingCuts([[500, 1]]),
        [6000],
        [nestingOffcut(1, 9000), nestingOffcut(2, 2000)],
        [1 => 'A'],
        $business,
    );

    expect($result['totals']['oldStock']['total'])->toBe(2000);
});

it('would be a disaster if an offcut was cut up when a bar being bought had the room for free', function () {
    /**
     * A 5,000mm and a 900mm cut, a 12,000mm bar to buy and an 1,800mm offcut on the shelf.
     *
     * The offcut pass used to run once, before the packing, and took every cut it could fit - so the
     * 900 came off the 1,800mm offcut and the 900mm left over was binned, while the 12,000mm bar that
     * was being bought anyway sat with 7,000mm spare. Same purchase either way, 900mm destroyed for
     * nothing.
     */
    $business = nestingBusiness();
    $formatter = new NestingFormatter();

    $result = $formatter->meterageAlgorithm(
        nestingCuts([[5000, 1], [900, 1]]),
        [12000],
        [nestingOffcut(1, 1800)],
        [1 => 'A'],
        $business,
    );

    $totals = $result['totals'];

    //Both cuts came out of the one bar, and the offcut was left on the shelf whole
    expect($totals['newStock']['total'])->toBe(12000)
        ->and($totals['oldStock']['total'])->toBe(0)
        ->and($totals['newStock']['scrap'] + $totals['oldStock']['scrap'])->toBe(0)
        ->and($formatter->effectiveEfficiency($totals))->toBe(100.0);
});

it('would be a disaster if the offcut nesting was not searched along with the packing', function () {
    /**
     * 3,300 + 2,800 + 1,200 against a 4,700mm and a 4,400mm offcut, buying nothing.
     *
     * Taking each cut greedily puts 3,300 in the 4,400 (the shorter one that still leaves a reusable
     * drop), then 2,800 in the 4,700, and the 1,200 into the 1,900mm left of it - binning 700mm. Paired
     * the other way, 3,300 + 1,200 in the 4,700 and 2,800 in the 4,400, only 200mm is destroyed. The
     * offcut pass has to be inside the iterated search to see it.
     */
    $business = nestingBusiness();

    $result = (new NestingFormatter())->meterageAlgorithm(
        nestingCuts([[3300, 1], [2800, 1], [1200, 1]]),
        [6000, 12000],
        [nestingOffcut(1, 4700), nestingOffcut(2, 4400)],
        [1 => 'A'],
        $business,
    );

    $totals = $result['totals'];

    expect($totals['newStock']['total'])->toBe(0)
        ->and($totals['oldStock']['scrap'] + $totals['newStock']['scrap'])->toBe(200);
});

it('would be a disaster if a cut was left unmade because making it destroyed anything', function () {
    /**
     * An 8,000mm cut, 6,000mm the longest stock there is to buy, and an 8,200mm offcut on the shelf.
     * The offcut is the only thing that can hold it, and doing so bins the 200mm left over.
     *
     * Once the search could decline the shelf for a cut, declining looked free next to placing it: no
     * purchase either way and nothing destroyed, so the nest that simply did not make the piece scored
     * best. A cut nothing can hold is not a cheaper nest, so it has to outrank every millimetre.
     */
    $business = nestingBusiness();

    $result = (new NestingFormatter())->meterageAlgorithm(
        nestingCuts([[8000, 1]]),
        [6000],
        [nestingOffcut(1, 8200)],
        [1 => 'A'],
        $business,
    );

    expect($result['tooLong'])->toBeEmpty()
        ->and($result['totals']['oldStock']['used'])->toBe(8000)
        ->and($result['totals']['oldStock']['scrap'])->toBe(200)
        ->and($result['totals']['newStock']['total'])->toBe(0);
});

it('would be a disaster if two offcuts of the same length nested differently by their order', function () {
    /**
     * Nothing orders the offcut inventory query, and the tie between two offcuts of the same length
     * used to be settled by whichever the database returned first - so the nest could name a different
     * bar in the yard on the run that saves the batch than on the one the user approved.
     */
    $business = nestingBusiness();
    $formatter = new NestingFormatter();
    $cuts = nestingCuts([[2500, 2]]);

    $inventory = [nestingOffcut(1, 3000), nestingOffcut(2, 3000), nestingOffcut(3, 5000)];

    $first = $formatter->meterageAlgorithm($cuts, [6000], $inventory, [1 => 'A'], $business);
    $second = $formatter->meterageAlgorithm($cuts, [6000], array_reverse($inventory), [1 => 'A'], $business);

    expect($second)->toEqual($first);
});

/**
 * Efficiency
 */
it('would be a disaster if using an offcut you already own scored worse than buying new', function () {
    /**
     * One 5,000mm cut. Buying a 6,000mm bar leaves 1,000mm; cutting it from an 11,000mm offcut already
     * in the yard leaves 6,000mm. Both drops are banked as offcuts, so neither nest destroys a
     * millimetre - and counting the whole offcut as "purchased material" reported 45.5% against 83.3%,
     * failing the 70% check for reusing your own steel.
     */
    $business = nestingBusiness();
    $formatter = new NestingFormatter();
    $cuts = nestingCuts([[5000, 1]]);

    $withOffcut = $formatter->meterageAlgorithm($cuts, [6000, 9000, 12000], [nestingOffcut(1, 11000)], [1 => 'A'], $business);
    $without = $formatter->meterageAlgorithm($cuts, [6000, 9000, 12000], [], [1 => 'A'], $business);

    $statsWith = $formatter->usageStats(['METERAGE' => [(object) ['nested' => $withOffcut]]]);
    $statsWithout = $formatter->usageStats(['METERAGE' => [(object) ['nested' => $without]]]);

    expect($statsWith['METERAGE']['efficiency'])->toBe(100.0)
        ->and($statsWithout['METERAGE']['efficiency'])->toBe(100.0);

    //Nothing was bought for the offcut nest, and the offcut is reported apart from purchases
    expect($statsWith['METERAGE']['totalPurchasedMaterial'])->toBe(0)
        ->and($statsWith['METERAGE']['totalOffcutMaterial'])->toBe(11000)
        ->and($statsWithout['METERAGE']['totalPurchasedMaterial'])->toBe(6000)
        ->and($statsWithout['METERAGE']['totalOffcutMaterial'])->toBe(0);
});

it('would be a disaster if scrapped steel did not show up in the efficiency figure', function () {
    //Two 4,100mm cuts in a 9,000mm bar leave 800mm, under the threshold, so it is destroyed
    $business = nestingBusiness();
    $formatter = new NestingFormatter();

    $nested = $formatter->meterageAlgorithm(nestingCuts([[4100, 2]]), [9000], [], [1 => 'A'], $business);
    $stats = $formatter->usageStats(['METERAGE' => [(object) ['nested' => $nested]]]);

    //8,200 of 9,000 became pieces and 800 was binned
    expect($stats['METERAGE']['totalScrap'])->toBe(800)
        ->and($stats['METERAGE']['efficiency'])->toBe(91.1)
        ->and($stats['METERAGE']['yield'])->toBe(91.1);
});

/**
 * Packing
 */
it('would be a disaster if a pack that scrapped steel was chosen over one that did not', function () {
    /**
     * 4,300 + 4,300 and 3,700 + 3,700 into two 9,000mm bars leaves 400mm (scrap) and 1,600mm.
     * Pairing 4,300 + 3,700 twice buys exactly the same two bars at exactly the same used/purchased
     * ratio, and destroys nothing. Ranking runs on used/purchased alone could not tell them apart.
     *
     * There is one purchasable length here on purpose. Random runs used to vary only which stock length
     * opened a new bar, so with nothing to vary every iteration packed the cuts the same way and the
     * search could never improve on its own first attempt.
     */
    $business = nestingBusiness();

    $result = (new NestingFormatter())->meterageAlgorithm(
        nestingCuts([[4300, 2], [3700, 2]]),
        [9000],
        [],
        [1 => 'A'],
        $business,
    );

    $newStock = $result['totals']['newStock'];

    expect($newStock['total'])->toBe(18000)
        ->and($newStock['scrap'])->toBe(0)
        ->and($newStock['reusable'])->toBe(2000);
});

it('would be a disaster if the nest never tried one stock length throughout', function () {
    /**
     * Twelve 4,000mm cuts, with 6,000mm and 12,000mm stock. Three fit a 12,000mm bar exactly, so four
     * bars cover the lot with nothing left over - 48,000mm.
     *
     * The search draws each bar's length on its own, so the chance of it landing on 12,000mm four times
     * running is slim, and the only deterministic run opened the shortest bar that held the cut in hand:
     * twelve 6,000mm bars, 72,000mm bought and 24,000mm of drop. Packing every bar to one length has to
     * be a candidate in its own right.
     */
    $business = nestingBusiness();

    $result = (new NestingFormatter())->meterageAlgorithm(
        nestingCuts([[4000, 12]]),
        [6000, 12000],
        [],
        [1 => 'A'],
        $business,
    );

    $newStock = $result['totals']['newStock'];

    expect($newStock['total'])->toBe(48000)
        ->and($newStock['used'])->toBe(48000)
        ->and($newStock['unused'])->toBe(0);
});

it('would be a disaster if a bar was bought longer than what ended up in it needed', function () {
    /**
     * A bar's length was chosen when it was opened, off the first cut that went into it, and nothing
     * revisited it once the rest of the nest was known - so a 12,000mm bar opened for one 4,000mm cut
     * stayed 12,000mm even when nothing else joined it.
     *
     * The invariant: every bar in the finished nest is the shortest purchasable length that holds what
     * is in it.
     */
    $business = nestingBusiness();
    $stockLengths = [6000, 9000, 12000];

    $result = (new NestingFormatter())->meterageAlgorithm(
        nestingCuts([[5800, 3], [4200, 2], [2600, 4], [1300, 3]]),
        $stockLengths,
        [],
        [1 => 'A'],
        $business,
    );

    foreach ($result['utilisedBars'] as $bar) {
        $consumed = $bar['result']['kerf'] + array_sum(array_column($bar['result']['pieces'], 'cutLength'));

        $shortestThatHolds = null;
        foreach ($stockLengths as $stockLength) {
            if ($stockLength >= $consumed) {
                $shortestThatHolds = $stockLength;
                break;
            }
        }

        expect($bar['result']['bar_length'])->toBe(
            $shortestThatHolds,
            "a {$bar['result']['bar_length']}mm bar holds only {$consumed}mm of cuts",
        );
    }
});

it('would be a disaster if buying more steel was called an improvement', function () {
    /**
     * 2x 7,000 and 7x 1,700 come to 25,900mm. Three 9,000mm bars cover it for 27,000mm with 800mm of
     * scrap; avoiding that scrap costs a fourth bar. Purchase is the line the business actually pays,
     * so it decides first and scrap only settles ties.
     */
    $business = nestingBusiness();

    $result = (new NestingFormatter())->meterageAlgorithm(
        nestingCuts([[7000, 2], [1700, 7]]),
        [9000, 12000],
        [],
        [1 => 'A'],
        $business,
    );

    expect($result['totals']['newStock']['total'])->toBe(27000);
});

/**
 * Fractional lengths
 */
it('would be a disaster if half a millimetre was quietly cut off every piece', function () {
    /**
     * A piece length is a string fed from a float, so "2999.5" is real data. Truncating it packed four
     * of them into a 12,000mm bar as if they were 2,999mm, and every piece came out short.
     */
    $business = nestingBusiness();

    $result = (new NestingFormatter())->meterageAlgorithm(
        [
            ['project' => 1, 'piece_id' => 1, 'length' => '2999.5'],
            ['project' => 1, 'piece_id' => 2, 'length' => '2999.5'],
            ['project' => 1, 'piece_id' => 3, 'length' => '2999.5'],
            ['project' => 1, 'piece_id' => 4, 'length' => '2999.5'],
        ],
        [12000],
        [],
        [1 => 'A'],
        $business,
    );

    //Rounded up, never down: 4x 3,000 is exactly a 12,000mm bar
    $cuts = [];
    foreach ($result['utilisedBars'] as $bar) {
        for ($i = 1; $i <= $bar['count']; $i++) {
            foreach ($bar['result']['pieces'] as $piece) {
                $cuts[] = $piece['cutLength'];
            }
        }
    }

    expect($cuts)->toBe([3000, 3000, 3000, 3000])
        ->and($result['totals']['newStock']['total'])->toBe(12000)
        ->and($result['totals']['newStock']['unused'])->toBe(0);
});

/**
 * Saw kerf
 */
it('would be a disaster if the nest left no room for the saw blade', function () {
    /**
     * 4x 3,000mm reads as a perfect fit in a 12,000mm bar, but every cut costs a blade width. With a
     * 3mm kerf the fourth piece does not come out of that bar.
     */
    $business = nestingBusiness(1000, 3);

    $result = (new NestingFormatter())->meterageAlgorithm(
        nestingCuts([[3000, 4]]),
        [12000],
        [],
        [1 => 'A'],
        $business,
    );

    $newStock = $result['totals']['newStock'];

    //Three cuts and their kerf fit a 12,000mm bar; the fourth opens another
    expect($newStock['total'])->toBe(24000)
        ->and($newStock['used'])->toBe(12000)
        ->and($newStock['kerf'])->toBe(12);

    //Every bar still balances: bar_length = cuts + kerf + drop
    foreach ($result['utilisedBars'] as $bar) {
        $cuts = array_sum(array_column($bar['result']['pieces'], 'cutLength'));

        expect($cuts + $bar['result']['kerf'] + $bar['result']['unused'])
            ->toBe($bar['result']['bar_length']);
    }
});

it('would be a disaster if a kerf of zero changed the nest at all', function () {
    //The default, and what every existing nest was approved against
    $business = nestingBusiness(1000, 0);

    $result = (new NestingFormatter())->meterageAlgorithm(
        nestingCuts([[3000, 4]]),
        [12000],
        [],
        [1 => 'A'],
        $business,
    );

    expect($result['totals']['newStock']['total'])->toBe(12000)
        ->and($result['totals']['newStock']['kerf'])->toBe(0)
        ->and($result['totals']['newStock']['unused'])->toBe(0);
});

/**
 * Checks
 */
it('would be a disaster if the nesting checks failed on a correct nest', function () {
    $business = nestingBusiness();
    $formatter = new NestingFormatter();

    //A fractional length, an offcut that leaves a reusable remainder, and a bar consumed exactly
    $nested = $formatter->meterageAlgorithm(
        [
            ['project' => 1, 'piece_id' => 1, 'length' => '2999.5'],
            ['project' => 1, 'piece_id' => 2, 'length' => '3000'],
            ['project' => 1, 'piece_id' => 3, 'length' => '6000'],
            ['project' => 1, 'piece_id' => 4, 'length' => '4000'],
        ],
        [6000, 9000, 12000],
        [nestingOffcut(1, 11000)],
        [1 => 'A'],
        $business,
    );

    $product = (object) [
        'product_category' => 'PFC',
        'purchasableLengths' => [6000, 9000, 12000],
        'pieces' => [
            ['length' => '2999.5', 'quantity' => 1],
            ['length' => '3000', 'quantity' => 1],
            ['length' => '6000', 'quantity' => 1],
            ['length' => '4000', 'quantity' => 1],
        ],
        'nested' => $nested,
    ];

    $piecesNested = ['METERAGE' => [$product]];
    $checks = $formatter->checks($piecesNested, $formatter->usageStats($piecesNested), $business);

    /*
     * offcuts_qty is left out: it re-reads the offcut inventory out of the database, and the offcut
     * nested here is a fixture rather than a row that a delivered order made available.
     */
    unset($checks['offcuts_qty']);

    foreach ($checks as $key => $check) {
        expect($check['result'])->toBeTrue("check '{$key}' ({$check['description']}) failed");
    }
});

it('would be a disaster if a bar packed past its own length passed the checks', function () {
    /**
     * Check 7 only ever looked at the offcut bars, so an overfilled bar of new stock - the thing the
     * algorithm actually builds - went unreported.
     */
    $business = nestingBusiness();
    $formatter = new NestingFormatter();

    $nested = $formatter->meterageAlgorithm(nestingCuts([[4000, 2]]), [9000], [], [1 => 'A'], $business);

    //Overfill one bar by hand
    $nested['utilisedBars'][0]['result']['pieces'][] = [
        'cutLength' => 8000,
        'projectId' => 1,
        'letter' => 'A',
    ];

    $product = (object) [
        'product_category' => 'PFC',
        'purchasableLengths' => [9000],
        'pieces' => [['length' => '4000', 'quantity' => 2]],
        'nested' => $nested,
    ];

    $piecesNested = ['METERAGE' => [$product]];
    $checks = $formatter->checks($piecesNested, $formatter->usageStats($piecesNested), $business);

    expect($checks['cuts_within_bar']['result'])->toBeFalse();
});

/**
 * Bundle
 */
it('would be a disaster if bundle nesting bought more packs than it had to', function () {
    //110 off from packs of 100 and 60: two 60s cover it for 120, a 100 and a 60 costs 160
    $result = (new NestingFormatter())->bundleAlgorithm(110, [100, 60]);

    expect($result['totalBought'])->toBe(120)
        ->and($result['boxes'])->toBe([100 => 0, 60 => 2]);
});

it('would be a disaster if bundle nesting reached exactly when it could', function () {
    $result = (new NestingFormatter())->bundleAlgorithm(130, [100, 30]);

    expect($result['totalBought'])->toBe(130)
        ->and($result['efficiency'])->toEqual(100);
});

it('would be a disaster if a repeated pack size made bundle nesting order nothing', function () {
    /**
     * Pack counts are keyed by size, so a size appearing twice overwrote its own count with a later
     * zero - and the caller was told to buy nothing, or less than was asked for.
     */
    expect((new NestingFormatter())->bundleAlgorithm(50, [100, 25, 25])['totalBought'])->toBe(50);
    expect((new NestingFormatter())->bundleAlgorithm(7, [5, 5])['totalBought'])->toBe(10);
});

it('would be a disaster if bundle nesting ever bought less than was asked for', function () {
    $formatter = new NestingFormatter();

    foreach ([[7, [5]], [1, [100]], [101, [100, 60]], [59, [60, 100]], [250, [100, 25]]] as [$qty, $packs]) {
        expect($formatter->bundleAlgorithm($qty, $packs)['totalBought'])
            ->toBeGreaterThanOrEqual($qty, "qty {$qty} from packs ".implode(',', $packs));
    }
});

/**
 * Purchasable stock
 */
it("would be a disaster if a nest was built on another business's stock lengths", function () {
    $business = nestingBusiness();
    $otherBusiness = nestingBusiness();

    $spec = [
        'product_category' => 'PFC',
        'material' => 'PLAIN CARBON STEEL',
        'grade' => 'GR300',
        'surface' => 'NONE',
        'nominal_height' => '200',
    ];

    $columns = ['description' => '200PFC', 'nesting_algo' => 'METERAGE'];

    //Shared by everyone
    Product::create($spec + $columns + ['nominal_length' => '9000', 'business_id' => null, 'deprecated' => false]);
    //Private to someone else
    Product::create($spec + $columns + ['nominal_length' => '10500', 'business_id' => $otherBusiness->id, 'deprecated' => false]);
    //Retired
    Product::create($spec + $columns + ['nominal_length' => '11000', 'business_id' => null, 'deprecated' => true]);

    $lengths = (new NestingFormatter())->getPurchasableVariations($spec, 'METERAGE', $business);

    expect($lengths)->toBe([9000]);
});
