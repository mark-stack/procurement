<?php

use App\Imports\ExcelImport;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use App\Models\User;
use App\Services\CsvService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

/**
 * public/examples holds one workbook per template in Tests\Support\ExampleTemplates, keyed
 * here by the template it demonstrates.
 *
 * The templates are calibrated in column offsets measured from wherever the heading run
 * starts, so a template is only as trustworthy as the file that proves it: move the sub
 * qty column by one and the import quietly starts multiplying by the Rate column
 * instead. These examples are that proof, and they are the files a user downloads to see
 * what we can read, so they are also the reason to keep the two in step.
 *
 * They earned a second job when detection moved out of config/TableTemplates.php and into
 * this table. These assertions did not change across that move, and their passing either
 * side of it is what says the two notations extract identical rows.
 *
 * The folder also holds the prints two of these were rebuilt from - a PDF and a PNG, neither of
 * which anybody can import. They are there to be read next to the file, so a question about what a
 * reconstruction assumed can be settled by looking rather than by guessing.
 */
function exampleMaterialLists(): array
{
    return [
        'material_list.xlsx' => 'Project Quote',
        'tekla_assembly_list.xlsx' => 'Assembly List',
        'tekla_assembly_list_totals.xlsx' => 'Assembly List - totals',
        'tekla_hot_rolled.xlsx' => 'Hot Rolled, Angles, and more.',
        'tekla_material_list.xlsx' => 'Material List',
        'tekla_bolt_summary_top.xlsx' => 'Bolt Summary - top',
        'tekla_bolt_summary_bottom.xlsx' => 'Bolt Summary - bottom',
    ];
}

function examplePath(string $file): string
{
    return base_path('public/examples/'.$file);
}

/**
 * Staff of a business that has recorded all five example templates.
 *
 * A business is created with none - an admin records one per report format a customer sends in -
 * so the fixture that makes these files importable is part of the test rather than something
 * registration hands out. See Tests\Support\ExampleTemplates.
 */
function exampleUploader(): User
{
    $business = createBusiness('gmail');
    recordExampleTemplates($business);

    $user = createUser(2, $business, false, true);
    Auth::login($user);

    return $user;
}

/**
 * Detect and extract, the way TemplateService does on an upload.
 *
 * @return array<int, array{type: string, data: array}>
 */
function exampleTables(string $file): array
{
    $csvService = new CsvService;
    $csvArray = Excel::toArray(new ExcelImport, examplePath($file))[0];

    return $csvService->detectedTables($csvArray, $csvService->eligibleTables());
}

/**
 * Every material row the file yields, in sheet order, with the tables run together -
 * which is what processTemplate() goes on to save.
 */
function exampleRows(string $file): array
{
    return collect(exampleTables($file))
        ->flatMap(fn (array $table) => $table['data'])
        ->all();
}

/**
 * @param  array<int, string>  $fields
 */
function exampleRowFields(string $file, array $fields): array
{
    return array_map(
        fn (array $row) => array_map(fn (string $field) => $row[$field], $fields),
        exampleRows($file),
    );
}

function uploadExamples(User $user, array $files): void
{
    test()->actingAs($user)
        ->from('/dashboard')
        ->post(route('projects.store'), [
            'name' => 'Examples',
            'reference' => null,
            'date_materials_required' => null,
            'date_fabrication_begins' => now()->addMonth()->toDateString(),
            'tentative' => false,
            'excel' => array_map(fn (string $file) => new UploadedFile(
                examplePath($file),
                $file,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            ), $files),
        ]);
}

it('would be a disaster if an example shipped without a test', function () {
    /**
     * These tests are only worth anything if they cover the whole folder. A new example
     * dropped in beside the others, with nothing asserting what it parses to, is exactly
     * the file that goes stale.
     */
    $shipped = collect(glob(base_path('public/examples/*.xlsx')))
        ->map(fn (string $path) => basename($path))
        ->sort()
        ->values()
        ->all();

    expect($shipped)->toBe(
        collect(exampleMaterialLists())->keys()->sort()->values()->all()
    );
});

