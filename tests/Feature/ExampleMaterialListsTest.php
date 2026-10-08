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
        'pages_1_2_material_list.xlsx' => 'Material List - pages 1-2',
        'tekla_bolt_summary_top.xlsx' => 'Bolt Summary - top',
        'tekla_bolt_summary_bottom.xlsx' => 'Bolt Summary - bottom',
    ];
}

function examplePath(string $file): string
{
    return base_path('public/examples/'.$file);
}

/**
 * Staff of a business that has recorded every example template.
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

/**
 * The band footers of pages_1_2_material_list.xlsx: each band's printed "Total" row, keyed by the
 * section above it, as [Qty, Length (mm)].
 *
 * These are the report's own arithmetic, not ours, which is the whole reason the file is worth
 * having. Two pages of a customer's export carry forty-nine rows across ten closed bands and an
 * eleventh the page break cuts in half, and every column offset in the template can be checked
 * against a figure Tekla printed rather than against a figure we decided.
 *
 * EA65*65*6 is deliberately absent: it is the band with no footer.
 *
 * @return array<string, array{float, float}>
 */
function pagesOneTwoBandTotals(): array
{
    return [
        'CHS26.9*3.2' => [3.0, 449.0],
        'CHS33.7*3.2' => [18.0, 24190.0],
        'CHS42.4*4.0' => [15.0, 24163.0],
        'CHS76.1*4.5' => [12.0, 10659.0],
        'CHS114.3*4.5' => [2.0, 6736.0],
        'CHS139.7*5.0' => [4.0, 9561.0],
        'D16' => [30.0, 8159.0],
        'D20' => [30.0, 3049.0],
        'EA50*50*5' => [2.0, 283.0],
        'EA50*50*6' => [92.0, 9231.0],
    ];
}

it('would be a disaster if the Material List - pages 1-2 example stopped parsing', function () {
    /**
     * The report "Material List" above is a reconstruction OF: pages one and two of a customer's
     * own Tekla Material_List export, which is where the file name comes from and what its Source
     * Page column counts. Where the two disagree this is the one to believe.
     *
     * They disagree about the band boundary, which is the one thing this report is shaped by.
     * Tekla bands its rows by section and closes each band with a line reading "Total" - and here
     * that word sits in the PROFILE column, on a row with no blank row either side of it, so a
     * band boundary is a WORD. The print the other file was rebuilt from has the same line
     * indented under Grade with white space around it, where it is a GAP and the Profile cell is
     * empty on it. So this template names "Total" and that one names nothing, and the band rules
     * GroupedTableEndTest pins down are what carry that one over its own footers.
     *
     * Naming it has to mean SKIP and not END. Ten bands close in these two pages: ending the
     * table on the first "Total" would read one row of forty-nine - the single 150mm CHS26.9
     * above it - and leave the other forty-eight out of every list an import reports back. Never
     * read is the failure with no symptom, which is why the row count is asserted before the rows.
     *
     * The lengths are a second thing this file holds that none of the others do. They run from a
     * 39mm cleat to a 6.5m column, and nothing on the sheet names a unit, so normalisedLength()
     * reads every one of them by magnitude - "below 20 must be meters". The smallest figure here
     * is 39.05, so no row trips that rule; a report with a 15mm washer plate in this column would,
     * and would order fifteen metres of it.
     */
    exampleUploader();

    expect(exampleTables('pages_1_2_material_list.xlsx'))->toHaveCount(1);

    $rows = exampleRowFields('pages_1_2_material_list.xlsx', ['description', 'grade', 'length_required', 'sub_qty']);

    //Forty-nine materials and ten footers, which is every row of the sheet below the heading
    expect($rows)->toHaveCount(49);

    expect($rows)->toBe([
        //One row, between the heading and the first "Total" - a band the end-of-table rule used to be
        ['CHS26.9*3.2', 'C350', 150.0, 3.0],
        ['CHS33.7*3.2', 'C350', 39.05, 1.0],
        ['CHS33.7*3.2', 'C350', 40.08, 2.0],
        ['CHS33.7*3.2', 'C350', 88.66, 1.0],
        ['CHS33.7*3.2', 'C350', 150.0, 3.0],
        ['CHS33.7*3.2', 'C350', 168.85, 1.0],
        ['CHS33.7*3.2', 'C350', 204.34, 1.0],
        ['CHS33.7*3.2', 'C350', 287.35, 1.0],
        ['CHS33.7*3.2', 'C350', 768.3, 3.0],
        ['CHS33.7*3.2', 'C350', 2764.26, 1.0],
        ['CHS33.7*3.2', 'C350', 3055.61, 1.0],
        ['CHS33.7*3.2', 'C350', 4122.89, 1.0],
        ['CHS33.7*3.2', 'C350', 4145.85, 1.0],
        ['CHS33.7*3.2', 'C350', 6478.51, 1.0],
        ['CHS42.4*4.0', 'C350', 47.1, 1.0],
        ['CHS42.4*4.0', 'C350', 49.87, 2.0],
        ['CHS42.4*4.0', 'C350', 109.5, 1.0],
        ['CHS42.4*4.0', 'C350', 148.0, 1.0],
        ['CHS42.4*4.0', 'C350', 197.94, 1.0],
        ['CHS42.4*4.0', 'C350', 266.5, 1.0],
        ['CHS42.4*4.0', 'C350', 878.4, 3.0],
        ['CHS42.4*4.0', 'C350', 2764.26, 1.0],
        ['CHS42.4*4.0', 'C350', 3034.76, 1.0],
        ['CHS42.4*4.0', 'C350', 4125.0, 1.0],
        ['CHS42.4*4.0', 'C350', 4137.34, 1.0],
        ['CHS42.4*4.0', 'C350', 6598.0, 1.0],
        //Page two begins here, and the heading row is not repeated above it
        ['CHS76.1*4.5', 'C250', 860.67, 2.0],
        ['CHS76.1*4.5', 'C250', 867.36, 1.0],
        ['CHS76.1*4.5', 'C250', 894.39, 3.0],
        ['CHS76.1*4.5', 'C250', 895.56, 4.0],
        ['CHS76.1*4.5', 'C250', 898.43, 1.0],
        ['CHS76.1*4.5', 'C250', 907.18, 1.0],
        //Another band of one, this one between two footers
        ['CHS114.3*4.5', 'C250', 3368.1, 2.0],
        ['CHS139.7*5.0', 'C250', 1493.55, 1.0],
        ['CHS139.7*5.0', 'C250', 1885.31, 1.0],
        ['CHS139.7*5.0', 'C250', 1910.45, 1.0],
        ['CHS139.7*5.0', 'C250', 4272.05, 1.0],
        ['D16', '6060', 272.0, 30.0],
        ['D20', '6060', 90.0, 16.0],
        ['D20', '6060', 115.0, 14.0],
        ['EA50*50*5', '300PLUS', 142.0, 2.0],
        //The one band whose rows are not all one grade
        ['EA50*50*6', '6060', 105.0, 55.0],
        ['EA50*50*6', '300PLUS', 75.0, 7.0],
        ['EA50*50*6', '300PLUS', 80.0, 7.0],
        ['EA50*50*6', '300PLUS', 100.0, 22.0],
        ['EA50*50*6', '300PLUS', 171.24, 1.0],
        //The band the page break cuts in half: no "Total" closes it, and the sheet ends under it
        ['EA65*65*6', '300PLUS', 180.0, 4.0],
        ['EA65*65*6', '300PLUS', 258.0, 4.0],
        ['EA65*65*6', '300PLUS', 507.63, 2.0],
    ]);

    /*
     * Read as a row, a footer orders a section called "Total" - three of them on page one alone,
     * at the band's whole quantity and its whole length.
     */
    expect(collect(exampleRows('pages_1_2_material_list.xlsx'))->pluck('description'))
        ->not->toContain('Total');
});

