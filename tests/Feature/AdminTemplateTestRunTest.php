<?php

use App\Models\Project;
use App\Models\RawMaterialQuote;
use App\Models\Template;
use Illuminate\Http\UploadedFile;
use Tests\Support\ExampleTemplates;

/*
 * Test, on the template screen: run the importer over a sample spreadsheet with the values in the
 * form, and list every material it would extract.
 *
 * The checks that were already there answer questions about the record - is this a cell, is it on
 * the first row of data, does the cell hold a number. None of them can answer the question an admin
 * actually has, which is whether the thing imports steel. A template can pass every check, match its
 * heading row exactly, and still hand a customer an empty project because the descriptions it pulls
 * out match nothing in the master materials list, or because there is no length on the rows.
 *
 * So these cover both halves: the rows that come out, and the verdict on each one.
 */

/**
 * One of the workbooks in public/examples, as an upload.
 */
function trialUpload(string $file): UploadedFile
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
 * The template form, filled in with one of the example templates.
 *
 * Read out of the fixture the example workbooks are parsed with rather than copied, so a column that
 * moves in one moves in both - these tests are about what the importer extracts, and a hand-copied
 * template quietly stops being the one that reads the file.
 *
 * @return array<string, mixed>
 */
function trialForm(string $name): array
{
    return collect((new ExampleTemplates)->definitions())->firstWhere('name', $name);
}

/**
 * A two-row sheet with a heading row, where the second material is not a material at all.
 */
function trialSheet(): UploadedFile
{
    $csv = <<<'CSV'
    Profile,Qty,Length (mm)
    250PFC,4,9000
    Unobtainium widget,2,3000
    CSV;

    return UploadedFile::fake()->createWithContent('acme.csv', $csv);
}

/**
 * The form for trialSheet(), which is the smallest template that reads anything.
 *
 * @return array<string, mixed>
 */
function trialSheetForm(): array
{
    return [
        'source' => 'TEKLA',
        'type' => 'CAD_BILL_OF_MATERIALS',
        'heading_cell' => 'A1',
        'expected_heading_labels' => ['Profile', 'Qty', 'Length (mm)'],
        'first_description_cell' => 'A2',
        'first_sub_qty_cell' => 'B2',
        'first_length_required_cell' => 'C2',
        'assembly_mark_rule' => 'NONE',
        'length_width_units' => 'mm',
    ];
}

/**
 * @param  array<string, mixed>  $form
 */
function runTrial(array $form, UploadedFile $sample): array
{
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);
    //The customer whose catalogue the descriptions are matched against
    createUser(2, $business, false, true);

    test()->actingAs($admin)->post(
        route('admin.businesses.templates.test', $business->id),
        [...$form, 'sample' => $sample],
    )->assertSessionHasNoErrors();

    return session('templateTest');
}

it('says when the extraction stopped short of the rows under the heading', function () {
    /**
     * Under-extraction is the one failure on this screen with no symptom of its own: a template
     * that reads half a table reports the rows it did read, they all import, and every other check
     * is a tick. Nothing compared what came out against what was there to come out.
     *
     * The stop rule here is a word that appears half way down, which is the ordinary way of getting
     * it wrong - a "Total" that is a subtotal.
     */
    seedMasterMaterials();

    $sheet = UploadedFile::fake()->createWithContent('short.csv', <<<'CSV'
    Profile,Qty,Length (mm)
    250PFC,4,9000
    Total,4,9000
    310UB40,2,12000
    150UC30,6,3600
    CSV);

    $result = runTrial([...trialSheetForm(), 'is_last_data_row' => 'Total'], $sheet);

    $check = collect($result['checks'])->firstWhere('key', 'rows_cover_table');

    expect($result['summary']['extracted'])->toBe(1)
        ->and($check['status'])->toBe('warning')
        ->and($check['detail'])->toContain('4 rows')
        ->and($check['detail'])->toContain('stop rule')
        //A warning, never a refusal: a sheet is allowed to hold rows we do not want
        ->and($result['passed'])->toBeTrue();
});