it('would be a disaster if an example matched a template it is not an example of', function () {
    /**
     * detectedTables() runs every eligible template over every row and keeps all of
     * them, so a file whose heading row satisfies two templates is imported twice -
     * once per template, at whatever offsets each one carries. The two bolt summaries
     * share their first heading label and are one label apart from each other, so
     * this is a real risk rather than a theoretical one.
     */
    $csvService = new CsvService;
    exampleUploader();

    foreach (exampleMaterialLists() as $file => $label) {
        $csvArray = Excel::toArray(new ExcelImport, examplePath($file))[0];

        $matched = [];
        foreach ($csvService->eligibleTables() as $template) {
            foreach ($csvArray as $csvRow) {
                if ($csvService->isTableHeader($csvRow, $template)) {
                    $matched[$template['label']] = true;
                }
            }
        }

        expect(array_keys($matched))->toBe([$label], "{$file} should match only '{$label}'");
    }
});

it('would be a disaster if the Project Quote example stopped parsing', function () {
    /**
     * Lengths and widths are in meters on this template and are left in meters here:
     * normaliseLengthWidthRequired() strips the units off the cell and stops, and the
     * meters-to-millimeters decision belongs to normalisedLength() further down.
     *
     * The assembly mark is the same on every row because the template reads it from one
     * FIXED cell above the table rather than from a column.
     */
    exampleUploader();

    expect(exampleTables('material_list.xlsx'))->toHaveCount(1);

    expect(exampleRowFields('material_list.xlsx', ['description', 'length_required', 'width_required', 'sub_qty', 'assembly_mark']))
        ->toBe([
            ['20PL 1220mm', 5.5, 1.22, 6.0, 'Expenses'],
            ['20mm plate GR350', 6.0, 1.0, 6.0, 'Expenses'],
            ['250 PFC 9m', 9.0, 1.0, 10.0, 'Expenses'],
            ['75x50x2.5 RHS', 1.0, 1.0, 13.0, 'Expenses'],
            ['LVL 90X63', 4.0, 1.0, 5.0, 'Expenses'],
            ['90X63 LVL 7 meters', 8.0, 1.0, 5.0, 'Expenses'],
            ['M16x100', 1.0, 1.0, 13.0, 'Expenses'],
            ['SS316 M16  x 150', 1.0, 1.0, 100.0, 'Expenses'],
        ]);
});

it('would be a disaster if the Assembly List example stopped parsing', function () {
    /**
     * The widest template we read: the description is three columns from the Mark
     * column, the finish fourteen, the length eighteen. Asserting the lengths proves
     * the parser is reading column S and not one of the area or weight columns beside
     * it, and the quantities prove it reads Qty rather than Length.
     *
     * A5 carries no finish, which must cost the surface and not the row.
     */
    exampleUploader();

    expect(exampleTables('tekla_assembly_list.xlsx'))->toHaveCount(1);

    expect(exampleRowFields('tekla_assembly_list.xlsx', ['description', 'surface', 'length_required', 'sub_qty', 'assembly_mark']))
        ->toBe([
            ['250PFC', 'GALVANISED', 9000.0, 4.0, 'A1'],
            ['310UB40', 'PAINTED', 12000.0, 6.0, 'A2'],
            ['150UC30', 'GALVANISED', 3600.0, 2.0, 'A3'],
            ['100x100x10EA', null, 2400.0, 12.0, 'A4'],
            ['100x75x6UA', 'PAINTED', 1800.0, 8.0, 'A5'],
            ['CHS88.9x3.2', 'GALVANISED', 2700.0, 5.0, 'A6'],
        ]);

    //Two blank Mark cells end the table, so the footer below them is not a material
    expect(collect(exampleRows('tekla_assembly_list.xlsx'))->pluck('description'))
        ->not->toContain('End of report');
});

