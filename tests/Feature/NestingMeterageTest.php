<?php

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
    $this->get(route('admin.update.master.materials.spreadsheet'));

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
    $this->get(route('admin.update.master.materials.spreadsheet'));

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
