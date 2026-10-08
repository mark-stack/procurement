<?php

use App\Models\Batch;
use App\Models\MaterialListFile;
use App\Models\Piece;
use App\Models\Project;
use App\Models\Quote;
use App\Models\RawMaterialQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * The example spreadsheet, as an upload.
 *
 * A real file rather than UploadedFile::fake(): every one of these tests turns on the import
 * actually producing material rows, which is what the file is being traced back from.
 */
function materialListUpload(): UploadedFile
{
    return new UploadedFile(
        base_path('public/examples/material_list.xlsx'),
        'material_list.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true
    );
}

/**
 * A business that can read the example workbook, and somebody in it.
 *
 * The disk is faked here rather than in each test. It used to matter that it happened only once the
 * price book was seeded - these files live on 'local', which is where the seeder read
 * master_materials.csv from, and faking it first left the catalogue empty and every import
 * extracting nothing. The catalogue is PHP in the repo since 2026-10-08, so that ordering is now
 * incidental.
 *
 * @return array{0: \App\Models\Business, 1: \App\Models\User}
 */
function fabricatorWhoCanImport(): array
{
    //The admin the import notifies about unmatched lines, created before the business under test
    test()->actingAs(createUser(1, createBusiness('admin'), true, true));
    seedMasterMaterials();

    Storage::fake(MaterialListFile::DISK);

    $business = createBusiness('fabricator');
    recordExampleTemplates($business);

    return [$business, createUser(1, $business, false, true)];
}

/**
 * Create a project from an uploaded material list, the way the new-project modal does.
 *
 * Its own copy rather than ProjectUploadTest's uploadExampleMaterialList(), because a Pest helper
 * defined in a test file only exists while that file is loaded - running this one on its own would
 * otherwise be an undefined function.
 *
 * @param  int|null  $projectManagerId  the colleague the job is for, when somebody is uploading on
 *                                      their behalf
 */
function createProjectFromUpload($test, $user, string $projectName, ?int $projectManagerId = null)
{
    return $test->actingAs($user)
        ->from('/dashboard')
        ->post(route('projects.store'), [
            'name' => $projectName,
            'reference' => null,
            'date_materials_required' => null,
            'date_fabrication_begins' => now()->addMonth()->toDateString(),
            'tentative' => false,
            'project_manager_id' => $projectManagerId,
            'excel' => [materialListUpload()],
        ]);
}

/**
 * Upload a material list onto an existing project, the way the BOM modal's dropzone does.
 */
function uploadOnto($test, $user, Project $project, string $filename = 'material_list.xlsx')
{
    $file = materialListUpload();

    return $test->actingAs($user)
        ->from('/nesting')
        ->post(route('projects.products.store', $project->id), [
            'excel' => new UploadedFile(
                $file->getPathname(),
                $filename,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            ),
        ]);
}

it('would be a disaster if a file that would not parse were a 500 on a customer upload', function () {
    /**
     * An .xlsx that is corrupt, truncated or password-protected has the right extension and the
     * right media type, so "mimes" lets it through - and the parse was unguarded, so it threw out
     * of the controller as a 500 while the customer was looking at it. The other upload path has
     * caught exactly this since it was written.
     */
    [, $user] = fabricatorWhoCanImport();

    $project = createProject($user);

    $response = $this->actingAs($user)
        ->from('/nesting')
        ->post(route('projects.products.store', $project->id), [
            'excel' => UploadedFile::fake()->createWithContent(
                'truncated.xlsx',
                'PK'."\x03\x04".'this is not a workbook',
            ),
        ]);

    $response->assertRedirect('/nesting')
        ->assertSessionHas('warning')
        ->assertSessionHasNoErrors();

    expect(MaterialListFile::count())->toBe(0)
        ->and(RawMaterialQuote::count())->toBe(0);
});

it('keeps the spreadsheet a material list was imported from, and links every row to it', function () {
    /*
     * The whole foundation. Nothing was kept before this: ProductController read the uploaded temp
     * file straight into Excel::toArray() and let it go, so an imported file existed only as the rows
     * it produced and there was no way back to it.
     */
    [, $user] = fabricatorWhoCanImport();

    $project = createProject($user);

    uploadOnto($this, $user, $project, 'beams-rev-c.xlsx')->assertSessionHasNoErrors();

    $materialListFile = $project->materialListFiles()->sole();

    //Kept under the name it arrived with, stored under a generated one - see MaterialListFile::record
    expect($materialListFile->original_filename)->toBe('beams-rev-c.xlsx')
        ->and($materialListFile->path)->not->toBe('beams-rev-c.xlsx')
        ->and($materialListFile->user_id)->toBe($user->id)
        ->and($materialListFile->size_bytes)->toBeGreaterThan(0);

    Storage::disk(MaterialListFile::DISK)->assertExists($materialListFile->path);

    //And every row it produced points back at it, which is what lets the whole file be taken off
    expect($project->rawMaterialQuotes()->count())->toBeGreaterThan(0)
        ->and($project->rawMaterialQuotes()->whereNull('material_list_file_id')->count())->toBe(0);
});