it('would be a disaster if the Assembly List - totals example stopped parsing', function () {
    /**
     * The same report as above printed the other way up, and RECONSTRUCTED from
     * public/examples/tekla_assembly_list_totals.png - so its columns are adjacent where the
     * other one spreads the same seven across A to W. Tests\Support\ExampleTemplates says which
     * parts of its shape are read off that page and which are a reading of it.
     *
     * Two things it holds that the wide export does not. The first is the footer: "Total for 18
     * assemblies:" sits in the PROFILE column - the description column - directly under the last
     * assembly, with no blank row in front of it and no word naming it the way "Hot Rolled" names
     * "Total". The only thing between it and the material list is its empty Ass Mk cell, so the
     * table ends because the check column ran out and nothing below reads as a material. Read as a
     * row it would order a section called "Total for 18 assemblies:".
     *
     * The second is the quantity. Eighteen assemblies are printed as seventeen lines, because B/3
     * is a Qty of 2 - which is what says the Qty column multiplies rather than counting lines
     * already totalled.
     */
    exampleUploader();

    expect(exampleTables('tekla_assembly_list_totals.xlsx'))->toHaveCount(1);

    expect(exampleRowFields('tekla_assembly_list_totals.xlsx', ['description', 'surface', 'length_required', 'sub_qty', 'assembly_mark']))
        ->toBe([
            ['UB360*51', 'ZINC PRIMED', 5000.0, 1.0, 'B/1'],
            ['UB360*51', 'ZINC PRIMED', 7721.0, 1.0, 'B/2'],
            //Two of them, and the only line of the report that is not one assembly
            ['UB360*51', 'ZINC PRIMED', 2950.0, 2.0, 'B/3'],
            ['UB250*31', 'ZINC PRIMED', 2599.0, 1.0, 'B/4'],
            ['UB310*40', 'ZINC PRIMED', 7652.0, 1.0, 'B/5'],
            ['UB200*18', 'ZINC PRIMED', 2950.0, 1.0, 'B/6'],
            ['UB360*51', 'ZINC PRIMED', 7652.0, 1.0, 'B/7'],
            ['UB530*92', 'ZINC PRIMED', 2950.0, 1.0, 'B/8'],
            ['UB180*18', 'ZINC PRIMED', 2950.0, 1.0, 'B/9'],
            ['UB180*22', 'ZINC PRIMED', 2950.0, 1.0, 'B/10'],
            ['UC310*97', 'GALV', 5000.0, 1.0, 'C/1'],
            ['UC310*97', 'GALV', 5000.0, 1.0, 'C/2'],
            ['UC310*97', 'GALV', 5000.0, 1.0, 'C/3'],
            ['UC310*97', 'GALV', 5000.0, 1.0, 'C/4'],
            ['UC310*97', 'GALV', 5000.0, 1.0, 'C/5'],
            ['UC310*97', 'GALV', 5000.0, 1.0, 'C/6'],
            ['UC310*97', 'GALV', 5000.0, 1.0, 'C/7'],
        ]);

    expect(collect(exampleRows('tekla_assembly_list_totals.xlsx'))->pluck('description'))
        ->not->toContain('Total for 18 assemblies:');
});

it('would be a disaster if a rounded mass stopped reaching the section it names', function () {
    /**
     * "UB360*51" is a 360UB50.7, and the sheet is the only place the 50.7 can come from - the
     * detailer rounds it and the catalogue does not. Depth alone cannot finish the job: 180UB is
     * stocked at 16.1, 18.1 and 22.2 kg/m, so B/9 and B/10 are the same depth and different steel,
     * and matching on "180UB" would put them on the same bar.
     *
     * The report also names no grade anywhere - there is no Grade column to read - so every row
     * here is matched on description alone. That it still lands on exactly one section is what
     * says the rounded mass is being read; drop it and these rows go ambiguous rather than wrong,
     * which is the failure that reaches the user as a confirmation modal full of choices.
     *
     * Finish is read and deliberately not matched on. "ZINC PRIMED" and "GALV" are both surfaces
     * the catalogue has no plain-carbon UB for, and a row must not go unmatched over one.
     */
    $user = exampleUploader();
    seedMasterMaterials();

    $project = createProject($user);
    (new CsvService)->processTemplate(exampleTables('tekla_assembly_list_totals.xlsx'), $project);

    expect($project->unimportedItems()['notRecognised'])->toBe([])
        ->and(RawMaterialQuote::count())->toBe(17);

    $matched = RawMaterialQuote::all()
        ->unique('description')
        ->mapWithKeys(function (RawMaterialQuote $quote) {
            $results = unserialize($quote->general_product_matches)['results'];

            expect($results)->toHaveCount(1, "{$quote->description} should name one section");

            return [$quote->description => [$results[0]['nominal_height'], $results[0]['kg_per_m']]];
        })
        ->all();

    expect($matched)->toBe([
        'UB360*51' => ['360', 50.7],
        'UB250*31' => ['250', 31.4],
        'UB310*40' => ['310', 40.4],
        'UB200*18' => ['200', 18.2],
        'UB530*92' => ['530', 92.4],
        //The two that share a depth
        'UB180*18' => ['180', 18.1],
        'UB180*22' => ['180', 22.2],
        'UC310*97' => ['310', 96.8],
    ]);
});

