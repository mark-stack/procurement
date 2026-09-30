<?php

use App\Models\Business;
use App\Models\Template;
use App\Models\User;
use App\Services\TemplateTestCertificate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/*
 * A template cannot be created until it has been tested.
 *
 * Every other check on that screen is about the record - is that a cell, is it on the first row of
 * data, does the cell hold a number. A record can pass all of them, match its heading row exactly, and
 * still hand a customer an empty project: the descriptions it pulls out match nothing in the master
 * materials list, or there is no length on the rows, or it is reading the column of assembly marks and
 * every "material" it finds is "A1". None of that is visible without running the importer over a real
 * spreadsheet and looking at what comes out.
 *
 * So running it is now compulsory, and these cover the gate rather than the report:
 *
 *  - what the test hands back when it passes, and that it hands back nothing when it does not;
 *  - that the save refuses a template with no proof, with proof of a different template, or with proof
 *    issued for another business - because a disabled button is a courtesy and this is the gate;
 *  - the named checks the screen shows as ticks, including the ones only a model can answer.
 *
 * AdminTemplateTestRunTest covers what the test reports. This is about what it permits.
 */

/**
 * A sheet whose every row is a real section, so a correct template passes every check against it.
 */
function gateSheet(): UploadedFile
{
    $csv = <<<'CSV'
    Profile,Qty,Length (mm)
    250PFC,4,9000
    310UB40,6,12000
    CSV;

    return UploadedFile::fake()->createWithContent('acme.csv', $csv);
}

/**
 * The template that reads gateSheet(), as the form posts it: the fields that describe the table and
 * nothing else. This is exactly what a test is run with.
 *
 * @return array<string, mixed>
 */
function gateForm(array $overrides = []): array
{
    return array_merge([
        'source' => 'TEKLA',
        'type' => 'CAD_BILL_OF_MATERIALS',
        'heading_cell' => 'A1',
        'expected_heading_labels' => ['Profile', 'Qty', 'Length (mm)'],
        'first_description_cell' => 'A2',
        'first_sub_qty_cell' => 'B2',
        'first_length_required_cell' => 'C2',
        'assembly_mark_rule' => 'NONE',
        'length_width_units' => 'mm',
    ], $overrides);
}

/**
 * The same values, plus the four fields a save needs and a test does not.
 *
 * @return array<string, mixed>
 */
function gateCreatePayload(array $overrides = []): array
{
    return array_merge(gateForm(), [
        'name' => 'Acme Profile List',
        'screenshot' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        'web_source' => null,
        'active' => true,
    ], $overrides);
}

/**
 * A business with a catalogue and an admin looking at its template screen.
 *
 * @return array{0: Business, 1: User}
 */
function gateAdmin(string $name = 'Business A'): array
{
    $business = createBusiness($name, true);
    $admin = createUser(1, $business, true, true);
    //Whose catalogue the descriptions are matched against
    createUser(2, $business, false, true);

    seedMasterMaterials();

    return [$business, $admin];
}

/**
 * Press Test, and hand back what came off it.
 *
 * @param  array<string, mixed>  $form
 * @return array<string, mixed>
 */
function runGateTest(Business $business, User $admin, ?array $form = null): array
{
    test()->actingAs($admin)->post(
        route('admin.businesses.templates.test', $business->id),
        [...($form ?? gateForm()), 'sample' => gateSheet()],
    )->assertSessionHasNoErrors();

    return session('templateTest');
}

/**
 * One review from OpenAI, in the shape Structured Outputs returns it. Every field of the schema is
 * always present - see ExtractedMaterialsReview::schema() - so the defaults here are a happy review
 * and a test overrides only what it is about.
 *
 * @param  array<string, mixed>  $answer
 */
function fakeExtractionReview(array $answer = []): void
{
    config(['openai.key' => 'sk-test', 'openai.model' => 'gpt-5-mini']);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['content' => json_encode([
                    'looks_like_materials_list' => true,
                    'description_column_correct' => true,
                    'columns_aligned' => true,
                    'quantities_plausible' => true,
                    'lengths_plausible' => true,
                    'verdict' => 'valid',
                    'confidence' => 'high',
                    'summary' => 'Two hot rolled sections with quantities and cutting lengths.',
                    'issues' => [],
                    ...$answer,
                ])],
            ]],
        ]),
    ]);
}

