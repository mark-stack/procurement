<?php

use App\Models\Product;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

function uploadExampleMaterialList($test, $user, string $projectName = 'Example project')
{
    $file = new UploadedFile(
        base_path('public/examples/material_list.xlsx'),
        'material_list.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true
    );

    return $test->actingAs($user)
        ->from('/dashboard')
        ->post(route('projects.store'), [
            'name' => $projectName,
            'reference' => null,
            'date_materials_required' => null,
            'tentative' => false,
            'excel' => [$file],
        ]);
}

/**
 * Post straight at the route, the way anything bypassing the modal would.
 */
function postMaterialLists($test, $user, array $files)
{
    return $test->actingAs($user)
        ->from('/dashboard')
        ->post(route('projects.store'), [
            'name' => 'Example project',
            'reference' => null,
            'date_materials_required' => null,
            'tentative' => false,
            'excel' => $files,
        ]);
}

it('would be a disaster if a non-Excel upload reached the extractor', function () {
    /**
     * The accept attribute and the size check in the modal were the only thing
     * stopping this - the request had no per-file rules at all, so anything at all
     * got handed to Excel::toArray.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $response = postMaterialLists($this, $user, [
        UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'),
    ]);

    $response->assertInvalid('excel.0');
    expect(Project::count())->toBe(0);
});

it('would be a disaster if an oversized upload reached the extractor', function () {
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $response = postMaterialLists($this, $user, [
        UploadedFile::fake()->create(
            'huge.xlsx',
            2048,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ),
    ]);

    $response->assertInvalid('excel.0');
    expect(Project::count())->toBe(0);
});

it('would be a disaster if the five file limit was only enforced in the browser', function () {
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $files = collect(range(1, 6))
        ->map(fn ($i) => UploadedFile::fake()->create(
            "list{$i}.xlsx",
            10,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ))
        ->all();

    $response = postMaterialLists($this, $user, $files);

    $response->assertInvalid('excel');
    expect(Project::count())->toBe(0);
});

it('would be a disaster if uploading a material list extracted nothing', function () {
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, true, true);

    //Seeding now runs through the admin-guarded import route
    $this->actingAs($user);
    seedMasterMaterials();

    uploadExampleMaterialList($this, $user);

    expect(Project::count())->toBe(1)
        ->and(RawMaterialQuote::count())->toBeGreaterThan(0)
        ->and(session('project'))->not->toBeNull()
        ->and(session('warning'))->toBeNull();
});

it('would be a disaster if an empty extraction looked like a success', function () {
    /**
     * With no products to match against, every BOM row is skipped. The project used
     * to be created and flashed as a success anyway, so the modal closed and the user
     * was handed an empty BOM with no explanation.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, true, true);

    expect(Product::count())->toBe(0);

    uploadExampleMaterialList($this, $user);

    //User is told what happened
    expect(session('warning'))->not->toBeNull()
        //...and the modal is not sent off to download a BOM that isn't there
        ->and(session('project'))->toBeNull()
        //...and no empty shell is left blocking a retry with the same name
        ->and(Project::count())->toBe(0);
});

it('would be a disaster if a failed extraction blocked retrying the same name', function () {
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, true, true);

    //First attempt fails: no products to match against
    uploadExampleMaterialList($this, $user, 'Same name');
    expect(session('warning'))->not->toBeNull();

    //Second attempt succeeds under the same project name
    seedMasterMaterials();
    uploadExampleMaterialList($this, $user, 'Same name');

    expect(session('warning'))->toBeNull()
        ->and(Project::count())->toBe(1)
        ->and(RawMaterialQuote::count())->toBeGreaterThan(0);
});