it('would be a disaster if a band stopped reconciling with the total the report printed under it', function () {
    /**
     * The assertion the file was collected for. Every figure above is a cell somebody typed into
     * this test, so on its own it only says the import has not changed; the footers say whether it
     * is RIGHT, because Tekla printed its own arithmetic under each band and we did not.
     *
     * The quantities reconcile exactly, band for band, and that is the strongest thing here. It
     * says the Qty column is the one being read - not Length, not Source Page, both of which hold
     * small whole numbers in this report and either of which would multiply the order by the wrong
     * thing - and it says the Qty column MULTIPLIES. Four of these bands print more pieces than
     * they print lines.
     *
     * It also says no footer was imported. A "Total" row counted as a material would bring its
     * band's whole quantity in a second time, so the band it closes would reconcile at double.
     *
     * The lengths reconcile to within a millimetre rather than exactly, and the gap is the report's
     * own rounding: it prints each piece to two decimals and totals the figures it did not round.
     * Every band that is out by one is a band whose pieces are whole millimetres on the page -
     * 150, 272, 90, 142 - so the rounding is where the millimetre went. A band out by more than
     * that is a column offset, not a rounding.
     */
    exampleUploader();

    $rows = collect(exampleRows('pages_1_2_material_list.xlsx'));

    foreach (pagesOneTwoBandTotals() as $section => [$printedQty, $printedLength]) {
        $band = $rows->where('description', $section);

        expect($band->sum('sub_qty'))
            ->toBe($printedQty, "{$section} should order the {$printedQty} pieces its total prints");

        expect(abs($band->sum(fn (array $row) => $row['length_required'] * $row['sub_qty']) - $printedLength))
            ->toBeLessThanOrEqual(1.0, "{$section} should measure the {$printedLength}mm its total prints");
    }

    //The eleventh band has no footer to reconcile against, because the page break took it
    expect($rows->where('description', 'EA65*65*6')->sum('sub_qty'))->toBe(10.0)
        ->and(array_keys(pagesOneTwoBandTotals()))->not->toContain('EA65*65*6');
});