/**
 * One named check off the result.
 *
 * @param  array<string, mixed>  $result
 * @return array<string, mixed>|null
 */
function gateCheck(array $result, string $key): ?array
{
    return collect($result['checks'])->firstWhere('key', $key);
}

it('would be a disaster if a template nobody had tested could be created', function () {
    /**
     * The whole gate. Every field of this payload is valid and the record is perfectly coherent - it
     * is the payload that used to store without a murmur, and the one that has never been shown to
     * import a single piece of steel.
     */
    [$business, $admin] = gateAdmin();

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        gateCreatePayload(),
    )->assertSessionHasErrors('template_test_token');

    expect(Template::count())->toBe(0);
});

it('creates a template once its test has passed', function () {
    //The other side of it: a test that passes hands back proof, and the proof is what lets the save
    //through. Nothing about the form changes in between.
    [$business, $admin] = gateAdmin();

    $result = runGateTest($business, $admin);

    expect($result['passed'])->toBeTrue()
        ->and($result['token'])->toBeString();

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        gateCreatePayload(['template_test_token' => $result['token']]),
    )->assertSessionHasNoErrors();

    expect(Template::count())->toBe(1)
        //The proof is not a column: the model is $guarded = [], so a token left in the payload is an insert
        ->and(Template::first()->getAttributes())->not->toHaveKey('template_test_token');
});

it('would be a disaster if proof from one template created a different one', function () {
    /**
     * Test it reading column C, save it reading column D. The token is a signature over the values
     * that were tried, so changing any of them after the test leaves the save with proof of something
     * else - which is the same thing the screen means when it calls a result stale.
     */
    [$business, $admin] = gateAdmin();

    $result = runGateTest($business, $admin);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        gateCreatePayload([
            'first_length_required_cell' => 'D2',
            'template_test_token' => $result['token'],
        ]),
    )->assertSessionHasErrors('template_test_token');

    expect(Template::count())->toBe(0);
});

it('would be a disaster if proof for one business created a template for another', function () {
    /**
     * The verdict on every row is about one business: the descriptions are matched against that
     * business's own catalogue, and its plan decides which products it may buy at all. A template
     * that imports six sections for one customer can import nothing for the next, so a pass earned
     * for one must not travel.
     */
    [$businessA, $admin] = gateAdmin('Business A');
    $businessB = createBusiness('Business B', true);

    $result = runGateTest($businessA, $admin);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $businessB->id),
        gateCreatePayload(['template_test_token' => $result['token']]),
    )->assertSessionHasErrors('template_test_token');

    expect(Template::count())->toBe(0);
});

it('would be a disaster if a case difference invalidated a passing test', function () {
    /**
     * "b7" and "B7" are the same cell, and the form upper cases what is typed into it. A token that
     * turned on which one was typed would refuse a template whose test passed, for a difference
     * nobody could see on the screen.
     */
    [$business, $admin] = gateAdmin();

    $result = runGateTest($business, $admin, gateForm(['first_description_cell' => 'a2']));

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        gateCreatePayload([
            'first_description_cell' => 'A2',
            'template_test_token' => $result['token'],
        ]),
    )->assertSessionHasNoErrors();

    expect(Template::count())->toBe(1);
});

it('hands back nothing to save with when the test does not pass', function () {
    /**
     * length_required is NOT NULL, so with no length column every row is dropped and the customer
     * gets an empty project. The record itself is valid and saves without complaint, which is the
     * failure this gate exists for - so there is no token to save it with.
     */
    [$business, $admin] = gateAdmin();

    $result = runGateTest($business, $admin, collect(gateForm())->except('first_length_required_cell')->all());

    expect($result['passed'])->toBeFalse()
        ->and($result['token'])->toBeNull()
        ->and(gateCheck($result, 'length_column')['status'])->toBe('fail')
        ->and(gateCheck($result, 'imports')['status'])->toBe('fail');
});

