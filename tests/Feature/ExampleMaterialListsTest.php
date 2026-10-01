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
 */
function exampleMaterialLists(): array
{
    return [
        'material_list.xlsx' => 'Project Quote',
        'tekla_assembly_list.xlsx' => 'Assembly List',
        'tekla_hot_rolled.xlsx' => 'Hot Rolled, Angles, and more.',
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

    expect($project->unimportedItems()['notRecognised'])->toBe([]);

    //Every row of both files, the repeated 250PFC included
    expect(RawMaterialQuote::count())->toBe(16);

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
