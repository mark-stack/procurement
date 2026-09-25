<?php

use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Services\DataClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('would be a disaster if a project with an upload BOM had no materials available for nesting', function (int $testCaseIndex) {
    /**
     * project with awarded status has materials available for nesting
     * //todo remove "awarded"
     */
    //Create admin
    $adminBusiness = createBusiness('admin', true);
    $adminUser = createUser(1, $adminBusiness, true, true);

    //Authorised
    $this->actingAs($adminUser);

    //Create project
    $project = createProject($adminUser, true);

    //Nest
    $nest = nestingTestCases()[$testCaseIndex]['nest'];

    //Seed master_product.csv to create products
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Service
    $dataClassificationService = new dataClassificationService;

    //Create BOM
    $sampleBOM = sampleBOM($project, $dataClassificationService, $nest);

    //Create raw material quotes & pieces
    $pieces = createPieces($sampleBOM, $project, $dataClassificationService);
    expect(count($pieces))->toBeGreaterThan(0);

    $response = $this->get(route('suggested.nesting'));
    $response->assertStatus(200);

    //$response->assertInertia(fn (Assert $page) => dd($page));

    $response->assertInertia(fn (Assert $page) => $page
        ->count('pieces', 1) //1 batch parsed to the view
        ->count('pieces.METERAGE.0.pieces', count($pieces)) //5 pieces
    );
})->with(range(0, count(nestingTestCases()) - 1)); //This runs each test case index. e.g [0,1,2,3]

it('would be a disaster if has other business’s materials', function () {});

it('would be a disaster if includes deprecated project materials', function () {});

it('would be a disaster if includes archived project materials', function () {});

it("would be a disaster if a nest could be downloaded from another business's batch", function () {
    /**
     * download-nesting takes a bare batch id and used to run no ownership check at all, so any signed
     * in user could read any batch's cut plan, pieces and project names.
     */
    $business1 = createBusiness('biz1', true);
    $user1 = createUser(1, $business1, false, true);
    $batch = Batch::factory()->forUser($user1->id)->create();
    $batch->nested_state = ['METERAGE' => []];
    $batch->save();

    //A stranger from another business
    $business2 = createBusiness('biz2', true);
    $user2 = createUser(2, $business2, false, true);
    $this->actingAs($user2);

    $this->get(route('download.nesting', [$batch->id]))->assertStatus(403);

    //The owner still gets their own nest
    $this->actingAs($user1);
    $this->get(route('download.nesting', [$batch->id]))->assertStatus(200);
});

it('would be a disaster if "ready to nest" could not be downloaded', function () {
    //batch_id 0 means "not batched yet", which belongs to the caller by definition
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $this->actingAs($user);

    $this->get(route('download.nesting', [0]))->assertStatus(200);
});

it('would be a disaster if a batch that was never nested took the page down', function () {
    /**
     * nested_state is nullable, so a batch can exist without one. unserialize(null) returns false,
     * which used to be handed straight to usageStats() as if it were an array.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $this->actingAs($user);

    $batch = Batch::factory()->forUser($user->id)->create();
    expect($batch->getRawOriginal('nested_state'))->toBeNull()
        ->and($batch->nested_state)->toBe([]);

    $this->get(route('batch.nesting', [$batch->id, 'current', 0]))->assertStatus(200);
    $this->get(route('download.nesting', [$batch->id]))->assertStatus(200);
});

it('would be a disaster if a nest with no steel in it took the page down', function () {
    /**
     * checks() and the nesting screens read usage.METERAGE unconditionally. A business quoting only
     * bolts has no meterage at all, and used to get "Undefined array key METERAGE".
     */
    $business = createBusiness('biz', true);
    $formatter = new NestingFormatter();

    $piecesNested = ['BUNDLE' => collect([(object) ['product_category' => 'BOLT', 'nested' => []]])];

    $usageStats = $formatter->usageStats($piecesNested);
    expect($usageStats['METERAGE']['efficiency'])->toBe(0);

    $checks = $formatter->checks($piecesNested, $usageStats, $business);
    expect($checks['efficiency']['result'])->toBeFalse();
});

it('would be a disaster if the nest saved to the batch was not the nest the user approved', function () {
    /**
     * The suggested nesting screen and Actions/Batch/SaveNesting nest the same pieces independently.
     * Nesting used to pick stock lengths at random, so pressing "start quoting" re-rolled the plan and
     * the batch was ordered against a cut list nobody had seen.
     */
    //Create admin & seed materials
    $adminBusiness = createBusiness('admin', true);
    $adminUser = createUser(1, $adminBusiness, true, true);
    $this->actingAs($adminUser);
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Create user & project
    $business = createBusiness('biz', true);
    $user = createUser(2, $business, false, true);
    $this->actingAs($user);
    $project = createProject($user);

    //Create BOM & pieces
    $dataClassificationService = new dataClassificationService;
    $sampleBOM = sampleBOM($project, $dataClassificationService, nestingTestCases()[0]['nest']);
    createPieces($sampleBOM, $project, $dataClassificationService);

    //What the user is shown before batching
    $suggested = $this->get(route('suggested.nesting'));
    $suggested->assertStatus(200);
    $suggestedUsage = $suggested->viewData('page')['props']['usage']['METERAGE'];
    $suggestedBars = $suggested->viewData('page')['props']['pieces']['METERAGE'][0]->nested['utilisedBars'];

    //Start quoting
    $this->post(route('quotes.store'));
    $batch = Batch::first();
    expect($batch)->not->toBeNull();

    //What was actually saved against the batch
    $saved = $this->get(route('batch.nesting', [$batch->id, 'current', 0]));
    $saved->assertStatus(200);
    $savedUsage = $saved->viewData('page')['props']['usage']['METERAGE'];
    $savedBars = $saved->viewData('page')['props']['pieces']['METERAGE'][0]->nested['utilisedBars'];

    expect($savedUsage)->toEqual($suggestedUsage)
        ->and(count($savedBars))->toBe(count($suggestedBars));

    foreach ($suggestedBars as $index => $bar) {
        expect($savedBars[$index]['count'])->toBe($bar['count'])
            ->and($savedBars[$index]['result']['bar_length'])->toBe($bar['result']['bar_length'])
            ->and($savedBars[$index]['result']['unused'])->toBe($bar['result']['unused']);
    }
});

//todo more