it('would be a disaster if a grade the sheet named went back to buying something else', function () {
    /**
     * This report names a grade on every row, and the spellings it uses are the ones the standards
     * use rather than the ones a price book uses: "C250" and "C350" from AS/NZS 1163 for the hollow
     * sections, "300PLUS" from AS/NZS 3679.1 for the angles, and "6060" - which is not a steel
     * grade at all, but an aluminium extrusion alloy.
     *
     * None of the four were read when this file arrived, and the thing to understand about that is
     * that a grade nothing reads is not a grade the row is matched WITHOUT. It is a grade the row
     * is matched with no filter on at all, so every row went to the catalogue on its description
     * alone and took whatever came back:
     *
     *  - the C350 CHS were bought as the GR250 GALVANISED plumbing pipe of the same diameter, which
     *    is the exact substitution reading the grade column was added to stop;
     *  - the four 6060 rows - a hundred and fifteen aluminium pieces - were bought in steel.
     *
     * Both now refuse instead, and refusing is the point of the test. Thirty of the forty-nine rows
     * reach no product, and every one of those is a gap in OUR catalogue rather than anything wrong
     * with the sheet: it carries no 33.7 or 42.4 CHS except galvanised at C250, and no aluminium at
     * any size. A row reported as not stocked is a row somebody can act on. A row quietly filled
     * with the wrong steel is not, and is the more expensive of the two by the time it is cut.
     *
     * What must NOT come back is the old answer. If matchGrades() or matchMaterial() stops reading
     * one of these spellings the count below climbs back towards forty-eight and every number in
     * this test still looks healthy, which is why the matched rows are asserted by grade and
     * surface and not just counted.
     */
    $user = exampleUploader();
    seedMasterMaterials();

    $project = createProject($user);
    (new CsvService)->processTemplate(exampleTables('pages_1_2_material_list.xlsx'), $project);

    expect(RawMaterialQuote::count())->toBe(19)
        ->and($project->unimportedItems()['couldNotBeRead'])->toBe([]);

    /*
     * Four of these are the grade being read and one is not. CHS26.9*3.2 would be refused whatever
     * grade it named - the catalogue's 26.9 is a 2.6 wall and the sheet asks for 3.2 - and the
     * other four are stocked at this size in a grade nobody asked for.
     *
     * EA50*50*6 is in both lists at once, and that is the whole case for reading the column. Its
     * band holds one 6060 row and four 300PLUS rows of the same section: the aluminium one is
     * refused and the four steel ones import, which only happens if the grade is being read per
     * ROW rather than per description.
     */
    expect($project->unimportedItems()['notRecognised'])->toBe([
        'CHS26.9*3.2',
        'CHS33.7*3.2',
        'CHS42.4*4.0',
        'D16',
        'D20',
        'EA50*50*6',
    ]);

    $matched = RawMaterialQuote::all()
        ->unique('description')
        ->mapWithKeys(function (RawMaterialQuote $quote) {
            $results = unserialize($quote->general_product_matches)['results'];

            expect($results)->toHaveCount(1, "{$quote->description} should name one product");

            return [$quote->description => [
                $results[0]['material'],
                $results[0]['grade'],
                $results[0]['surface'],
            ]];
        })
        ->all();

    expect($matched)->toBe([
        /*
         * Galvanised, and correctly so: C250 IS the galvanised pipe grade, so these three are the
         * sheet and the catalogue agreeing rather than the importer settling for something.
         */
        'CHS76.1*4.5' => ['PLAIN_CARBON_STEEL', 'GR250', 'GALVANISED'],
        'CHS114.3*4.5' => ['PLAIN_CARBON_STEEL', 'GR250', 'GALVANISED'],
        'CHS139.7*5.0' => ['PLAIN_CARBON_STEEL', 'GR250', 'GALVANISED'],
        //300PLUS reaching GR300 is what the trade name means
        'EA50*50*5' => ['PLAIN_CARBON_STEEL', 'GR300', 'NONE'],
        'EA50*50*6' => ['PLAIN_CARBON_STEEL', 'GR300', 'NONE'],
        'EA65*65*6' => ['PLAIN_CARBON_STEEL', 'GR300', 'NONE'],
    ]);

    //Nothing on this sheet is bought in a material it did not ask for
    expect(collect($matched)->pluck(0)->unique()->all())->toBe(['PLAIN_CARBON_STEEL']);
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
     * Every row of both files reaches a material, and the last one to do so was CHS88.9x3.2.
     *
     * That row is worth remembering, because it was wrong in both directions before it was right.
     * The sheet asks for it at GR350; the catalogue carried 88.9x3.2 only as GR250 galvanised pipe,
     * its GR350 CHS of that diameter being a 5.5mm wall. While the grade came from the description
     * alone - and "CHS88.9x3.2" names no grade - the row was quietly filled with galvanised
     * plumbing pipe. Reading the grade COLUMN stopped that and turned it into an honest refusal,
     * and the refusal is what said out loud that AS/NZS 1163 C350 is the ordinary structural grade
     * for CHS and we did not stock it.
     *
     * Stocking it is what closed this, on 2026-10-07: the C350L0 light tube series is in the
     * catalogue now, 88.9x3.2 at 6.76kg/m among it. So this went from fifteen rows and one refusal
     * to sixteen and none, which is the number the comment this replaces asked somebody to account
     * for.
     */
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
