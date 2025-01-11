<?php

use App\Services\DataClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it("would be a disaster if total nested length didn't equal total pieces length" , function () {

});

it('would be a disaster if unfit cuts for meterage nesting was not working correctly', function () {
    /**
     * pieces.0.0.unfitCuts
     */
});

it("would be a disaster if meterage nesting for a single project didn't work correctly", function (int $testCaseIndex) {
    /**
     * Test that 5 items in BOM are successfully nested
     */
    //Create admin
    $adminBusiness = createBusiness("admin", true);
    $adminUser = createUser(1, $adminBusiness, true,true);

    //Authorised
    $this->actingAs($adminUser);

    //Create project
    $project = createProject($adminUser,true);

    $nest = nestingTestCases()[$testCaseIndex]["nest"];
    $result = nestingTestCases()[$testCaseIndex]["result"];

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Service
    $dataClassificationService = new dataClassificationService();

    //Create BOM
    $sampleBOM = sampleBOM($project,$dataClassificationService,$nest);

    //Create raw material quotes & pieces
    $pieceGroups = createPieces($sampleBOM,$project,$dataClassificationService);
    expect(count($pieceGroups))->toBeGreaterThan(0);

    $qtyPieces = 0;
    foreach($nest as $items){
        $qtyPieces = $qtyPieces + $items[1];
    }

    $response = $this->get(route("quotes.index"));
    $response->assertStatus(200);

    /**
     * The nesting pieces accumulate when looping nest cases
     */
    $response->assertInertia(fn (Assert $page) => $page
        ->count('pieces',1) //1 batch parsed to the view
        ->count('pieces.0.0.pieces',count($nest))  //Piece groups. e.g 2x5000, 3x1000 is 2 piece groups
        ->count('pieces.0.0.purchasable',4)    //4 different lengths
        ->has('pieces', function (Assert $page) use($result){
            //Each stock bar
            foreach($result as $index => $bar){
                $page->where('0.0.nested.usedStockBars.'.$index.'.count', $bar["count"]);
                $page->where('0.0.nested.usedStockBars.'.$index.'.result.stock_length', $bar["stock_length"]);
                $page->where('0.0.nested.usedStockBars.'.$index.'.result.waste', $bar["waste"]);
                foreach($bar["pieces"] as $pieceIndex => $piece){
                    $page->where('0.0.nested.usedStockBars.'.$index.'.result.pieces.'.$pieceIndex.'.cutLength', $piece);
                }
            }
        })
    );
})->with(range(0, count(nestingTestCases()) - 1)); //This runs each test case index. e.g [0,1,2,3]

//todo more


