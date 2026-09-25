<?php

use App\Formatters\NestingFormatter;
use App\Models\Batch;
use App\Services\DataClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

it('would be a disaster if the project letters on the batch page named a different project than the drawings', function () {
    /**
     * Letters are assigned once, when the nest is saved, and stamped onto every cut in it. The batch
     * screens used to rebuild the map from the batch's projects, which orders them by project id
     * rather than by the pieces. Two projects whose piece order does not match their id order then
     * got each other's letters, and the print friendly sheet sent the wrong steel to the wrong job.
     */
    //Create admin & seed materials
    $adminBusiness = createBusiness('admin', true);
    $adminUser = createUser(1, $adminBusiness, true, true);
    $this->actingAs($adminUser);
    $this->get(route('admin.update.master.materials.spreadsheet'));

    //Create user
    $business = createBusiness('biz', true);
    $user = createUser(2, $business, false, true);
    $this->actingAs($user);

    $dataClassificationService = new DataClassificationService;

    //Two projects, but the second project's pieces are created first
    $projectA = createProject($user);
    $projectB = createProject($user);

    createPieces(sampleBOM($projectB, $dataClassificationService, [[2500, 2]]), $projectB, $dataClassificationService);
    createPieces(sampleBOM($projectA, $dataClassificationService, [[1500, 2]]), $projectA, $dataClassificationService);

    //Start quoting
    $this->post(route('quotes.store'));
    $batch = Batch::first();
    expect($batch)->not->toBeNull();

    $response = $this->get(route('batch.nesting', [$batch->id, 'current', 0]));
    $response->assertStatus(200);
    $props = $response->viewData('page')['props'];

    //Both projects are in the legend, with a distinct letter each
    expect($props['lettersProjectArray'])->toHaveKeys([$projectA->id, $projectB->id])
        ->and(array_unique($props['lettersProjectArray']))->toHaveCount(2);

    //And every letter stamped on a cut is the one the legend gives that project
    $cutsChecked = 0;
    foreach ($props['pieces']['METERAGE'] as $product) {
        foreach ($product->nested['utilisedBars'] as $bar) {
            foreach ($bar['result']['pieces'] as $cut) {
                expect($cut['letter'])->toBe($props['lettersProjectArray'][$cut['projectId']]);
                $cutsChecked++;
            }
        }
    }

    expect($cutsChecked)->toBeGreaterThan(0);
});

it('would be a disaster if a batch nested before the letters were stored lost its legend', function () {
    /**
     * Batches saved before letters_project_array existed still carry the letters on the cuts, so the
     * legend is recovered from the nest itself rather than guessed at from the batch's projects.
     */
    $adminBusiness = createBusiness('admin', true);
    $adminUser = createUser(1, $adminBusiness, true, true);
    $this->actingAs($adminUser);
    $this->get(route('admin.update.master.materials.spreadsheet'));

    $business = createBusiness('biz', true);
    $user = createUser(2, $business, false, true);
    $this->actingAs($user);

    $dataClassificationService = new DataClassificationService;
    $project = createProject($user);
    createPieces(sampleBOM($project, $dataClassificationService, [[2500, 3]]), $project, $dataClassificationService);

    $this->post(route('quotes.store'));
    $batch = Batch::first();

    //The letters as saved, then forget them the way an older batch would have
    $saved = $batch->letters_project_array;
    expect($saved)->toBe([$project->id => 'A']);

    $batch->letters_project_array = null;
    $batch->save();

    $response = $this->get(route('batch.nesting', [$batch->id, 'current', 0]));
    $response->assertStatus(200);

    expect($response->viewData('page')['props']['lettersProjectArray'])->toBe($saved);
});

it('would be a disaster if a batch containing bolts took the print friendly page down', function () {
    /**
     * Only meterage is cut from bars. Bundle materials have no 'bestResultOffcuts' and area materials
     * have no nest at all, and the print friendly page used to paginate cutting diagrams for every
     * material in the batch regardless, taking the whole sheet down with it.
     */
    $business = createBusiness('biz', true);
    $business->update(['meterage_only' => false]);
    $user = createUser(1, $business, false, true);
    $this->actingAs($user);

    $batch = Batch::factory()->forUser($user->id)->create();
    $batch->nested_state = [
        'METERAGE' => [],
        'BUNDLE' => [
            [
                'product_category' => 'HEX_BOLT',
                'product_derived_label' => 'M16 HEX BOLT',
                'algo' => 'BUNDLE',
                'nominal_units' => 'MILLIMETERS',
                'pieces' => [],
                'purchasable' => [100, 25],
                'nested' => ['totalBought' => 250, 'efficiency' => 100, 'boxes' => [100 => 2, 25 => 2]],
            ],
        ],
    ];
    $batch->save();

    //The bolts reach the page, with no cutting diagram to draw
    $props = $this->get(route('batch.nesting', [$batch->id, 'current', 1]))
        ->assertStatus(200)
        ->viewData('page')['props'];

    $bolts = $props['piecesGroupedBySupplierGroup']['assigned']['FASTENERS'][0];

    expect($bolts['algo'])->toBe('BUNDLE')
        ->and($bolts['nested'])->not->toHaveKey('bestResultOffcuts');
});

it('would be a disaster if a mistyped batch nesting url returned a 500 instead of a 404', function () {
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $this->actingAs($user);

    $batch = Batch::factory()->forUser($user->id)->create();

    //"print" is typed int and "redirect" picks the close destination
    $this->get('/batch-nesting/'.$batch->id.'/current/abc')->assertStatus(404);
    $this->get('/batch-nesting/'.$batch->id.'/sideways/1')->assertStatus(404);

    //The real thing still works
    $this->get(route('batch.nesting', [$batch->id, 'current', 1]))->assertStatus(200);
});

it('would be a disaster if the batch nesting page ran a query per project', function () {
    /**
     * The page loaded one project per piece, then re-fetched each of those projects again inside
     * ProjectResource. A batch spanning a dozen jobs paid for that twice over.
     */
    $adminBusiness = createBusiness('admin', true);
    $adminUser = createUser(1, $adminBusiness, true, true);
    $this->actingAs($adminUser);
    $this->get(route('admin.update.master.materials.spreadsheet'));

    $business = createBusiness('biz', true);
    $user = createUser(2, $business, false, true);
    $this->actingAs($user);

    $dataClassificationService = new DataClassificationService;
    for ($i = 0; $i < 12; $i++) {
        $project = createProject($user);
        createPieces(sampleBOM($project, $dataClassificationService, [[2500, 4], [1500, 3]]), $project, $dataClassificationService);
    }

    $this->post(route('quotes.store'));
    $batch = Batch::first();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->get(route('batch.nesting', [$batch->id, 'current', 0]))->assertStatus(200);

    //Was 202 for these 12 projects, and grew by ~16 with each one added
    expect($queries)->toBeLessThan(70);
});