it('would be a disaster if the Hot Rolled example stopped parsing', function () {
    /**
     * Four tables in one document, each ended by its own "Total" row, and the heading
     * run starts at column B rather than column A - so every offset here has to be
     * measured from the heading the template matched. Read from the left edge of the
     * sheet instead, every column would be one out: the grade would come off the
     * description column and the quantity off the part mark.
     */
    exampleUploader();

    $tables = exampleTables('tekla_hot_rolled.xlsx');

    expect($tables)->toHaveCount(4)
        ->and(array_map(fn (array $table) => count($table['data']), $tables))->toBe([3, 2, 2, 3]);

    expect(exampleRowFields('tekla_hot_rolled.xlsx', ['description', 'grade', 'length_required', 'sub_qty', 'assembly_mark']))
        ->toBe([
            ['250PFC', 'GR300', 9000.0, 4.0, 'A1'],
            ['200PFC', 'GR300', 6000.0, 6.0, 'A2'],
            ['250PFC', 'GR300', 12000.0, 2.0, 'A3'],
            ['310UB40', 'GR300', 12000.0, 3.0, 'B1'],
            ['200UB25', 'GR300', 9000.0, 5.0, 'B2'],
            ['100x100x10EA', 'GR300', 2400.0, 12.0, 'C1'],
            ['90x90x6EA', 'GR300', 1800.0, 8.0, 'C2'],
            ['75x50x2.5 RHS', 'GR350', 6000.0, 14.0, 'D1'],
            ['150x50x4 RHS', 'GR350', 8000.0, 6.0, 'D2'],
            ['CHS88.9x3.2', 'GR350', 6000.0, 4.0, 'D3'],
        ]);

    /*
     * Both running-total rows are dropped: "Subtotal" by the skip rule, and "Total" by
     * ending the table. Counting either one would double the steel on the order.
     */
    expect(collect(exampleRows('tekla_hot_rolled.xlsx'))->pluck('description'))
        ->not->toContain('Subtotal')
        ->not->toContain('Total');
});

it('would be a disaster if the Material List example stopped parsing', function () {
    /**
     * The one reconstructed file in this folder - rebuilt from a print of the report, because the
     * thing the customer sent was a PDF. Tests\Support\ExampleTemplates says which parts of its
     * shape are read off that page and which are a reading of it.
     *
     * What it is here to hold is the end of the table. The subtotal line is blank in the Profile
     * column, so it reads as a gap, and each band between two gaps is a band of one, two, one and
     * three rows. The band of ONE is what used to end the table: four rows below it were never
     * read, and never read is the failure with no symptom - the rows reach none of the three lists
     * an import reports back, so it announced success two eleven-metre RHS short.
     *
     * The quantities matter as much. The subtotals on the printed page are quantity-weighted
     * (3495x2 + 3679x4 = 21705), which is what says the Qty column is a multiplier and not a
     * count of something already totalled.
     */
    exampleUploader();

    expect(exampleTables('tekla_material_list.xlsx'))->toHaveCount(1);

    expect(exampleRowFields('tekla_material_list.xlsx', ['description', 'grade', 'length_required', 'sub_qty']))
        ->toBe([
            ['CHS114.3*5.4', '300PLUS', 3865.0, 1.0],
            ['PFC125*65', '300PLUS', 3495.0, 2.0],
            ['PFC125*65', '300PLUS', 3679.0, 4.0],
            //Its own band, between two subtotals, and every row below it
            ['PFC300*90', '300PLUS', 3679.0, 2.0],
            ['RHS150*100*6.0', '300PLUS', 11310.0, 1.0],
            ['RHS150*100*6.0', '300PLUS', 11734.0, 1.0],
            ['RHS150*100*6.0', '300PLUS', 12103.0, 1.0],
        ]);

    /*
     * The subtotals are blank in the description column, so they never become rows - and the page
     * footer sits past the last band with nothing material below it, so the table ends above it.
     */
    expect(collect(exampleRows('tekla_material_list.xlsx'))->pluck('description'))
        ->not->toContain('Subtotal')
        ->not->toContain('Page 1');
});

