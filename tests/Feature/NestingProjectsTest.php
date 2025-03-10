<?php

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

//todo more
