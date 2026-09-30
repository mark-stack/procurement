<?php

use App\Models\Template;
use App\Services\SpreadsheetGrid;
use App\Services\TemplateChecks;
use App\Services\TemplateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/*
 * Recording a template means reading a customer's spreadsheet by eye and typing a column of cell
 * references out of it. These cover the two halves of doing it for them: placing the cells, and
 * checking that what has been placed is really what the file holds.
 *
 * It matters more than it did. A template is now what the importer matches uploads against, so the
 * form these fill in is the whole of what makes a new spreadsheet importable.
 */

function sampleUpload(string $file): UploadedFile
{
    return new UploadedFile(
        public_path('examples/'.$file),
        $file,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    );
}

/**
 * One OpenAI answer, in the shape Structured Outputs returns it.
 *
 * @param  array<string, mixed>  $answer
 */
function fakeOpenAiAnswer(array $answer): void
{
    config(['openai.key' => 'sk-test', 'openai.model' => 'gpt-5-mini']);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['content' => json_encode([
                    'name' => 'A read of the sheet',
                    'source' => 'PROJECT_MANAGER',
                    'type' => 'CAD_BILL_OF_MATERIALS',
                    'expected_heading_labels' => [],
                    'heading_cell' => null,
                    'first_description_cell' => null,
                    'first_material_cell' => null,
                    'first_grade_cell' => null,
                    'first_surface_cell' => null,
                    'first_length_required_cell' => null,
                    'first_width_required_cell' => null,
                    'first_sub_qty_cell' => null,
                    'length_width_units' => 'mm',
                    'confidence' => 'medium',
                    'notes' => 'Heading row 1, data from row 2.',
                    ...$answer,
                ])],
            ]],
        ]),
    ]);
}

/**
 * A spreadsheet no recorded template describes, so there is nothing to place arithmetically and
 * the model's answer is all there is. This is the case the whole feature exists for.
 */
function unknownTemplateUpload(): UploadedFile
{
    $csv = <<<'CSV'
    Acme Fabrication,,,
    Item,Count,Cut Length,Section
    1,4,6500,310UB40
    2,2,3200,250PFC
    CSV;

    return UploadedFile::fake()->createWithContent('acme.csv', $csv);
}

