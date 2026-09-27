<?php

use App\Models\Product;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use App\Services\CsvService;
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

/**
 * A Project Quote sheet whose third material row has a Length cell that never
 * resolved to a number - a formula that arrived as "#REF!", which is exactly what
 * public/examples/material_list.xlsx is full of.
 */
function materialListWithUnreadableLength(): UploadedFile
{
    $rows = [
        24 => [null, null, null, 'Length', 'Width', 'SubQty', 'Rate', 'Total'],
        25 => [null, 'Totals', null, 35.5, null, 158, null, 10014.6],
        26 => [null, '250 PFC 9m', null, 9, 1, 10, 60, 5400],
        27 => [null, '20mm plate GR350', null, 6, 1, 6, 10, 360],
        28 => [null, '75x50x2.5 RHS', null, '#REF!', 1, 13, 2, 26],
        29 => [null, 'LVL 90X63', null, 4, 1, 5, 60, 1200],
    ];

    $spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    foreach ($rows as $rowIndex => $row) {
        foreach ($row as $colIndex => $value) {
            if ($value !== null) {
                $sheet->setCellValueExplicit(
                    [$colIndex + 1, $rowIndex + 1],
                    $value,
                    PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING2
                );
            }
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'bom').'.xlsx';
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'bad_length.xlsx', null, null, true);
}

it('would be a disaster if one unreadable cell threw away the rest of the file', function () {
    /**
     * length_required is NOT NULL and an unreadable Length cell reduces to 0, so the
     * insert raised a QueryException that unwound the whole row loop: every row below
     * the bad one was lost, the half-filled project was left behind, and the user was
     * told the template had stopped auto-detecting.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);
    $this->actingAs($user);
    seedMasterMaterials();

    $this->from('/dashboard')->post(route('projects.store'), [
        'name' => 'Unreadable length',
        'reference' => null,
        'date_materials_required' => null,
        'tentative' => false,
        'excel' => [materialListWithUnreadableLength()],
    ]);

    $descriptions = RawMaterialQuote::pluck('description')->all();

    //The rows below the bad one still import
    expect($descriptions)->toContain('LVL 90X63')
        //...and the row above it, and the bad row itself, is not silently dropped
        ->and($descriptions)->toContain('250 PFC 9m')
        ->and($descriptions)->not->toContain('75x50x2.5 RHS')
        //...the upload is a success, not a template failure
        ->and(session('project'))->not->toBeNull()
        ->and(session('warning'))->toBeNull();

    //...and the user is told which line we could not use
    expect(Project::first()->unimportedItems()['couldNotBeRead'])->toContain('75x50x2.5 RHS');
});

it('would be a disaster if a template with no SubQty column crashed the import', function () {
    /**
     * sub_qty is NOT NULL, and a template that does not map a SubQty column leaves it
     * null. One is what getSubQty() already substitutes for a zero, for the same reason.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, true, true);
    $this->actingAs($user);
    seedMasterMaterials();

    $project = Project::factory()->create(['user_id' => $user->id]);
    $dataClassificationService = new App\Services\DataClassificationService;

    (new CsvService)->saveRawMaterialQuoteData([[
        'index' => 5,
        'description' => '250 PFC',
        'material' => null,
        'grade' => null,
        'surface' => null,
        'length_required' => 6000.0,
        'width_required' => null,
        'sub_qty' => null,
        'assembly_mark' => 'A1',
        'generalProductMatches' => $dataClassificationService->findGeneralProductMatchesFromText('250 PFC', $user),
        'customProductMatches' => $dataClassificationService->findCustomProductMatches('250 PFC', $user),
    ]], $project, $business);

    expect(RawMaterialQuote::first()->sub_qty)->toEqual(1);
});

it('would be a disaster if a short row raised warnings instead of being skipped', function () {
    /**
     * Three of these rules read the row without checking the cell was there, unlike
     * their siblings. A row that stops short of the check column raised "Undefined
     * array key" and then passed null to strtoupper(), a fatal in PHP 9.
     */
    $csvService = new CsvService;

    $raised = null;
    set_error_handler(function ($number, $message) use (&$raised) {
        $raised = $message;

        return true;
    });

    $csvService->shouldSkip('Subtotal', ['A', 'B'], 5);

    restore_error_handler();

    expect($raised)->toBeNull();
});
