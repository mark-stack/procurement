<?php

use App\Formatters\NestingFormatter;
use App\Models\Bar;
use App\Models\Batch;
use App\Models\Offcut;
use App\Models\Order;
use App\Models\Piece;
use App\Models\Quote;
use App\Models\Scrap;
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

    /*
     * batch
     */
    $batchFrom = Batch::factory()->forUser($user->id)->create();
    $deliveredQuote = Quote::create([
        'user_id' => $user->id,
        'batch_id' => $batchFrom->id,
        'supplier_id' => null,
        'supplier_category' => "STEEL_MERCHANT",
        'supplier_quote_reference' => null,
        'quote_sent' => true,
        'quoted_price' => null,
        'quoted_lead_time' => null,
    ]);
    $deliveredOrder = Order::create([
        'user_id' => $user->id,
        'batch_id' => $batchFrom->id,
        'supplier_id' => null,
        'quote_id' => $deliveredQuote->id,
        'order_sent' => true,
        'order_confirmation_received' => true,
        'purchase_order_number' => "123",
        'is_delivered' => true,
        "material_cert_numbers" => null,
    ]);

    /*
     * offcuts
     */
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

it("would be a disaster if offcut of an offcut didn't work", function () {
    /**
     * Nesting case #1 has 2500mm of reusable material
     *
     *   1 of 9000: 2500|2500|2500|1500 (0 waste)
     *   1 of 9000: 2500|2500|1500 (2500 waste)
     */
    //Services
    $dataClassificationService = new dataClassificationService;
    $nestingFormatter = new NestingFormatter();

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

    //Nesting case
    $nest_1 = nestingTestCases()[0]['nest']; //length vs qty array

    //Create BOM
    $sampleBOM_1 = sampleBOM($project, $dataClassificationService, $nest_1);

    //Create raw material quotes & pieces
    createPieces($sampleBOM_1, $project, $dataClassificationService);

    /*
     * Save nesting
     */
    //Pieces ready for batching
    $piecesReadyForBatching = $nestingFormatter->piecesReadyForBatching($business);
    expect(count($piecesReadyForBatching))->toBeGreaterThan(0);

    $this->actingAs($user);
    $this->post(route('quotes.store'));

    /*
     * Order and mark as delivered (this makes offcuts available)
     */
    $batchFrom = Batch::first();
    $deliveredQuote = Quote::create([
        'user_id' => $user->id,
        'batch_id' => $batchFrom->id,
        'supplier_id' => null,
        'supplier_category' => "STEEL_MERCHANT",
        'supplier_quote_reference' => null,
        'quote_sent' => true,
        'quoted_price' => null,
        'quoted_lead_time' => null,
    ]);
    $deliveredOrder = Order::create([
        'user_id' => $user->id,
        'batch_id' => $batchFrom->id,
        'supplier_id' => null,
        'quote_id' => $deliveredQuote->id,
        'order_sent' => true,
        'order_confirmation_received' => true,
        'purchase_order_number' => "123",
        'is_delivered' => true,
        "material_cert_numbers" => null,
    ]);

    //Check offcut created
    $offcuts = Offcut::all();
    expect($offcuts->count())->toEqual(1);
    expect($offcuts[0]->length)->toEqual(2500);
    expect($business->availableOffcuts()->count())->toEqual(1);
    expect(Bar::count())->toEqual(2);

    /**
     * Run case #1 again but using the 2500 offcut. The result will be 5,000 reusable
     *
     *   From offcut: 2500 from 2500
     *   1 of 9000: 2500|2500|2500|1500 (0 waste)
     *   1 of 9000: 2500|1500 (5000 waste)
     */
    //Nesting case
    $nest_2 = nestingTestCases()[0]['nest']; //length vs qty array

    //Create BOM
    $sampleBOM_2 = sampleBOM($project, $dataClassificationService, $nest_2);

    //Create raw material quotes & pieces
    createPieces($sampleBOM_2, $project, $dataClassificationService);

    /*
     * Save nesting
     */
    //Pieces ready for batching
    $piecesReadyForBatching = $nestingFormatter->piecesReadyForBatching($business);
    expect(count($piecesReadyForBatching))->toBeGreaterThan(0);

    $this->actingAs($user);
    $this->post(route('quotes.store'));

    //Check offcut created
    $offcuts = Offcut::all();
    expect($offcuts->count())->toEqual(2);
    expect($offcuts[1]->length)->toEqual(5000);
    expect($offcuts[1]->batch_from_id)->toEqual(2);
    expect($offcuts[1]->batch_to_id)->toBeNull();
    expect(Bar::count())->toEqual(2+2);

    //todo hack because testing is weird: change 'batch_to_id' to 1. $business->availableOffcuts() is not updating properly
    Offcut::query()
        ->where("batch_from_id",2)
        ->update([
            "batch_from_id" => 1
        ]);

    /**
     * Run case #1 again but using the 5000mm offcut. The result will be 1500 offcut
     *
     *   From offcut: 2500|2500 from 5000

     *   1 of 12000: 2500|2500|2500|1500|1500 (1500 waste)
     */
    //Nesting case
    $nest_3 = nestingTestCases()[0]['nest']; //length vs qty array

    //Create BOM
    $sampleBOM_3 = sampleBOM($project, $dataClassificationService, $nest_3);

    //Create raw material quotes & pieces
    createPieces($sampleBOM_3, $project, $dataClassificationService);

    /*
     * Save nesting
     */
    //Pieces ready for batching
    $piecesReadyForBatching = $nestingFormatter->piecesReadyForBatching($business);
    expect(count($piecesReadyForBatching))->toBeGreaterThan(0);

    $this->actingAs($user);
    $this->post(route('quotes.store'));

    //Check offcut created
    $offcuts = Offcut::all();
    expect(Bar::count())->toEqual(2+2+1); //The 3rd round used 1 less bar

    expect($offcuts->count())->toEqual(3);
    expect($offcuts[2]->length)->toEqual(1500);
    expect($offcuts[2]->batch_from_id)->toEqual(3);
    expect($offcuts[2]->batch_to_id)->toBeNull();
});