it('names every check it performed rather than reporting a quiet panel', function () {
    /**
     * "Nothing was mentioned" and "every check passed" are different statements, and a panel of prose
     * cannot tell them apart. The screen shows one line per check with a tick against it, so the
     * checks have to be enumerated and answered - including the ones that pass.
     */
    [$business, $admin] = gateAdmin();

    $result = runGateTest($business, $admin);

    $statuses = collect($result['checks'])->pluck('status', 'key');

    expect($statuses['sample_read'])->toBe('pass')
        ->and($statuses['table_found'])->toBe('pass')
        ->and($statuses['rows_extracted'])->toBe('pass')
        ->and($statuses['length_column'])->toBe('pass')
        ->and($statuses['sub_qty_column'])->toBe('pass')
        ->and($statuses['catalogue'])->toBe('pass')
        ->and($statuses['recognised_as_materials'])->toBe('pass')
        ->and($statuses['in_master_materials'])->toBe('pass')
        ->and($statuses['lengths_present'])->toBe('pass')
        ->and($statuses['imports'])->toBe('pass')
        //Nothing failed, so the whole thing passed
        ->and(collect($result['checks'])->pluck('status')->contains('fail'))->toBeFalse()
        //Every one of them says why, because a tick with no sentence behind it is not a check
        ->and(collect($result['checks'])->every(fn (array $check) => trim($check['detail']) !== ''))->toBeTrue();
});

it('says which rows are the problem rather than failing the whole template for one of them', function () {
    /**
     * A real bill of materials holds rows that are not steel. Failing a template because one of forty
     * rows is a note would stop an admin recording a spreadsheet that is genuinely shaped that way -
     * so some of the rows falling at a gate warns, and all of them failing is what says the column is
     * being read from the wrong place.
     */
    [$business, $admin] = gateAdmin();

    //Happy, so this is about the row that is not steel and not about what a model made of it
    fakeExtractionReview();

    $sheet = UploadedFile::fake()->createWithContent('acme.csv', implode("\n", [
        'Profile,Qty,Length (mm)',
        '250PFC,4,9000',
        'Unobtainium widget,2,3000',
    ]));

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.test', $business->id),
        [...gateForm(), 'sample' => $sheet],
    )->assertSessionHasNoErrors();

    $result = session('templateTest');

    expect(gateCheck($result, 'recognised_as_materials')['status'])->toBe('warning')
        ->and(gateCheck($result, 'imports')['status'])->toBe('pass')
        //One bad row out of two is a warning, and a warning has never stopped a save
        ->and($result['passed'])->toBeTrue()
        ->and($result['token'])->toBeString();
});

it('would be a disaster if a template reading the wrong column passed because the wrong column held strings', function () {
    /**
     * The failure nothing deterministic can catch. A column of assembly marks is a column of plausible
     * strings, a column of weights is a column of plausible numbers, and a "Total" row is a material
     * with an unusual name - all of which get through every check about the record. Reading the
     * extracted rows as a whole is what catches it, so a review that says these are not materials
     * refuses the template.
     */
    [$business, $admin] = gateAdmin();

    fakeExtractionReview([
        'looks_like_materials_list' => false,
        'description_column_correct' => false,
        'verdict' => 'invalid',
        'summary' => 'These are assembly marks, not materials.',
        'issues' => ['Every description is a mark like "A1" rather than a profile.'],
    ]);

    $result = runGateTest($business, $admin);

    expect(gateCheck($result, 'ai_materials_list')['status'])->toBe('fail')
        ->and(gateCheck($result, 'ai_materials_list')['detail'])->toBe('These are assembly marks, not materials.')
        ->and(gateCheck($result, 'ai_description_column')['status'])->toBe('warning')
        ->and($result['passed'])->toBeFalse()
        ->and($result['token'])->toBeNull()
        ->and($result['review']['used'])->toBeTrue()
        ->and($result['review']['issues'])->toBe(['Every description is a mark like "A1" rather than a profile.']);

    //And the save agrees, because there is nothing to save it with
    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        gateCreatePayload(),
    )->assertSessionHasErrors('template_test_token');
});

it('passes a template the review is happy with, and says which model said so', function () {
    [$business, $admin] = gateAdmin();

    fakeExtractionReview();

    $result = runGateTest($business, $admin);

    expect(gateCheck($result, 'ai_materials_list')['status'])->toBe('pass')
        ->and($result['review']['model'])->toBe('gpt-5-mini')
        ->and($result['review']['verdict'])->toBe('valid')
        ->and($result['review']['confidence'])->toBe('high')
        ->and($result['passed'])->toBeTrue();
});

