<?php

use App\Formatters\NestingFormatter;
use App\Services\DataClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it("would be a disaster if total nested length didn't equal total pieces length", function (int $testCaseIndex) {
    //Create admin
    $adminBusiness = createBusiness('admin', true);
    $adminUser = createUser(1, $adminBusiness, true, true);

    //Authorised
    $this->actingAs($adminUser);

    //Create project
    $project = createProject($adminUser, true);

    //Nesting
    $nest = nestingTestCases()[$testCaseIndex]['nest']; //length vs qty array

    //Seed master_product.csv to create products
    seedMasterMaterials();

    //Service
    $dataClassificationService = new dataClassificationService;

    //Create BOM
    $sampleBOM = sampleBOM($project, $dataClassificationService, $nest);

    //Create raw material quotes & pieces
    $pieces = createPieces($sampleBOM, $project, $dataClassificationService);

    /*
     * total Pieces Length
     */
    $totalPiecesLength = 0;
    foreach($nest as $item){
        $length = $item[0];
        $qty = $item[1];
        $totalPiecesLength = $totalPiecesLength + ($qty * $length);
    }

    $response = $this->get(route('suggested.nesting'));
    $response->assertStatus(200);

    //$response->assertInertia(fn (Assert $page) => dd($page));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('usage', function (Assert $page) use ($totalPiecesLength) {
            $page->where('METERAGE.totalUsedMaterial', $totalPiecesLength);
            //Every algo reports a block whether or not this nest has any of it in
            $page->where('BUNDLE.totalBought', 0);
        })
    );
})->with(range(0, count(nestingTestCases()) - 1)); //This runs each test case index. e.g [0,1,2,3]

it("would be a disaster if 'pieces too long' for meterage nesting was not working correctly", function () {
    /**
     * pieces.0.0.tooLong
     */
});

it("would be a disaster if meterage nesting for a single project didn't work correctly", function (int $testCaseIndex) {
    /*
     * Create admin & seed materials
     */
    $adminBusiness = createBusiness('admin', true);
    $adminUser = createUser(1, $adminBusiness, true, true);

    //Seed master_product.csv to create products
    $this->actingAs($adminUser);
    seedMasterMaterials();

    /*
     * Create user
     */
    $userBusiness = createBusiness('greg', true);
    $user = createUser(2, $userBusiness, false, true);
    $this->actingAs($user);

    //Create project
    $project = createProject($user, true);

    //Nesting
    $nest = nestingTestCases()[$testCaseIndex]['nest']; //length vs qty array
    $expectedResult = nestingTestCases()[$testCaseIndex]['result'];

    //Service
    $dataClassificationService = new dataClassificationService;

    //Create BOM
    $sampleBOM = sampleBOM($project, $dataClassificationService, $nest);

    //Create raw material quotes & pieces
    $pieces = createPieces($sampleBOM, $project, $dataClassificationService);
    expect(count($pieces))->toBeGreaterThan(0);

    //Qty of pieces
    $qtyPieces = 0;
    foreach ($nest as $items) {
        $qtyPieces = $qtyPieces + $items[1];
    }
    expect($qtyPieces)->toEqual(nestingTestCases()[$testCaseIndex]["qtyPieces"]);

    $response = $this->get(route('suggested.nesting'));
    $response->assertStatus(200);

    /**
     * The nesting pieces accumulate when looping nest cases
     */
    //$response->assertInertia(fn (Assert $page) => dd($page));

    $response->assertInertia(fn (Assert $page) => $page
        ->count('pieces.METERAGE', 1) //1 batch parsed to the view
        ->count('pieces.METERAGE.0.pieces', count($nest))  //Piece groups. e.g 2x5000, 3x1000 is 2 piece groups
        ->count('pieces.METERAGE.0.purchasableLengths', 2)    //4 different lengths
        ->has('pieces', function (Assert $page) use ($expectedResult) {
            //Each stock bar
            foreach ($expectedResult as $index => $bar) {
                $page->where('METERAGE.0.nested.utilisedBars.'.$index.'.count', $bar['count']);
                $page->where('METERAGE.0.nested.utilisedBars.'.$index.'.result.bar_length', (int) $bar['bar_length']);
                $page->where('METERAGE.0.nested.utilisedBars.'.$index.'.result.unused', $bar['unused']);
                foreach ($bar['pieces'] as $pieceIndex => $piece) {
                    $page->where('METERAGE.0.nested.utilisedBars.'.$index.'.result.pieces.'.$pieceIndex.'.cutLength', $piece);
                }
            }
        })
    );
})->with(range(0, count(nestingTestCases()) - 1)); //This runs each test case index. e.g [0,1,2,3]

//todo more