it('fills the form in from a spreadsheet an existing template already describes, without asking a model anything', function () {
    /**
     * A recorded template gives every column as an offset from its own heading cell, so for a file
     * whose heading row matches, the cell references are arithmetic - exactly the ones the importer
     * will read. There is nothing for a model to improve on, and asking would spend money to be
     * told something less reliable.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    recordExampleTemplates($business);

    config(['openai.key' => null]);
    Http::preventStrayRequests();

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.propose', $business->id),
        ['sample' => sampleUpload('tekla_assembly_list.xlsx')],
    )->assertRedirect();

    $proposal = session('templateProposal');

    expect($proposal['ok'])->toBeTrue()
        ->and($proposal['detection']['name'])->toBe('Assembly List')
        ->and($proposal['detection']['heading_row'])->toBe(6)
        //Profile, Length (mm) and Qty, at offsets 3, 18 and 1 from column A of row 6
        ->and($proposal['prefill']['heading_cell'])->toBe('A6')
        ->and($proposal['prefill']['first_description_cell'])->toBe('D7')
        ->and($proposal['prefill']['first_length_required_cell'])->toBe('S7')
        ->and($proposal['prefill']['first_sub_qty_cell'])->toBe('B7')
        //The template has no material or width column, so neither is invented
        ->and($proposal['prefill']['first_material_cell'])->toBeNull()
        ->and($proposal['prefill']['first_width_required_cell'])->toBeNull()
        ->and($proposal['prefill']['length_width_units'])->toBe('mm')
        //And the labels come with it, so the form is a working template rather than a column of cells
        ->and($proposal['prefill']['expected_heading_labels'])->toContain('Length (mm)')
        ->and($proposal['provenance']['first_description_cell'])->toBe('detection')
        ->and($proposal['ai']['used'])->toBeFalse();
});

it('warns that a file which already imports would be imported twice', function () {
    /**
     * Only true since templates started driving detection, and the most useful thing the screen
     * can say: a second template for a table that already matches one reads the same rows again,
     * and every row of that upload is saved twice.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    recordExampleTemplates($business);

    config(['openai.key' => null]);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.propose', $business->id),
        ['sample' => sampleUpload('tekla_assembly_list.xlsx')],
    );

    expect(collect(session('templateProposal')['findings'])->pluck('message')->implode("\n"))
        ->toContain('This file already imports under "Assembly List"')
        ->toContain('import them twice');
});

it('reads the cells back out of the sample and says what each one holds', function () {
    //The point of the check is that a cell reference can be perfectly well formed and still be the
    //wrong column. Only the file can say otherwise.
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    recordExampleTemplates($business);

    config(['openai.key' => null]);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.propose', $business->id),
        ['sample' => sampleUpload('tekla_assembly_list.xlsx')],
    );

    $passed = collect(session('templateProposal')['findings'])
        ->where('level', 'ok')
        ->pluck('message')
        ->implode("\n");

    expect($passed)->toContain('D7 holds "250PFC"')
        ->and($passed)->toContain('S7 holds "9000"')
        ->and($passed)->toContain('B7 holds "4"');
});

it('would be a disaster if a model\'s reading quietly replaced what detection worked out', function () {
    /**
     * Detection's answer is the one the importer uses. A model that reads the sheet differently is
     * worth hearing - it usually means the file has drifted from the entry it matches - but it does
     * not get to move the cells, and the disagreement has to be visible rather than silently lost.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    recordExampleTemplates($business);

    fakeOpenAiAnswer([
        'first_description_cell' => 'I7',
        'first_sub_qty_cell' => 'B7',
        'first_length_required_cell' => 'S7',
    ]);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.propose', $business->id),
        ['sample' => sampleUpload('tekla_assembly_list.xlsx')],
    );

    $proposal = session('templateProposal');

    expect($proposal['prefill']['first_description_cell'])->toBe('D7')
        ->and($proposal['provenance']['first_description_cell'])->toBe('detection')
        ->and(collect($proposal['findings'])->pluck('message')->implode("\n"))
        ->toContain('OpenAI read the description column from I7');
});

it('asks OpenAI about a spreadsheet no template describes, and marks the answer as a suggestion', function () {
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    fakeOpenAiAnswer([
        'name' => 'Acme Cut List',
        'expected_heading_labels' => ['Item', 'Count', 'Cut Length', 'Section'],
        'heading_cell' => 'a2',
        'first_description_cell' => 'd3',
        'first_length_required_cell' => 'C3',
        'first_sub_qty_cell' => 'B3',
    ]);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.propose', $business->id),
        ['sample' => unknownTemplateUpload()],
    );

    $proposal = session('templateProposal');

    expect($proposal['detection'])->toBeNull()
        ->and($proposal['prefill']['name'])->toBe('Acme Cut List')
        //Cell references are stored upper cased, so a lower case answer is not a different cell
        ->and($proposal['prefill']['first_description_cell'])->toBe('D3')
        ->and($proposal['prefill']['heading_cell'])->toBe('A2')
        //The labels are the half that makes the record detect, so the model has to answer them too
        ->and($proposal['prefill']['expected_heading_labels'])->toBe(['Item', 'Count', 'Cut Length', 'Section'])
        ->and($proposal['provenance']['first_description_cell'])->toBe('ai')
        ->and($proposal['ai']['used'])->toBeTrue()
        ->and($proposal['ai']['confidence'])->toBe('medium')
        //Saving the form is now the whole of what makes this file importable
        ->and(collect($proposal['findings'])->pluck('message')->implode("\n"))
        ->toContain('mark it Active and save, and uploads of this spreadsheet will import');

    //The sheet is described to the model by cell reference, not pasted in as a wall of values
    Http::assertSent(function ($request) {
        return str_contains($request['messages'][1]['content'], 'R2: A="Item" B="Count" C="Cut Length" D="Section"')
            && $request['response_format']['json_schema']['strict'] === true;
    });
});

it('records what it proposed for a file nothing matched, and that file then imports', function () {
    /**
     * The end of the whole exercise. A spreadsheet that matched nothing is read, the form it fills
     * in is submitted unchanged, and the same spreadsheet now imports - with no code change and no
     * deploy between the two halves of this test.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    //There has to be a catalogue to match the descriptions against for the test below to pass
    seedMasterMaterials();

    fakeOpenAiAnswer([
        'name' => 'Acme Cut List',
        'expected_heading_labels' => ['Item', 'Count', 'Cut Length', 'Section'],
        'heading_cell' => 'A2',
        'first_description_cell' => 'D3',
        'first_length_required_cell' => 'C3',
        'first_sub_qty_cell' => 'B3',
    ]);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.propose', $business->id),
        ['sample' => unknownTemplateUpload()],
    );

    $prefill = session('templateProposal')['prefill'];

    /*
     * The form the proposal filled in, tried against the file it was read from. Creating a template
     * is gated on that passing, so the path from "read a spreadsheet" to "that spreadsheet imports"
     * now runs through here - which is the half of this test that used to be a matter of trust.
     *
     * The key is dropped first: the fake above answers with a proposal and not a review of extracted
     * rows, and what a model makes of those is AdminTemplateGateTest's subject. Unasked, that check
     * is skipped, and a skip blocks nothing.
     */
    config(['openai.key' => null]);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.test', $business->id),
        [...$prefill, 'sample' => unknownTemplateUpload()],
    )->assertSessionHasNoErrors();

    expect(session('templateTest')['passed'])->toBeTrue();

    /*
     * The proposal, submitted as the form would submit it. Only the things a spreadsheet cannot be
     * read for are added: whether it is live, the screenshot, and the proof that it was tested.
     */
    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        [
            ...$prefill,
            'active' => true,
            'screenshot' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
            'template_test_token' => session('templateTest')['token'],
        ],
    )->assertSessionHasNoErrors();

    //As a member of that business, not as the admin who recorded it
    $this->actingAs(createUser(2, $business, false, true));

    expect((new TemplateService)->invalidFiles([unknownTemplateUpload()]))->toBe([]);
});