it('warns rather than refuses when the review is only uneasy', function () {
    /**
     * "suspect" is the model saying this would mostly work and something specific is wrong. Worth
     * reading, and not something to stop a template on: a model's unease about a spreadsheet it has
     * seen forty rows of does not outrank an admin who knows the customer.
     */
    [$business, $admin] = gateAdmin();

    fakeExtractionReview([
        'verdict' => 'suspect',
        'columns_aligned' => false,
        'summary' => 'Plausible, but the quantity and length columns may be the wrong way round.',
    ]);

    $result = runGateTest($business, $admin);

    expect(gateCheck($result, 'ai_materials_list')['status'])->toBe('warning')
        ->and(gateCheck($result, 'ai_columns_aligned')['status'])->toBe('warning')
        ->and($result['passed'])->toBeTrue();
});

it('would be a disaster if being unable to reach OpenAI stopped a template being recorded', function () {
    /**
     * Not being able to ask is not evidence against a template. With no key configured - which is
     * every machine these tests run on - the review is skipped, and a skip is neither a tick nor a
     * cross: it says out loud that the check could not be asked, and it blocks nothing.
     */
    [$business, $admin] = gateAdmin();

    config(['openai.key' => null]);

    $result = runGateTest($business, $admin);

    expect(gateCheck($result, 'ai_materials_list')['status'])->toBe('skipped')
        ->and(gateCheck($result, 'ai_materials_list')['detail'])->toContain('OPENAI_API_KEY is not set')
        //And the other four are not claimed at all, because nothing answered them
        ->and(gateCheck($result, 'ai_columns_aligned'))->toBeNull()
        ->and($result['passed'])->toBeTrue()
        ->and($result['review']['used'])->toBeFalse();
});

it('does not spend money on a review when nothing was extracted', function () {
    //There is nothing to read, and the checks that failed have already said why
    [$business, $admin] = gateAdmin();

    fakeExtractionReview();

    $result = runGateTest($business, $admin, gateForm(['expected_heading_labels' => ['Section', 'Count', 'Cut Length']]));

    Http::assertNothingSent();

    expect($result['review']['used'])->toBeFalse()
        ->and(gateCheck($result, 'ai_materials_list')['status'])->toBe('skipped')
        ->and($result['passed'])->toBeFalse();
});

it('reports a sample that is not a spreadsheet as a failed check rather than an empty checklist', function () {
    /**
     * A refusal has to answer "may this be created" too, and the honest answer is a list with one
     * failed check on it - not a list of unasked questions, which reads as a list of things that
     * are fine.
     */
    [$business, $admin] = gateAdmin();

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.test', $business->id),
        [...gateForm(), 'sample' => UploadedFile::fake()->createWithContent('broken.xlsx', 'not a spreadsheet')],
    )->assertSessionHasNoErrors();

    $result = session('templateTest');

    expect($result['ok'])->toBeFalse()
        ->and($result['passed'])->toBeFalse()
        ->and($result['token'])->toBeNull()
        ->and($result['checks'])->toHaveCount(1)
        ->and($result['checks'][0]['status'])->toBe('fail');
});

it('would be a disaster if a second template for the same table could be recorded', function () {
    /**
     * Every active template is matched against every upload, so two that find the same heading row
     * both read that table and both import it - the customer orders twice the steel, off two records
     * that each look perfectly correct on this screen.
     *
     * It became worth refusing when creating a template started switching it on: there used to be a
     * deliberate step between recording one and it meeting a real upload, and this is what that step
     * was for.
     */
    [$business, $admin] = gateAdmin();

    //One already recorded and live, reading exactly this file
    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        gateCreatePayload(['template_test_token' => runGateTest($business, $admin)['token']]),
    )->assertSessionHasNoErrors();

    $result = runGateTest($business, $admin);

    expect(gateCheck($result, 'not_already_read')['status'])->toBe('fail')
        ->and(gateCheck($result, 'not_already_read')['detail'])->toContain('Acme Profile List')
        ->and(gateCheck($result, 'not_already_read')['detail'])->toContain('read twice and ordered twice')
        ->and($result['passed'])->toBeFalse()
        ->and($result['token'])->toBeNull();

    //And so the second one cannot be created
    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        gateCreatePayload(['name' => 'Acme Profile List 2']),
    )->assertSessionHasErrors('template_test_token');

    expect(Template::count())->toBe(1);
});