it('keeps each upload apart when a project is built from several of them', function () {
    /*
     * The case this exists for. A job's steel arrives over several files as the model is detailed,
     * and the one somebody wants back off the batch is a particular revision - not "the material
     * list", which by then is three spreadsheets deep.
     */
    [, $user] = fabricatorWhoCanImport();

    $project = createProject($user);

    uploadOnto($this, $user, $project, 'rev-a.xlsx')->assertSessionHasNoErrors();
    uploadOnto($this, $user, $project, 'rev-b.xlsx')->assertSessionHasNoErrors();

    $files = $project->materialListFiles()->orderBy('id')->get();

    expect($files)->toHaveCount(2)
        ->and($files->pluck('original_filename')->all())->toBe(['rev-a.xlsx', 'rev-b.xlsx'])
        //Cumulative, so the second file added to the list rather than replacing it
        ->and($files[0]->rawMaterialQuotes()->count())->toBeGreaterThan(0)
        ->and($files[1]->rawMaterialQuotes()->count())->toBe($files[0]->rawMaterialQuotes()->count());
});

it('lists the uploads behind the open batch, with what each one brought', function () {
    [, $user] = fabricatorWhoCanImport();

    $project = createProject($user);
    $project->update(['name' => 'Warehouse frame']);

    uploadOnto($this, $user, $project, 'warehouse-rev-c.xlsx')->assertSessionHasNoErrors();

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    //No batch in the url: the open card, which is the one an upload can still join
    $bom = $this->getJson(route('download.batch.bom'))->assertOk()->json('batchBom');

    expect($bom['files'])->toHaveCount(1);

    $file = $bom['files'][0];

    //What the modal prints, so a missing key is caught here rather than as a blank line
    expect($file)->toHaveKeys([
        'id', 'filename', 'project', 'size_bytes', 'rowCount',
        'uploadedBy', 'uploadedAt', 'downloadable', 'deletable', 'undeletableReason',
    ]);

    expect($file['filename'])->toBe('warehouse-rev-c.xlsx')
        ->and($file['project'])->toBe('Warehouse frame')
        ->and($file['rowCount'])->toBe($project->rawMaterialQuotes()->count())
        ->and($file['downloadable'])->toBeTrue()
        //Nothing quoted, nothing nested, and it is this person's own job
        ->and($file['deletable'])->toBeTrue()
        ->and($file['undeletableReason'])->toBeNull()
        //Nothing on the table that no file accounts for
        ->and($bom['rowsWithoutFile'])->toBe(0);
});

it('says how much of the table no uploaded file accounts for', function () {
    /*
     * Everything imported before uploads started being kept is in here, and so are the example lists,
     * which never came off a spreadsheet. A files list that does not add up to the table below it
     * otherwise reads as a files list with something missing from it.
     */
    [, $user] = fabricatorWhoCanImport();

    //Materials made the way everything imported before this feature existed was: with no file behind
    $project = createProject($user);
    $dataClassificationService = new \App\Services\DataClassificationService;
    createPieces(
        sampleBOM($project, $dataClassificationService, [[2500, 5], [1500, 2]]),
        $project,
        $dataClassificationService,
    );

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $bom = $this->getJson(route('download.batch.bom'))->assertOk()->json('batchBom');

    expect($bom['files'])->toHaveCount(0)
        ->and($bom['rowsWithoutFile'])->toBe($project->rawMaterialQuotes()->count())
        ->and($bom['rowsWithoutFile'])->toBeGreaterThan(0);
});