it('would be a disaster if a small drop on every bar was counted as reusable stock', function () {
    /**
     * Reusable vs scrap is a property of the individual drop, not of the total.
     *
     * Six 4,300mm cuts fill three 9,000mm bars with 400mm left on each. 400mm is below the 1,000mm
     * scrap threshold, so all three drops are scrap and no offcut is banked. Summing the drops first
     * (1,200mm) and then testing the total against the threshold reported them all as reusable.
     */
    $business = createBusiness('biz', true);
    $business->scrap_threshold_mm = 1000;
    $business->save();

    $cuts = [];
    for ($i = 1; $i <= 6; $i++) {
        $cuts[] = ['project' => 1, 'piece_id' => $i, 'length' => 4300];
    }

    $result = (new NestingFormatter())->meterageAlgorithm($cuts, [9000], [], [1 => 'A'], $business);

    $newStock = $result['totals']['newStock'];

    expect($newStock['unused'])->toBe(1200)
        ->and($newStock['reusable'])->toBe(0)
        ->and($newStock['scrap'])->toBe(1200);

    //And what the totals claim is reusable must be what CreateBarsAndOffcuts actually banks
    $bankable = array_filter(
        $result['utilisedBars'],
        fn ($bar) => $bar['result']['unused'] >= $bar['result']['scrap_threshold_mm'],
    );
    expect($bankable)->toBeEmpty();
});

it('would be a disaster if a drop big enough to reuse was counted as scrap', function () {
    $business = createBusiness('biz', true);
    $business->scrap_threshold_mm = 1000;
    $business->save();

    //One 6,000mm cut in a 9,000mm bar leaves 3,000mm - well over the threshold
    $result = (new NestingFormatter())->meterageAlgorithm(
        [['project' => 1, 'piece_id' => 1, 'length' => 6000]],
        [9000],
        [],
        [1 => 'A'],
        $business,
    );

    $newStock = $result['totals']['newStock'];

    expect($newStock['reusable'])->toBe(3000)
        ->and($newStock['scrap'])->toBe(0);
});

it('would be a disaster if nesting runs were indexed by their efficiency', function () {
    /**
     * Results used to be collected into an array keyed by efficiency. Efficiency is a float, and PHP
     * truncates float array keys to int - so 96.9% and 96.1% collided on key 96, the better run was
     * overwritten by whichever ran last, and every write emitted a deprecation.
     *
     * Catching the deprecation is what pins this down: as long as no float reaches an array key, runs
     * are being compared by value rather than bucketed into whole percents.
     */
    $business = createBusiness('biz', true);
    $business->scrap_threshold_mm = 1000;
    $business->save();

    $deprecations = [];
    set_error_handler(function ($level, $message) use (&$deprecations) {
        if (str_contains($message, 'Implicit conversion from float')) {
            $deprecations[] = $message;
        }

        return true;
    }, E_DEPRECATED);

    $cuts = [];
    foreach ([4300, 2750, 1900, 6100, 3300, 5200, 2100] as $index => $length) {
        $cuts[] = ['project' => 1, 'piece_id' => $index + 1, 'length' => $length];
    }

    try {
        (new NestingFormatter())->meterageAlgorithm($cuts, [6000, 9000, 12000], [], [1 => 'A'], $business);
    } finally {
        restore_error_handler();
    }

    expect($deprecations)->toBeEmpty();
});

it('would be a disaster if the nest chosen was not the best one available', function () {
    /**
     * 4x 3,000mm nests perfectly into one 12,000mm bar. Taking the smallest bar that fits each cut
     * instead ("best fit") needs two 10,000mm bars for the same pieces - 60% efficiency.
     */
    $business = createBusiness('biz', true);
    $business->scrap_threshold_mm = 1000;
    $business->save();

    $cuts = [];
    for ($i = 1; $i <= 4; $i++) {
        $cuts[] = ['project' => 1, 'piece_id' => $i, 'length' => 3000];
    }

    $result = (new NestingFormatter())->meterageAlgorithm($cuts, [10000, 12000], [], [1 => 'A'], $business);

    $newStock = $result['totals']['newStock'];

    expect($newStock['total'])->toBe(12000)
        ->and($newStock['unused'])->toBe(0);
});

it('would be a disaster if nesting the same pieces twice gave two different answers', function () {
    /**
     * The suggested nesting screen and Actions/Batch/SaveNesting nest the same pieces independently.
     * If the algorithm is not deterministic the batch is ordered against a cut plan nobody approved.
     */
    $business = createBusiness('biz', true);
    $business->scrap_threshold_mm = 1000;
    $business->save();

    $cuts = [];
    foreach ([4300, 2750, 1900, 6100, 3300, 5200, 2100] as $index => $length) {
        $cuts[] = ['project' => 1, 'piece_id' => $index + 1, 'length' => $length];
    }

    $formatter = new NestingFormatter();
    $stockLengths = [6000, 9000, 12000];

    $first = $formatter->meterageAlgorithm($cuts, $stockLengths, [], [1 => 'A'], $business);
    $second = $formatter->meterageAlgorithm($cuts, $stockLengths, [], [1 => 'A'], $business);

    expect($second)->toEqual($first);
});