it('would be a disaster if the Bolt Summary top example stopped parsing', function () {
    /**
     * This template has no description column at all. The description is assembled from
     * three cells - "M" + Bolt Dia + Bolt Grade + Length + "mm" - and that string is
     * the only thing product classification has to go on, so its spacing matters as much
     * as any column offset.
     *
     * The bolt diameter doubles as the width, and the length is a bolt length rather than
     * a bar length; it is the sub quantity that carries the count.
     */
    exampleUploader();

    $tables = exampleTables('tekla_bolt_summary_top.xlsx');

    //The first band ends where the second band's heading row starts
    expect($tables)->toHaveCount(2)
        ->and(array_map(fn (array $table) => count($table['data']), $tables))->toBe([4, 2]);

    expect(exampleRowFields('tekla_bolt_summary_top.xlsx', ['description', 'length_required', 'width_required', 'sub_qty']))
        ->toBe([
            ['M16 8.8 100mm', 100.0, 16.0, 120.0],
            ['M16 8.8 65mm', 65.0, 16.0, 80.0],
            ['M20 8.8 75mm', 75.0, 20.0, 40.0],
            ['M12 4.6 40mm', 40.0, 12.0, 200.0],
            ['M24 10.9 90mm', 90.0, 24.0, 16.0],
            ['M20 8.8 55mm', 55.0, 20.0, 48.0],
        ]);

    /*
     * The assembly mark is a FIXED cell three rows above the heading, which only exists
     * above the first band - the second band's coordinates land in the blank rows
     * between the two tables. A mark is not worth losing a row over, so those rows come
     * back with none, and that is a limitation of the FIXED rule rather than of the file.
     */
    expect(collect(exampleRows('tekla_bolt_summary_top.xlsx'))->pluck('assembly_mark')->all())
        ->toBe([
            'WAREHOUSE 4 / A1',
            'WAREHOUSE 4 / A1',
            'WAREHOUSE 4 / A1',
            'WAREHOUSE 4 / A1',
            null,
            null,
        ]);
});

it('would be a disaster if the Bolt Summary bottom example stopped parsing', function () {
    /**
     * The same compound description as the top summary, built from different columns:
     * the grade this one reads at offset 12 is not one of the template's heading labels,
     * because the labels only have to be enough to recognise the table. Profile and Name
     * sit between them and are deliberately not part of the description - a nut and a
     * bolt of the same diameter would otherwise be told apart by a column nothing reads.
     *
     * This template carries no assembly mark rule, so every row gets an empty one.
     */
    exampleUploader();

    expect(exampleTables('tekla_bolt_summary_bottom.xlsx'))->toHaveCount(1);

    expect(exampleRowFields('tekla_bolt_summary_bottom.xlsx', ['description', 'length_required', 'width_required', 'sub_qty', 'assembly_mark']))
        ->toBe([
            ['M16 8.8 100mm', 100.0, 16.0, 64.0, ''],
            ['M16 8.8 45mm', 45.0, 16.0, 32.0, ''],
            ['M20 8.8 75mm', 75.0, 20.0, 24.0, ''],
            ['M24 10.9 90mm', 90.0, 24.0, 8.0, ''],
            ['M12 4.6 40mm', 40.0, 12.0, 120.0, ''],
        ]);

    expect(collect(exampleRows('tekla_bolt_summary_bottom.xlsx'))->pluck('description'))
        ->not->toContain('End of report');
});