it('lists every material a template would import, before the template is saved', function () {
    /**
     * The whole point: the rows below came out of CsvService itself, at the offsets the form
     * describes, and not one of them was written anywhere.
     */
    seedMasterMaterials();

    $result = runTrial(trialForm('Assembly List'), trialUpload('tekla_assembly_list.xlsx'));

    expect($result['ok'])->toBeTrue()
        ->and(collect($result['rows'])->pluck('description')->all())->toBe([
            '250PFC',
            '310UB40',
            '150UC30',
            '100x100x10EA',
            '100x75x6UA',
            'CHS88.9x3.2',
        ])
        //The columns beside the description, which are what a column one out shows up in
        ->and(collect($result['rows'])->pluck('sub_qty')->all())->toBe([4.0, 6.0, 2.0, 12.0, 8.0, 5.0])
        ->and(collect($result['rows'])->pluck('length_required')->all())->toBe([9000.0, 12000.0, 3600.0, 2400.0, 1800.0, 2700.0])
        ->and(collect($result['rows'])->pluck('assembly_mark')->all())->toBe(['A1', 'A2', 'A3', 'A4', 'A5', 'A6'])
        /*
         * Every one of them is a real section, so every one of them reaches the catalogue - five
         * outright, and the sixth by asking.
         *
         * This template records no grade column, so "CHS88.9x3.2" arrives naming no grade, and
         * since the C350L0 light tube series was stocked on 2026-10-07 we carry that size twice:
         * as C250 galvanised pipe and as C350 black tube. Two products match one description, so
         * the row still imports and is put in front of the customer to choose between them, which
         * is what "clarify" is.
         *
         * tekla_hot_rolled.xlsx is the same section read through a template that DOES record a
         * grade column, and it lands on one product without asking anybody. The pair is the
         * clearest statement there is of what recording that column buys.
         */
        ->and(collect($result['rows'])->pluck('status')->all())->toBe([
            'imports',
            'imports',
            'imports',
            'imports',
            'imports',
            'clarify',
        ])
        //A row that needs clarifying is still a row that imports
        ->and($result['summary']['extracted'])->toBe(6)
        ->and($result['headline'])->toBe('6 of 6 extracted rows would import.');

    //Nothing written: not the template, and not a single material row
    expect(Template::count())->toBe(0)
        ->and(Project::count())->toBe(0)
        ->and(RawMaterialQuote::count())->toBe(0);
});

it('tells a description that is not a material apart from one the catalogue has never heard of', function () {
    /**
     * The two failures look identical to a customer - the row is simply missing - and they are
     * fixed in completely different places. "Not recognised" means the description column is not
     * the column recorded, or the row is not steel at all; "not in master materials" means the
     * template is right and the catalogue is short.
     */
    seedMasterMaterials();

    $result = runTrial(trialSheetForm(), trialSheet());

    expect(collect($result['rows'])->pluck('status')->all())->toBe(['imports', 'unrecognised'])
        ->and($result['rows'][0]['product_category'])->toBe('PFC')
        ->and($result['rows'][1]['note'])->toContain('reads as no product the classifier knows')
        ->and($result['headline'])->toBe('1 of 2 extracted rows would import.');
});

it('would be a disaster if a test passed against an empty catalogue', function () {
    /**
     * An empty products table makes a perfectly good template import nothing, and it is not even
     * honest about why: the nesting algorithm a row is judged by is read off the catalogue as well,
     * so on a meterage-only plan a real 250PFC is turned away as the wrong kind of product rather
     * than as something with no match. Read as a verdict on the template, any of that is a lie -
     * hence one error about the catalogue, above every row.
     */
    $result = runTrial(trialSheetForm(), trialSheet());

    expect($result['headline'])->toBe('0 of 2 extracted rows would import.')
        ->and(collect($result['findings'])->where('level', 'error')->pluck('message')->implode("\n"))
        ->toContain('no master materials for this business to match against')
        ->toContain('not about the template');
});

it('says so when the heading labels are not in the file at all', function () {
    //Nothing else on the screen can tell an admin this: the labels are perfectly valid, and they
    //describe a table that is not in the spreadsheet in front of them
    $result = runTrial(
        [...trialSheetForm(), 'expected_heading_labels' => ['Section', 'Count', 'Cut Length']],
        trialSheet(),
    );

    expect($result['rows'])->toBe([])
        ->and($result['tables'])->toBe([])
        ->and($result['headline'])->toBe('Nothing was extracted from this spreadsheet.')
        ->and(collect($result['findings'])->pluck('message')->implode("\n"))
        ->toContain('No row of this spreadsheet carries these labels in this order');
});

it('would be a disaster if a template with no length column looked like it worked', function () {
    /**
     * length_required is NOT NULL, so a row with no length is dropped by the importer and reported
     * to the customer as unreadable. With no length column at all that is every row, and the record
     * is otherwise perfectly valid - it saves without a murmur.
     */
    seedMasterMaterials();

    $result = runTrial(
        collect(trialSheetForm())->except('first_length_required_cell')->all(),
        trialSheet(),
    );

    expect($result['rows'][0]['status'])->toBe('no_length')
        ->and(collect($result['findings'])->where('level', 'error')->pluck('message')->implode("\n"))
        ->toContain('No length column is recorded')
        ->and($result['headline'])->toBe('0 of 2 extracted rows would import.');
});

