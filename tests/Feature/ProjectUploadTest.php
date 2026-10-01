<?php

use App\Models\Product;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use App\PrerequisiteConditions\PrerequisiteConditions;
use App\Services\CsvService;
use App\Services\DataClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

/**
 * @param  int|null  $projectManagerId  the colleague the job is for, when somebody is uploading on
 *                                      their behalf - null is the plain case, where it is the
 *                                      uploader's own project
 */
function uploadExampleMaterialList($test, $user, string $projectName = 'Example project', ?int $projectManagerId = null)
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
            'project_manager_id' => $projectManagerId,
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
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $response = postMaterialLists($this, $user, [
        UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'),
    ]);

    $response->assertInvalid('excel.0');
    expect(Project::count())->toBe(0);
});

it('would be a disaster if an oversized upload reached the extractor', function () {
    $business = createBusiness('gmail');
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
    $business = createBusiness('gmail');
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

it('would be a disaster if a new project could be named anything at all', function () {
    /**
     * The name inputs carry no maxlength and the rules carried no max, on a TEXT column - so the
     * only limit on a name drawn across every colleague's board was what the poster chose to send.
     * A blank-but-"required" name of spaces is the same hole from the other side: the modal trims it
     * on the way out and nothing else did.
     *
     * Both refusals have to land before the upload is read, or the answer arrives after the
     * spreadsheet has been parsed and a project created under the name being refused.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $file = fn () => UploadedFile::fake()->create(
        'list.xlsx',
        10,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    $this->actingAs($user)->from('/dashboard')->post(route('projects.store'), [
        'name' => str_repeat('A', Project::MAX_NAME_CHARACTERS + 1),
        'tentative' => false,
        'excel' => [$file()],
    ])->assertInvalid('name');

    $this->actingAs($user)->from('/dashboard')->post(route('projects.store'), [
        'name' => '     ',
        'tentative' => false,
        'excel' => [$file()],
    ])->assertInvalid('name');

    $this->actingAs($user)->from('/dashboard')->post(route('projects.store'), [
        'name' => 'Fine name',
        'reference' => ['an', 'array'],
        'tentative' => false,
        'excel' => [$file()],
    ])->assertInvalid('reference');

    expect(Project::count())->toBe(0);
});

it('would be a disaster if a padded name slipped past the duplicate check', function () {
    /*
     * The uniqueness rule compares what was posted, so without trimming first, "Tower A " is a
     * different name to "Tower A" as far as the check is concerned and the same name everywhere it
     * is read.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $existing = createProject($user);
    $existing->update(['name' => 'Tower A']);

    $this->actingAs($user)->from('/dashboard')->post(route('projects.store'), [
        'name' => '  Tower A  ',
        'tentative' => false,
        'excel' => [UploadedFile::fake()->create(
            'list.xlsx',
            10,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        )],
    ])->assertInvalid('name');

    expect(Project::count())->toBe(1);
});

it('would be a disaster if uploading a material list extracted nothing', function () {
    $business = createBusiness('gmail');
    recordExampleTemplates($business);

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
    $business = createBusiness('gmail');
    recordExampleTemplates($business);

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
    $business = createBusiness('gmail');
    recordExampleTemplates($business);

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

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    foreach ($rows as $rowIndex => $row) {
        foreach ($row as $colIndex => $value) {
            if ($value !== null) {
                $sheet->setCellValueExplicit(
                    [$colIndex + 1, $rowIndex + 1],
                    $value,
                    DataType::TYPE_STRING2
                );
            }
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'bom').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'bad_length.xlsx', null, null, true);
}

it('would be a disaster if one unreadable cell threw away the rest of the file', function () {
    /**
     * length_required is NOT NULL and an unreadable Length cell reduces to 0, so the
     * insert raised a QueryException that unwound the whole row loop: every row below
     * the bad one was lost, the half-filled project was left behind, and the user was
     * told the template had stopped auto-detecting.
     */
    $business = createBusiness('gmail');
    recordExampleTemplates($business);

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
    $business = createBusiness('gmail');
    $user = createUser(1, $business, true, true);
    $this->actingAs($user);
    seedMasterMaterials();

    $project = Project::factory()->create(['user_id' => $user->id]);
    $dataClassificationService = new DataClassificationService;

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

it('would be a disaster if a BOM upload could be posted onto a colleague’s project', function () {
    /**
     * Found by the route audit - projects.products.store had no test at all, and it is the second
     * way a material list gets into the application. It is owner-only for the same reason editing
     * is: the BOM is what the nest cuts from.
     */
    $business = createBusiness('gmail');
    recordExampleTemplates($business);
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);
    seedMasterMaterials();

    $theirs = createProject($colleague);

    $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('projects.products.store', $theirs->id), [
            'excel' => new UploadedFile(
                base_path('public/examples/material_list.xlsx'),
                'material_list.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            ),
        ])
        ->assertForbidden();

    expect($theirs->rawMaterialQuotes()->count())->toBe(0);
});

it('would be a disaster if a BOM upload took a file that is not a spreadsheet', function () {
    /*
     * The other route into the extractor. projects.store already refuses this per file; this one
     * validates on its own and nothing covered it.
     */
    $business = createBusiness('gmail');
    recordExampleTemplates($business);
    $user = createUser(1, $business, false, true);

    $project = createProject($user);

    $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('projects.products.store', $project->id), [
            'excel' => UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'),
        ])
        ->assertInvalid('excel');

    expect($project->rawMaterialQuotes()->count())->toBe(0);
});

/*
 * Uploading on behalf of a colleague.
 *
 * The draftsman has the spreadsheet; the project manager has the job. Before projects.user_id could
 * name somebody other than whoever was logged in, every BOM the drawing office uploaded produced a
 * project under the draftsman's name - on the board as theirs, editable and archivable by nobody
 * else, and with the materials deadline reminders going to the one person not running the job.
 */
it('creates the project under the colleague it was uploaded for, and records who uploaded it', function () {
    $business = createBusiness('gmail');
    recordExampleTemplates($business);
    $draftsman = createUser(1, $business, false, true);
    $projectManager = createUser(2, $business, false, true);
    seedMasterMaterials();

    uploadExampleMaterialList($this, $draftsman, 'Tower A', $projectManager->id);

    $project = Project::firstWhere('name', 'Tower A');

    expect($project)->not->toBeNull()
        //The manager owns it, which is what the board draws and what the owner-only gates read
        ->and($project->user_id)->toBe($projectManager->id)
        //And the draftsman is on the record as the one who uploaded it
        ->and($project->created_by_user_id)->toBe($draftsman->id)
        //The materials still came out of the file, into the manager's project
        ->and($project->rawMaterialQuotes()->count())->toBeGreaterThan(0);
});

it('leaves created_by_user_id empty when a project manager uploads their own list', function () {
    /*
     * The common case, and the one that must stay unremarkable: null means "nobody acted on anybody's
     * behalf". Posting your own id counts as that too - the select offers "me" as null, but anything
     * posting straight at the route may well send it.
     */
    $business = createBusiness('gmail');
    recordExampleTemplates($business);
    $user = createUser(1, $business, false, true);
    seedMasterMaterials();

    uploadExampleMaterialList($this, $user, 'Mine', $user->id);

    expect(Project::firstWhere('name', 'Mine')->created_by_user_id)->toBeNull();
});

it('would be a disaster if a project could be created for somebody outside the business', function () {
    /*
     * This id decides who owns a project: who the board names, who may rename or archive it, and who
     * is chased about its deadline. An id from another business would hand a stranger a project - and
     * take it off the uploader's own board, which is drawn from their business's users, so neither of
     * them would be able to reach it.
     */
    $business = createBusiness('gmail');
    recordExampleTemplates($business);
    $user = createUser(1, $business, false, true);

    $otherBusiness = createBusiness('othersteel');
    $stranger = createUser(2, $otherBusiness, false, true);

    seedMasterMaterials();

    uploadExampleMaterialList($this, $user, 'Tower A', $stranger->id)
        ->assertInvalid('project_manager_id');

    expect(Project::count())->toBe(0);
});

it('lets the draftsman who uploaded a colleague’s list add the rest of the materials to it', function () {
    /*
     * A job's materials do not arrive in one file on one day, and the second file reaches whoever the
     * first one did. Without this the draftsman could create the manager's project and then do
     * nothing further to it - including finishing an import that stopped at a price book
     * clarification, which is part of the upload and not part of running the job.
     *
     * The colleague's project this is NOT allowed on is the test above it (a project they had no hand
     * in), which still answers 403.
     */
    $business = createBusiness('gmail');
    recordExampleTemplates($business);
    $draftsman = createUser(1, $business, false, true);
    $projectManager = createUser(2, $business, false, true);
    seedMasterMaterials();

    uploadExampleMaterialList($this, $draftsman, 'Tower A', $projectManager->id);

    $project = Project::firstWhere('name', 'Tower A');
    $rowsFromTheFirstFile = $project->rawMaterialQuotes()->count();

    $this->actingAs($draftsman)
        ->from('/dashboard')
        ->post(route('projects.products.store', $project->id), [
            'excel' => new UploadedFile(
                base_path('public/examples/material_list.xlsx'),
                'material_list.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            ),
        ])
        ->assertSessionHasNoErrors();

    //Uploads are cumulative, so the second file adds to the list rather than replacing it
    expect($project->rawMaterialQuotes()->count())->toBeGreaterThan($rowsFromTheFirstFile);
});

it('still refuses the manager’s colleagues everything except reading the list', function () {
    /*
     * The line only moved for the person who uploaded the material list. A third colleague - no
     * relation to the job - gets the same 403 they always did, and the board still disables the
     * controls for them.
     */
    $business = createBusiness('gmail');
    recordExampleTemplates($business);
    $draftsman = createUser(1, $business, false, true);
    $projectManager = createUser(2, $business, false, true);
    $bystander = createUser(3, $business, false, true);
    seedMasterMaterials();

    uploadExampleMaterialList($this, $draftsman, 'Tower A', $projectManager->id);

    $project = Project::firstWhere('name', 'Tower A');

    expect((new PrerequisiteConditions())->uploadMaterials($draftsman, $project))->toBeTrue()
        ->and((new PrerequisiteConditions())->uploadMaterials($projectManager, $project))->toBeTrue()
        ->and((new PrerequisiteConditions())->uploadMaterials($bystander, $project))->toBeFalse()
        /*
         * And the uploader is not a second owner: renaming the job, moving its materials date and
         * taking it off the board stay the manager's call.
         */
        ->and((new PrerequisiteConditions())->editProject($draftsman, $project))->toBeFalse()
        ->and((new PrerequisiteConditions())->archiveProject($draftsman, $project))->toBeFalse()
        ->and((new PrerequisiteConditions())->editProject($projectManager, $project))->toBeTrue();
});

it('adds the material rows when the owner uploads to their own project', function () {
    //The other half - the route audit found no test proving this path works at all
    $business = createBusiness('gmail');
    recordExampleTemplates($business);
    $user = createUser(1, $business, false, true);
    seedMasterMaterials();

    $project = createProject($user);

    $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('projects.products.store', $project->id), [
            'excel' => new UploadedFile(
                base_path('public/examples/material_list.xlsx'),
                'material_list.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            ),
        ]);

    expect($project->rawMaterialQuotes()->count())->toBeGreaterThan(0);
});