it('takes a whole upload off the batch, and only that upload', function () {
    /*
     * The thing the button is for. Before this it meant recognising one spreadsheet's rows by eye in
     * a table carrying several projects' steel and ticking them off one at a time.
     */
    [, $user] = fabricatorWhoCanImport();

    $project = createProject($user);

    uploadOnto($this, $user, $project, 'rev-a.xlsx')->assertSessionHasNoErrors();
    uploadOnto($this, $user, $project, 'rev-b.xlsx')->assertSessionHasNoErrors();

    [$revA, $revB] = $project->materialListFiles()->orderBy('id')->get()->all();

    $keptRows = $revB->rawMaterialQuotes()->pluck('id')->all();
    $pathOfRevA = $revA->path;

    $this->actingAs($user)
        ->from('/nesting')
        ->delete(route('material.list.file.destroy', $revA))
        ->assertSessionHasNoErrors();

    //The file row, the file itself, and every material that came out of it
    expect(MaterialListFile::find($revA->id))->toBeNull()
        ->and(RawMaterialQuote::where('material_list_file_id', $revA->id)->count())->toBe(0);

    Storage::disk(MaterialListFile::DISK)->assertMissing($pathOfRevA);

    //And the other revision is untouched - rows, pieces and all
    expect(RawMaterialQuote::whereIn('id', $keptRows)->count())->toBe(count($keptRows))
        ->and(MaterialListFile::find($revB->id))->not->toBeNull()
        ->and(Piece::whereIn('raw_material_quote_id', $keptRows)->count())
        ->toBe(Piece::where('project_id', $project->id)->count());
});

it('would be a disaster if removing a file took steel that was already on order with it', function () {
    /*
     * The rule the whole screen hangs off. A row that has been quoted or ordered is a commitment to
     * somebody outside the business, and deleting it quietly leaves a quote nobody can trace back to
     * a material list - the per-project BOM has always refused those one at a time, and a button that
     * takes two hundred rows at once must refuse them too.
     *
     * All or nothing, deliberately: half-deleting an upload leaves a file that is listed, is named
     * after a spreadsheet, and no longer contains what that spreadsheet said.
     */
    [, $user] = fabricatorWhoCanImport();

    $project = createProject($user);

    uploadOnto($this, $user, $project)->assertSessionHasNoErrors();

    $materialListFile = $project->materialListFiles()->sole();
    $rowCount = $materialListFile->rawMaterialQuotes()->count();

    //One length of this file's steel, out to a supplier
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    [$quote] = quoteAndOrder($user, $batch, quoteSent: true);
    $piece = Piece::where('project_id', $project->id)->firstOrFail();
    $piece->quotes()->attach($quote->id);

    $response = $this->actingAs($user)
        ->from('/nesting')
        ->delete(route('material.list.file.destroy', $materialListFile))
        ->assertSessionHasErrors('materialListFile');

    //Named, not counted: "which ones" is the next question
    expect(session('errors')->first('materialListFile'))
        ->toContain($piece->rawMaterialQuote->description);

    //Nothing taken
    expect(MaterialListFile::find($materialListFile->id))->not->toBeNull()
        ->and($materialListFile->rawMaterialQuotes()->count())->toBe($rowCount);

    Storage::disk(MaterialListFile::DISK)->assertExists($materialListFile->path);
});

it('refuses an upload whose materials have been nested into a batch', function () {
    /*
     * Bites earlier than the quote above. "Start quoting" sweeps everything waiting into one batch,
     * and from that moment the nest is saved against it and the order lists are read off that nest -
     * pulling a file out from under both would leave them describing steel that is not on the job.
     */
    [, $user] = fabricatorWhoCanImport();

    $project = createProject($user);

    uploadOnto($this, $user, $project)->assertSessionHasNoErrors();

    $materialListFile = $project->materialListFiles()->sole();

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    Piece::where('project_id', $project->id)->update(['batch_id' => $batch->id]);

    $this->actingAs($user)
        ->from('/nesting')
        ->delete(route('material.list.file.destroy', $materialListFile))
        ->assertSessionHasErrors('materialListFile');

    expect(session('errors')->first('materialListFile'))->toContain('nested into a batch')
        ->and(MaterialListFile::find($materialListFile->id))->not->toBeNull();

    //And the modal says the same thing before the button is ever drawn
    $bom = $this->actingAs($user)
        ->getJson(route('download.batch.bom', $batch))->assertOk()->json('batchBom');

    expect($bom['files'][0]['deletable'])->toBeFalse()
        ->and($bom['files'][0]['undeletableReason'])->toContain('nested into a batch');
});