it('warns that every row imports as one piece when there is no sub qty column', function () {
    seedMasterMaterials();

    $result = runTrial(
        collect(trialSheetForm())->except('first_sub_qty_cell')->all(),
        trialSheet(),
    );

    expect(collect($result['findings'])->where('level', 'warning')->pluck('message')->implode("\n"))
        ->toContain('every row imports as a single piece')
        //And the row that imports says the same thing about itself
        ->and($result['rows'][0]['note'])->toContain('No quantity, so one piece is assumed');
});

it('reads all four tables of a file that carries four', function () {
    /**
     * A Tekla hot rolled report holds four bands, each ended by its own Total row. An admin who
     * tests one and sees three rows has tested a quarter of the spreadsheet, so the count and where
     * each band starts are both part of the answer.
     */
    seedMasterMaterials();

    $result = runTrial(trialForm('Hot Rolled, Angles, and more.'), trialUpload('tekla_hot_rolled.xlsx'));

    expect($result['tables'])->toHaveCount(4)
        ->and(array_column($result['tables'], 'extracted'))->toBe([3, 2, 2, 3])
        ->and($result['summary']['extracted'])->toBe(10)
        //Which band each row came out of, so ten rows from four tables are not read as one table
        ->and(collect($result['rows'])->pluck('table')->unique()->values()->all())->toBe([1, 2, 3, 4])
        ->and(collect($result['findings'])->pluck('message')->implode("\n"))
        ->toContain('The heading row was found 4 times')
        //The running totals are the rows that would double the steel on the order
        ->and(collect($result['rows'])->pluck('description')->all())
        ->not->toContain('Subtotal')
        ->not->toContain('Total');
});

it('builds the description out of several cells for a table that has no description column', function () {
    /**
     * The bolt summaries have no description column at all - "M" + diameter + grade + length +
     * "mm" is the only thing that says what the row is - and that string is all the classifier has
     * to go on. A template whose compound cells are one out extracts nonsense that still looks
     * like a description, so this is the case worth showing an admin.
     */
    seedMasterMaterials();

    $result = runTrial(trialForm('Bolt Summary - top'), trialUpload('tekla_bolt_summary_top.xlsx'));

    expect(collect($result['rows'])->pluck('description')->all())->toBe([
        'M16 8.8 100mm',
        'M16 8.8 65mm',
        'M20 8.8 75mm',
        'M12 4.6 40mm',
        'M24 10.9 90mm',
        'M20 8.8 55mm',
    ]);
});

it('shows the length the importer would store, not just the one on the sheet', function () {
    /**
     * A quote written in meters is stored in millimeters, by the "below 20 must be meters" rule in
     * normalisedLength() - so a 9 becomes 9000. Reading 9 back off the sheet proves the column is
     * the right one; showing the 9000 proves the units are.
     */
    seedMasterMaterials();

    $result = runTrial(trialForm('Project Quote'), trialUpload('material_list.xlsx'));

    $pfc = collect($result['rows'])->firstWhere('description', '250 PFC 9m');

    expect($pfc['length_required'])->toBe(9.0)
        ->and($pfc['length_mm'])->toBe(9000.0);
});

it('refuses a record that could import nothing, with the reason under the field', function () {
    /**
     * The same blocking checks the save runs. A record with no heading labels cannot be tested
     * either - there is nothing to find a table by - and the reason belongs under the field it is
     * about rather than in the result panel.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.test', $business->id),
        [...collect(trialSheetForm())->except('expected_heading_labels')->all(), 'sample' => trialSheet()],
    )->assertSessionHasErrors('expected_heading_labels');

    expect(session('templateTest'))->toBeNull();
});

it('asks for a sample to test against', function () {
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.test', $business->id),
        trialSheetForm(),
    )->assertSessionHasErrors('sample');
});

it('says so when the sample is not a spreadsheet at all', function () {
    $result = runTrial(
        trialSheetForm(),
        UploadedFile::fake()->createWithContent('broken.xlsx', 'this is not a spreadsheet'),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('could not be read as a spreadsheet');
});

it('would be a disaster if anyone but an admin could run this', function () {
    $business = createBusiness('Business A');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)->post(
        route('admin.businesses.templates.test', $business->id),
        [...trialSheetForm(), 'sample' => trialSheet()],
        //AdminMiddleware sends a non-admin home rather than to the route
    )->assertRedirect('/');

    expect(session('templateTest'))->toBeNull();
});