it('would be a disaster if OpenAI being down took the whole form with it', function () {
    /**
     * The suggestion is a convenience. A rate limit, a bad key or a timeout has to leave the admin
     * with whatever detection could place and a sentence about what happened - not a 500 and an
     * empty form.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    recordExampleTemplates($business);

    config(['openai.key' => 'sk-test']);
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Rate limit reached']], 429)]);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.propose', $business->id),
        ['sample' => sampleUpload('tekla_assembly_list.xlsx')],
    )->assertRedirect()->assertSessionHasNoErrors();

    $proposal = session('templateProposal');

    expect($proposal['ok'])->toBeTrue()
        ->and($proposal['prefill']['first_description_cell'])->toBe('D7')
        ->and($proposal['ai']['used'])->toBeFalse()
        ->and($proposal['ai']['error'])->toContain('Rate limit reached');
});

it('says so when the upload is not a spreadsheet at all', function () {
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    config(['openai.key' => null]);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.propose', $business->id),
        ['sample' => UploadedFile::fake()->createWithContent('broken.xlsx', 'this is not a spreadsheet')],
    )->assertSessionHasNoErrors();

    expect(session('templateProposal')['ok'])->toBeFalse();
});

it('refuses an upload that is not a spreadsheet file type', function () {
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.propose', $business->id),
        ['sample' => UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload')],
    )->assertSessionHasErrors('sample');
});

it('would be a disaster if anyone but an admin could spend money reading spreadsheets', function () {
    $business = createBusiness('Business A', true);
    $user = createUser(1, $business, false, true);

    config(['openai.key' => 'sk-test']);
    Http::preventStrayRequests();

    $this->actingAs($user)->post(
        route('admin.businesses.templates.propose', $business->id),
        ['sample' => sampleUpload('tekla_assembly_list.xlsx')],
        //AdminMiddleware sends a non-admin home rather than to the route
    )->assertRedirect('/');

    expect(session('templateProposal'))->toBeNull();
});

it('suggests a name this business has not already used', function () {
    //Names are unique per business, so a suggestion that collides wastes the round trip the
    //parser just saved
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    //The examples include one named "Assembly List", which is what this file detects as
    recordExampleTemplates($business);

    config(['openai.key' => null]);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.propose', $business->id),
        ['sample' => sampleUpload('tekla_assembly_list.xlsx')],
    );

    expect(session('templateProposal')['prefill']['name'])->toBe('Assembly List 2');
});

it('finds every table in a file that carries more than one', function () {
    //A Tekla report holds four. An admin who records the first and walks away has recorded a
    //quarter of the spreadsheet.
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);
    recordExampleTemplates($business);

    config(['openai.key' => null]);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.propose', $business->id),
        ['sample' => sampleUpload('tekla_hot_rolled.xlsx')],
    );

    expect(collect(session('templateProposal')['findings'])->pluck('message')->implode("\n"))
        ->toContain('It also carries');
});

/*
 * The checks themselves, which the parser runs for you and the store request runs again on submit.
 */

it('reads a cell that holds the wrong kind of value as the wrong column', function () {
    $grid = new SpreadsheetGrid([
        ['Mark', 'Qty', 'Profile', 'Length'],
        ['A1', 'BEAM', '250PFC', '9000'],
    ]);

    $messages = collect((new TemplateChecks)->againstSample([
        'source' => null,
        'config_label' => null,
        //Qty and Profile the wrong way round: both are real cells, and one is not a quantity
        'first_description_cell' => 'B2',
        'first_sub_qty_cell' => 'C2',
        'first_length_required_cell' => 'D2',
        'length_width_units' => 'mm',
    ], $grid))->pluck('message')->implode("\n");

    expect($messages)->toContain('C2 holds "250PFC", which is not a number')
        ->and($messages)->toContain('B2 holds "BEAM"');
});

it('reads a cell beyond the end of the sheet as an error', function () {
    $grid = new SpreadsheetGrid([
        ['Mark', 'Qty'],
        ['A1', '4'],
    ]);

    $findings = (new TemplateChecks)->againstSample([
        'first_description_cell' => 'A2',
        'first_sub_qty_cell' => 'Z2',
    ], $grid);

    expect(TemplateChecks::blocking($findings))
        ->toHaveKey('first_sub_qty_cell')
        ->and(TemplateChecks::blocking($findings)['first_sub_qty_cell'])
        ->toContain('outside this spreadsheet');
});

it('reads a length column of thousands as millimetres whatever the form says', function () {
    $grid = new SpreadsheetGrid([
        ['Profile', 'Qty', 'Length'],
        ['250PFC', '4', '9000'],
        ['310UB40', '2', '12000'],
        ['150UC30', '6', '3600'],
    ]);

    $messages = collect((new TemplateChecks)->againstSample([
        'first_description_cell' => 'A2',
        'first_sub_qty_cell' => 'B2',
        'first_length_required_cell' => 'C2',
        'length_width_units' => 'm',
    ], $grid))->pluck('message')->implode("\n");

    expect($messages)->toContain('reads like mm');
});