it('would be a disaster if using offcuts that belong to another company', function () {
    /*
     * Create admin & seed materials
     */
    $adminBusiness = createBusiness('admin', true);
    $adminUser = createUser(1, $adminBusiness, true, true);
    $this->actingAs($adminUser);
    $this->get(route('admin.update.master.materials.spreadsheet'));

    /*
     * Business #1 has a delivered batch with a 9,000mm offcut sitting in its inventory
     */
    $business1 = createBusiness('biz1', true);
    $user1 = createUser(2, $business1, false, true);
    $this->actingAs($user1);

    $batchFrom = Batch::factory()->forUser($user1->id)->create();
    $deliveredQuote = Quote::create([
        'user_id' => $user1->id,
        'batch_id' => $batchFrom->id,
        'supplier_id' => null,
        'supplier_category' => "STEEL_MERCHANT",
        'supplier_quote_reference' => null,
        'quote_sent' => true,
        'quoted_price' => null,
        'quoted_lead_time' => null,
    ]);
    Order::create([
        'user_id' => $user1->id,
        'batch_id' => $batchFrom->id,
        'supplier_id' => null,
        'quote_id' => $deliveredQuote->id,
        'order_sent' => true,
        'order_confirmation_received' => true,
        'purchase_order_number' => "123",
        'is_delivered' => true,
        "material_cert_numbers" => null,
    ]);
    create_offcut_200PFC(9000, $batchFrom->id);

    //It really is available to its owner
    expect($business1->availableOffcuts()->count())->toEqual(1);

    /*
     * Business #2 nests pieces that would fit that offcut perfectly
     */
    $business2 = createBusiness('biz2', true);
    $user2 = createUser(3, $business2, false, true);
    $this->actingAs($user2);
    $project2 = createProject($user2);

    $dataClassificationService = new dataClassificationService;
    $sampleBOM = sampleBOM($project2, $dataClassificationService, nestingTestCases()[0]['nest']);
    createPieces($sampleBOM, $project2, $dataClassificationService);

    //Another company's offcut is not inventory this business can nest into
    expect($business2->availableOffcuts()->count())->toEqual(0);

    $response = $this->get(route('suggested.nesting'));
    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->count('pieces.METERAGE.0.nested.bestResultOffcuts.utilisedOffcutBars', 0)
    );
});

it('would be a disaster if offcuts not added to inventory', function () {});

it('would be a disaster if using an offcut twice in the same project', function () {
    /**
     * An offcut is a physical bar - once its length is spoken for it cannot be handed to a second
     * bar in the same nest.
     */
    //Create admin & seed materials
    $adminBusiness = createBusiness('admin', true);
    $adminUser = createUser(1, $adminBusiness, true, true);
    $this->actingAs($adminUser);
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Business with a delivered batch and three offcuts available
    $business = createBusiness('biz', true);
    $user = createUser(2, $business, false, true);
    $this->actingAs($user);

    $batchFrom = Batch::factory()->forUser($user->id)->create();
    $deliveredQuote = Quote::create([
        'user_id' => $user->id,
        'batch_id' => $batchFrom->id,
        'supplier_id' => null,
        'supplier_category' => "STEEL_MERCHANT",
        'supplier_quote_reference' => null,
        'quote_sent' => true,
        'quoted_price' => null,
        'quoted_lead_time' => null,
    ]);
    Order::create([
        'user_id' => $user->id,
        'batch_id' => $batchFrom->id,
        'supplier_id' => null,
        'quote_id' => $deliveredQuote->id,
        'order_sent' => true,
        'order_confirmation_received' => true,
        'purchase_order_number' => "123",
        'is_delivered' => true,
        "material_cert_numbers" => null,
    ]);
    foreach ([3000, 3000, 3000] as $length) {
        create_offcut_200PFC($length, $batchFrom->id);
    }

    //Pieces that could each be cut from any of those offcuts
    $project = createProject($user);
    $dataClassificationService = new dataClassificationService;
    $sampleBOM = sampleBOM($project, $dataClassificationService, [[2500, 5], [1500, 2]]);
    createPieces($sampleBOM, $project, $dataClassificationService);

    $response = $this->get(route('suggested.nesting'));
    $response->assertStatus(200);

    $utilisedOffcutBars = $response->viewData('page')['props']['pieces']['METERAGE'][0]
        ->nested['bestResultOffcuts']['utilisedOffcutBars'];

    //Every offcut used is a different physical offcut
    $offcutIds = array_map(fn ($bar) => $bar['sourceOffcut']['offcutId'], $utilisedOffcutBars);
    expect($offcutIds)->toEqual(array_unique($offcutIds));

    //And none is cut past its own length
    foreach ($utilisedOffcutBars as $bar) {
        expect($bar['sourceOffcut']['cutLength'])->toBeLessThanOrEqual($bar['sourceOffcut']['offcutLength']);
    }
});

it("would be a disaster if a cancelled order doesn't release offcuts back to available status", function () {});

//todo more