it('would be a disaster if an example parsed but imported nothing', function () {
    /**
     * Parsing is only half of it: a description that reads perfectly off the sheet and
     * then matches nothing in the products table still leaves the user with an empty
     * BOM. Every section in the two Tekla section examples has to reach a material row.
     */
    $user = exampleUploader();
    seedMasterMaterials();

    uploadExamples($user, ['tekla_assembly_list.xlsx', 'tekla_hot_rolled.xlsx']);

    $project = Project::firstOrFail();

    /*
     * One row of the hot rolled example does not reach a material, and it is the catalogue's gap
     * rather than the parser's: that sheet asks for CHS 88.9x3.2 at GR350, and the platform
     * catalogue carries 88.9x3.2 only as GR250 galvanised pipe - its GR350 CHS of that diameter is
     * 5.5mm wall. AS/NZS 1163 C350 is the ordinary structural grade for CHS, so this is a product
     * we should stock and do not.
     *
     * It imported until the grade COLUMN started being matched on. Before that the grade was
     * derived from the description alone, "CHS88.9x3.2" names no grade, and the row was quietly
     * filled with galvanised plumbing pipe - which is the whole reason the column is now read. The
     * assertion is here so that adding the missing product shows up as this test going green on
     * 16, rather than as a number nobody can account for.
     */
    expect($project->unimportedItems()['notRecognised'])->toBe(['CHS88.9x3.2']);

    //Every row of both files bar that one, the repeated 250PFC included
    expect(RawMaterialQuote::count())->toBe(15);

    expect(RawMaterialQuote::pluck('description')->unique()->sort()->values()->all())
        ->toBe([
            '100x100x10EA',
            '100x75x6UA',
            '150UC30',
            '150x50x4 RHS',
            '200PFC',
            '200UB25',
            '250PFC',
            '310UB40',
            '75x50x2.5 RHS',
            '90x90x6EA',
            'CHS88.9x3.2',
        ]);
});

it('would be a disaster if a plan dropped an example without saying why', function () {
    /**
     * Fasteners nest as a bundle, and a meterage-only business - which is the default -
     * cannot order them. Both bolt summaries therefore parse in full and import nothing,
     * which is correct. What would not be correct is those lines being recorded as
     * unrecognised: the descriptions are read and classified perfectly, and telling the
     * user we could not match their bolts would send them off editing a file that is fine.
     */
    $user = exampleUploader();
    seedMasterMaterials();

    expect($user->business->meterage_only)->toBeTrue();

    $project = createProject($user);
    $csvService = new CsvService;

    foreach (['tekla_bolt_summary_top.xlsx', 'tekla_bolt_summary_bottom.xlsx'] as $file) {
        $csvService->processTemplate(exampleTables($file), $project);
    }

    expect(RawMaterialQuote::count())->toBe(0)
        ->and($project->unimportedItems()['otherPlan'])->toBe([
            'M16 8.8 100mm',
            'M16 8.8 65mm',
            'M20 8.8 75mm',
            'M12 4.6 40mm',
            'M24 10.9 90mm',
            'M20 8.8 55mm',
            'M16 8.8 45mm',
        ])
        ->and($project->unimportedItems()['notRecognised'])->toBe([]);
});

it('would be a disaster if an example that imported nothing looked like a success', function () {
    /**
     * The two bolt examples between them are a whole upload that a meterage-only plan
     * can do nothing with. The modal must not close on a project holding an empty BOM,
     * and the empty shell must not be left behind blocking a retry under the same name.
     */
    $user = exampleUploader();
    seedMasterMaterials();

    uploadExamples($user, ['tekla_bolt_summary_top.xlsx', 'tekla_bolt_summary_bottom.xlsx']);

    expect(session('warning'))->not->toBeNull()
        ->and(session('project'))->toBeNull()
        ->and(Project::count())->toBe(0)
        ->and(RawMaterialQuote::count())->toBe(0);
});