it('does not report a template as a duplicate of itself', function () {
    /**
     * Editing a recorded template and testing it against the file it already reads is the ordinary
     * thing to do. Finding itself and refusing on that would make every template uneditable the
     * moment it went live.
     */
    [$business, $admin] = gateAdmin();

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        gateCreatePayload(['template_test_token' => runGateTest($business, $admin)['token']]),
    );

    $template = $business->templates()->first();

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.test', $business->id),
        [...gateForm(), 'sample' => gateSheet(), 'editing_template_id' => $template->id],
    )->assertSessionHasNoErrors();

    $result = session('templateTest');

    expect(gateCheck($result, 'not_already_read')['status'])->toBe('pass')
        ->and($result['passed'])->toBeTrue();
});

it('allows a second template for a different table in the same file', function () {
    /**
     * One Tekla report holds four bands and a bolt summary, and each is its own template. Reading the
     * same file off a different heading row is the feature, not the fault - so it is said out loud
     * and nothing is refused.
     */
    [$business, $admin] = gateAdmin();

    //A live template that matches a heading row this one does not
    Template::factory()->for($business)->create([
        'name' => 'Acme Bolt Summary',
        'active' => true,
        'heading_cell' => 'A1',
        'expected_heading_labels' => ['Bolt', 'Grade'],
        'first_description_cell' => 'A2',
        'first_length_required_cell' => 'B2',
    ]);

    $sheet = UploadedFile::fake()->createWithContent('acme.csv', implode("\n", [
        'Bolt,Grade',
        'M20,8.8',
        '',
        'Profile,Qty,Length (mm)',
        '250PFC,4,9000',
    ]));

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.test', $business->id),
        [...gateForm(['first_description_cell' => 'A5', 'first_sub_qty_cell' => 'B5', 'first_length_required_cell' => 'C5', 'heading_cell' => 'A4']), 'sample' => $sheet],
    )->assertSessionHasNoErrors();

    $result = session('templateTest');

    expect(gateCheck($result, 'not_already_read')['status'])->toBe('warning')
        ->and(gateCheck($result, 'not_already_read')['detail'])->toContain('on other rows')
        //A warning has never stopped a save
        ->and($result['passed'])->toBeTrue();
});

it('does not ask for a test to correct a recorded template', function () {
    /**
     * The gate is on recording a template, not on editing one. Insisting on a passing test for every
     * edit would mean having a sample spreadsheet to hand to fix a spelling mistake in a name, and an
     * admin who cannot correct a name without one leaves it wrong.
     */
    [$business, $admin] = gateAdmin();

    $template = Template::factory()->for($business)->create(['name' => 'Acme Profile List']);

    $this->actingAs($admin)->put(
        route('admin.businesses.templates.update', [$business->id, $template->id]),
        gateCreatePayload(['name' => 'Acme Profile List v2']),
    )->assertSessionHasNoErrors();

    expect($template->fresh()->name)->toBe('Acme Profile List v2');
});

it('would be a disaster if the test request itself demanded proof of a test', function () {
    //The test is what issues the proof, so requiring it there is a form nobody can ever submit
    [$business, $admin] = gateAdmin();

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.test', $business->id),
        [...gateForm(), 'sample' => gateSheet()],
    )->assertSessionHasNoErrors();

    expect(session('templateTest')['passed'])->toBeTrue();
});

it('refuses proof that was not signed by this application', function () {
    //A token is an HMAC under the application key, so a made up one is not a near miss
    [$business, $admin] = gateAdmin();

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        gateCreatePayload(['template_test_token' => str_repeat('a', 64)]),
    )->assertSessionHasErrors('template_test_token');

    expect(Template::count())->toBe(0)
        //And the real one for these values is accepted, so the refusal is about the signature
        ->and((new TemplateTestCertificate)->matches(
            (new TemplateTestCertificate)->issue($business, gateForm()),
            $business,
            gateCreatePayload(),
        ))->toBeTrue();
});