it('lets a colleague read a file but not remove it', function () {
    /*
     * The two lines the multi-staff rules draw, and they are different lines. Reading the BOM is open
     * to the business - the Nesting page is shared and a batch carries several managers' work.
     * Changing a material list is the project's manager, or whoever uploaded for them: see
     * Project::isManagedBy.
     */
    [$business, $manager] = fabricatorWhoCanImport();
    $bystander = createUser(2, $business, false, true);

    $project = createProject($manager);

    uploadOnto($this, $manager, $project)->assertSessionHasNoErrors();

    $materialListFile = $project->materialListFiles()->sole();

    //Reading, including the file itself
    $this->actingAs($bystander)
        ->get(route('material.list.file.download', $materialListFile))
        ->assertOk()
        ->assertDownload($materialListFile->original_filename);

    //The button is not drawn for them...
    $bom = $this->actingAs($bystander)
        ->getJson(route('download.batch.bom'))->assertOk()->json('batchBom');

    expect($bom['files'][0]['deletable'])->toBeFalse()
        ->and($bom['files'][0]['undeletableReason'])->toContain('project manager');

    //...and posting at the route anyway is refused
    $this->actingAs($bystander)
        ->from('/nesting')
        ->delete(route('material.list.file.destroy', $materialListFile))
        ->assertSessionHasErrors('materialListFile');

    expect(MaterialListFile::find($materialListFile->id))->not->toBeNull();
});

it('lets whoever uploaded on a colleague’s behalf remove their own file', function () {
    /*
     * The other half of isManagedBy. The BOM comes out of the model and is uploaded by the draftsman
     * who detailed it, not by the manager running the job - so the person who put the wrong revision
     * on the batch is exactly the person who has to be able to take it off again.
     */
    [$business, $draftsman] = fabricatorWhoCanImport();
    $projectManager = createUser(2, $business, false, true);

    createProjectFromUpload($this, $draftsman, 'Tower A', $projectManager->id)
        ->assertSessionHasNoErrors();

    $project = Project::firstWhere('name', 'Tower A');
    $materialListFile = $project->materialListFiles()->sole();

    expect($materialListFile->user_id)->toBe($draftsman->id);

    $this->actingAs($draftsman)
        ->from('/nesting')
        ->delete(route('material.list.file.destroy', $materialListFile))
        ->assertSessionHasNoErrors();

    expect(MaterialListFile::find($materialListFile->id))->toBeNull()
        ->and($project->rawMaterialQuotes()->count())->toBe(0);
});

it('keeps another business out of the file entirely', function () {
    [, $user] = fabricatorWhoCanImport();

    $project = createProject($user);
    uploadOnto($this, $user, $project)->assertSessionHasNoErrors();

    $materialListFile = $project->materialListFiles()->sole();

    //A stranger's business: possessing the id is not possessing the file
    $outsider = createUser(1, createBusiness('somebodyelse'), false, true);

    $this->actingAs($outsider)
        ->get(route('material.list.file.download', $materialListFile))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->delete(route('material.list.file.destroy', $materialListFile))
        ->assertForbidden();

    expect(MaterialListFile::find($materialListFile->id))->not->toBeNull();
});

it('still lists a file whose copy never made it to disk, so its materials can be removed', function () {
    /*
     * A full disk must not cost the customer their import, so the row is written with no path on it.
     * The materials are on the batch either way, and removing them is still the thing to offer -
     * there is just nothing to open.
     */
    [, $user] = fabricatorWhoCanImport();

    $project = createProject($user);
    uploadOnto($this, $user, $project)->assertSessionHasNoErrors();

    $materialListFile = $project->materialListFiles()->sole();
    Storage::disk(MaterialListFile::DISK)->delete($materialListFile->path);

    $this->actingAs($user);

    $bom = $this->getJson(route('download.batch.bom'))->assertOk()->json('batchBom');

    expect($bom['files'][0]['downloadable'])->toBeFalse()
        ->and($bom['files'][0]['deletable'])->toBeTrue();

    //Opening it is a 404 rather than a stream of nothing
    $this->get(route('material.list.file.download', $materialListFile))->assertNotFound();

    //And it still comes off cleanly
    $this->from('/nesting')
        ->delete(route('material.list.file.destroy', $materialListFile))
        ->assertSessionHasNoErrors();

    expect(MaterialListFile::find($materialListFile->id))->toBeNull();
});

it('leaves no file behind when an upload imports nothing and the project is discarded', function () {
    /*
     * ProjectController deletes the empty shell so the user can retry with the same name. The rows
     * cascade off the project and the files on disk do not, so this is the one place an orphan could
     * be left - and nothing points at it to ever find it again.
     */
    [, $user] = fabricatorWhoCanImport();

    /*
     * A workbook the templates read but which produces no material: the price book is empty, so
     * every line matches nothing. seedMasterMaterials() ran for the admin business above, and this
     * one has its own products - of which there are none.
     */
    \App\Models\Product::query()->delete();

    createProjectFromUpload($this, $user, 'Nothing imports');

    expect(Project::where('name', 'Nothing imports')->exists())->toBeFalse()
        ->and(MaterialListFile::count())->toBe(0)
        ->and(Storage::disk(MaterialListFile::DISK)->allFiles(MaterialListFile::DIRECTORY))->toBe([]);
});
