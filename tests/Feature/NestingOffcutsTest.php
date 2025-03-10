<?php

use App\Models\Batch;
use App\Models\Offcut;
use App\Services\DataClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('would be a disaster if meterage nesting with an offcut was not working correctly', function (int $testCaseIndex) {
    /*
     * Create admin & seed materials
     */
    $adminBusiness = createBusiness('admin', true);
    $adminUser = createUser(1, $adminBusiness, true, true);

    //Seed master_product.csv to create products
    $this->actingAs($adminUser);
    $this->get(route('admin.update.master.materials.spreadsheet'));

    /*
     * Business/User #1
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $this->actingAs($user);
    $project = createProject($user, true);

    //batch & offcuts
    $batchFrom = Batch::factory()->forUser($user->id)->create();
    $offcutLengths = nestingTestCasesWithOffcuts()[$testCaseIndex]['offcuts'];
    $expectedQtyOffcutsUsed = nestingTestCasesWithOffcuts()[$testCaseIndex]['expectedQtyOffcutsUsed'];
    foreach($offcutLengths as $offcutLength){
        create_offcut_200PFC($offcutLength,$batchFrom->id);
    }

    //Nesting
    $nest = nestingTestCasesWithOffcuts()[$testCaseIndex]['nest']; //length vs qty array
    $expectedResult = nestingTestCasesWithOffcuts()[$testCaseIndex]['result'];

    //Service
    $dataClassificationService = new dataClassificationService;

    //Create BOM (based on 200PFC)
    $sampleBOM = sampleBOM($project, $dataClassificationService, $nest);

    //Create raw material quotes & pieces
    $pieces = createPieces($sampleBOM, $project, $dataClassificationService);

    //View
    $response = $this->get(route('suggested.nesting'));
    $response->assertStatus(200);

    /**
     * The nesting pieces accumulate when looping nest cases
     */

    //$response->assertInertia(fn (Assert $page) => dd($page));

    $response->assertInertia(fn (Assert $page) => $page
        ->count('pieces.METERAGE', 1) //1 batch parsed to the view
        ->count('pieces.METERAGE.0.pieces', count($nest))  //Piece groups. e.g 2x5000, 3x1000 is 2 piece groups
        ->count('pieces.METERAGE.0.nested.bestResultOffcuts.utilisedOffcutBars',$expectedQtyOffcutsUsed)
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

it('would be a disaster if using offcuts that are allocated to another batch', function () {
    /*
     * Business/User #1
     */
    $business1 = createBusiness('biz1', true);
    $user1 = createUser(1, $business1, false, true);
    $this->actingAs($user1);
    $project1 = createProject($user1, true);
    $batch1 = Batch::factory()->forUser($user1->id)->create();
    $testOffcut1 = Offcut::factory()
        ->withBatchFrom($batch1->id)
        ->withBatchTo(null)
        ->withPieceTo(null)
        ->withLength(2000)
        ->create();

    /*
     * Business/User #2
     */
    $business2 = createBusiness('biz2', true);
    $user2 = createUser(2, $business2, false, true);
    $this->actingAs($user2);
    $project2 = createProject($user2, true);
    $batch2 = Batch::factory()->forUser($user2->id)->create();



});

it("would be a disaster if offuct of an offcut didn't work", function () {
    //Create admin
    $adminBusiness = createBusiness('admin', true);
    $adminUser = createUser(1, $adminBusiness, true, true);

    //Authorised
    $this->actingAs($adminUser);

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Create admin
    $business = createBusiness('admin', true);
    $user = createUser(1, $business, false, true);

    //Create project
    $project = createProject($user);

    //Nesting
    $nest = nestingTestCases()[0]['nest']; //length vs qty array

    //Service
    $dataClassificationService = new dataClassificationService;

    //Create BOM
    $sampleBOM = sampleBOM($project, $dataClassificationService, $nest);

    //Create raw material quotes & pieces
    $pieces = createPieces($sampleBOM, $project, $dataClassificationService);

    //todo save nesting


    dd($adminBusiness->availableOffcuts()->get());
});

it('would be a disaster if using offcuts that belong to another company', function () {});

it('would be a disaster if offcuts not added to inventory', function () {});

it('would be a disaster if using an offcut twice in the same project', function () {});

it("would be a disaster if a cancelled order doesn't release offcuts back to available status", function () {});

//todo more
