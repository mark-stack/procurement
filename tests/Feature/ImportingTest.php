<?php

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Enums\MeasurementUnitEnums;
use App\Enums\NestingEnums;
use App\Enums\ProductEnums;
use App\Enums\SurfaceEnums;
use App\Models\Batch;
use App\Models\Piece;
use App\Models\Product;
use App\Models\RawMaterialQuote;
use App\Services\CsvService;
use App\Services\DataClassificationService;
use App\Services\PieceService;
use App\Services\SheetNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/**
 * A minimal template spec, in the shape Template::detectionSpec() hands the importer: three
 * headings, and the three columns under them one row down.
 *
 * Written out rather than built from a Template so these tests are about the extraction and not
 * about the record - the conversion from cell references to offsets has its own tests.
 *
 * @return array<string, mixed>
 */
function probeSpec(array $overrides = []): array
{
    return array_merge([
        'label' => 'Probe',
        'source' => 'TEKLA',
        'type' => 'CAD_BILL_OF_MATERIALS',
        'ExpectedHeadingLabels' => ['Profile', 'Qty', 'Length'],
        'OffsetFromHeaderToFirstDataRow' => 1,
        //Column A: the description, which is what the skip and stop rules watch
        'skipOrFinishCheckRelativeOffset' => 0,
        'ShouldSkipRow' => null,
        'isLastDataRow' => null,
        'compoundDescription' => null,
        'assemblyMarkRule' => ['NONE'],
        'nominalUnits' => 'mm',
        'DescriptionRelativeOffset' => 0,
        'MaterialRelativeOffset' => null,
        'GradeRelativeOffset' => null,
        'SurfaceRelativeOffset' => null,
        'LengthRelativeOffset' => 2,
        'WidthRelativeOffset' => null,
        'SubQtyRelativeOffset' => 1,
    ], $overrides);
}

it('would be a disaster if could not import an excel', function () {
    expect(csvArray())->toBeArray();
});

it('would be a disaster if user had no eligible tables', function () {
    //Services
    $csvService = new CsvService;

    //User (staff of mark.laravel.coder@gmail.com), with the examples recorded against
    //their business - a business is created with no templates and imports nothing
    $business = createBusiness('gmail');
    recordExampleTemplates($business);
    $user = createUser(2, $business, false, true);
    Auth::login($user);

    $eligibleTables = $csvService->eligibleTables();
    expect(count($eligibleTables))->toBeGreaterThan(0);
});

it('would be a disaster if could not detect a table', function () {
    //Services
    $csvService = new CsvService;

    //Imports Excel
    $csvArray = csvArray();

    //User (staff of mark.laravel.coder@gmail.com)
    $business = createBusiness('gmail');
    recordExampleTemplates($business);

    $user = createUser(2, $business, false, true);
    Auth::login($user);

    $eligibleTables = $csvService->eligibleTables();
    $detectedTables = $csvService->detectedTables($csvArray, $eligibleTables);
    expect(count($detectedTables))->toBeGreaterThan(0);
});

it('would be a disaster if length meters vs millimeters was mixed up', function () {
    /**
     * A user might provide a BOM in METERS, MILLIMETERS, or a mix of both.
     * The basic rule is below 20 = METERS. Because 20mm of steel is unrealistic.
     * todo Post MVP add a user clarification for 20-100 as it's not certain if M or MM
     */
    $csvService = new CsvService;
    $algo = NestingEnums::METERAGE->value;

    $meters = [
        1.000,
        2.440,
        3.000,
        5.500,
        6.000,
        12,
        12.0,
        9,
    ];
    $millimeters = [
        100,
        1500,
        850,
        12000,
        5000,
    ];

    //Meters = 1000x
    foreach ($meters as $lengthRequired) {
        $result = $csvService->normalisedLength($algo, $lengthRequired);
        expect($result)->toEqual($lengthRequired * 1000);
    }

    //Millimeters = same
    foreach ($millimeters as $lengthRequired) {
        $result = $csvService->normalisedLength($algo, $lengthRequired);
        expect($result)->toEqual($lengthRequired);
    }
});

it('would be a disaster if materials misidentified', function () {
    /**
     * Check "PLAIN_CARBON_STEEL", "SS316", "TIMBER", etc
     */
    $dataClassificationService = new DataClassificationService;

    $data = [
        /**
         * Plain carbon steel (default)
         */
        MaterialEnums::PLAIN_CARBON_STEEL->value => [
            '200PFC',
            'M16x50',
            '200UB31',
            '100x100x10EA',
            '20PL 1220mm',
            'RHS75*50*2.5',
        ],
        /**
         * Stainless
         */
        MaterialEnums::STAINLESS_STEEL->value => [
            '200PFC 316SS',
            '200PFC 316 SS',
            '200PFC SS316',
            '200PFC SS 316',
            '200PFC 316 stainless steel',
            '200PFC 304SS',
            '200PFC 304 SS',
            '200PFC SS304',
            '200PFC SS 304',
            '200PFC 304 stainless steel',
        ],
        /**
         * Hardox
         */
        MaterialEnums::HARDOX->value => [
            '10PL Hardox',
            '16PL Hardox',
            '20PL Hardox',
        ],
        /**
         * Alloy
         */
        MaterialEnums::ALLOY->value => [
            '10PL Chromium',
            '10PL Manganese',
            '10PL Nickel',
            '10PL Molybdenum',
            '10PL Duplex',
            '10PL Tool Steel',
            '10PL Tungsten',
            '10PL Spring Steel',
        ],
        /**
         * Aluminium
         */
        MaterialEnums::ALUMINIUM->value => [
            '10PL Aluminium',
        ],
        /**
         * Plastic
         */
        MaterialEnums::PLASTIC->value => [
            '10PL Plastic',
        ],
    ];

    foreach ($data as $material => $descriptions) {
        foreach ($descriptions as $description) {
            $productConfig = $dataClassificationService->findProductConfigFromText($description);
            expect($productConfig)->toBeArray();

            $materialEnum = $dataClassificationService->findMaterial($productConfig, $description);
            expect($materialEnum->value)->toBe($material);
        }
    }
});

it('would be a disaster if quantities misidentified', function () {
    //Services
    $csvService = new CsvService;

    //Imports Excel
    $csvArray = csvArray();

    //User (staff of mark.laravel.coder@gmail.com)
    $business = createBusiness('gmail');
    recordExampleTemplates($business);

    $user = createUser(2, $business, false, true);
    Auth::login($user);

    //Detect tables
    $eligibleTables = $csvService->eligibleTables();
    $detectedTables = $csvService->detectedTables($csvArray, $eligibleTables);

    //Get rows
    $rows = $detectedTables[0]['data'];
    expect($rows)->toBeArray();

    $allSubQuantities = (collect($rows)->pluck('sub_qty')->toArray());

    /**
     * The SubQty column of public/examples/material_list.xlsx, read straight off
     * the sheet. Asserting these proves the parser locks onto the SubQty column
     * rather than Length, Width or Rate sitting either side of it.
     */
    $shouldBe = [
        6,      //20PL 1220mm
        6,      //20mm plate GR350
        10,     //250 PFC 9m
        13,     //75x50x2.5 RHS
        5,      //LVL 90X63
        5,      //90X63 LVL 7 meters
        13,     //M16x100
        100,    //SS316 M16  x 150
    ];

    //Guard the fixture itself - a short read would otherwise pass silently
    expect($allSubQuantities)->toHaveCount(count($shouldBe));

    foreach ($allSubQuantities as $index => $subQty) {
        expect($subQty)->toEqual($shouldBe[$index]);
    }
});

it('would be a disaster if imported duplicate materials accidentally', function () {
    /**
     * A report that reprints its heading row every page.
     *
     * Every row of the sheet is matched against every template, so a repeated heading was two
     * detections and two tables - and nothing stopped the first one where the second began, so it
     * read the whole sheet while the second read the rows below the second heading a second time.
     * Four rows of steel came out as seven: the heading row itself as a material, and two rows
     * ordered twice. Both tables looked right on the test screen, which reported "found 2 times,
     * each is read as its own table, and all of them import".
     *
     * Each band now ends where the next begins.
     */
    $csvService = new CsvService;

    $tables = $csvService->detectedTables([
        ['Profile', 'Qty', 'Length'],
        ['310UB40', '2', '9000'],
        ['250PFC', '3', '6000'],
        ['Profile', 'Qty', 'Length'],
        ['150UB14', '4', '4000'],
        ['200UB25', '5', '3000'],
    ], [probeSpec()]);

    $descriptions = collect($tables)->pluck('data')->flatten(1)->pluck('description');

    expect($tables)->toHaveCount(2)
        ->and($descriptions->all())->toBe(['310UB40', '250PFC', '150UB14', '200UB25'])
        //The heading row is not a material, and nothing is read twice
        ->and($descriptions->duplicates()->all())->toBe([]);
});

it('would be a disaster if a blank row ended the table and lost the rest of the materials', function () {
    /**
     * The one failure in importing with no symptom of its own.
     *
     * The end-of-table rule was "two consecutive blank cells in the check column", and a blank
     * spacer row followed by an assembly or phase heading - a row with text in column A and nothing
     * in the check column - is two blank check cells. So a grouped report stopped at the end of its
     * first group, and every material below it was silently never read: no exception, nothing in the
     * customer's "could not be read" list, and a test screen that reported the handful it did
     * extract as "2 of 2 rows would import".
     *
     * The rule is now "the check column has run out and does not resume", which steps over the
     * spacers.
     */
    $csvService = new CsvService;

    /*
     * A Mark column to the left of the heading run, which is what these reports look like and what
     * puts the group heading somewhere other than the column being watched.
     */
    $sheet = [
        [null, 'Profile', 'Qty', 'Length'],
        ['A1', '310UB40', '2', '9000'],
        ['A2', '250PFC', '3', '6000'],
        [null, null, null, null],
        [null, null, null, null],
        ['ASSEMBLY B2', null, null, null],
        ['B1', '150UB14', '4', '4000'],
        ['B2', '200UB25', '5', '3000'],
        ['B3', '90x90x6EA', '6', '1200'],
    ];

    $tables = $csvService->detectedTables($sheet, [probeSpec()]);

    expect(collect($tables)->pluck('data')->flatten(1)->pluck('description')->all())
        ->toBe(['310UB40', '250PFC', '150UB14', '200UB25', '90x90x6EA']);

    /*
     * And the other way: one line on its own after a gap is a footer, not the table resuming. The
     * bolt summary example imported "MEnd of report  mm" as a material when this was not the rule.
     */
    $withFooter = $csvService->detectedTables([
        [null, 'Profile', 'Qty', 'Length'],
        ['A1', '310UB40', '2', '9000'],
        [null, null, null, null],
        [null, null, null, null],
        ['End of report', null, null, null],
    ], [probeSpec()]);

    expect(collect($withFooter)->pluck('data')->flatten(1)->pluck('description')->all())
        ->toBe(['310UB40']);
});

it('would be a disaster if the heading labels anchored to the wrong column', function () {
    /**
     * The anchor is the origin every column offset is measured from, so a heading row read two
     * columns early shifts the whole table - and every label matched, so nothing looked wrong.
     *
     * Labels are matched in order but not side by side, which is deliberate: these reports leave
     * empty columns between their headings. It also means a row that repeats a label offers more
     * than one valid run, and the old rule took the leftmost - "Mark, Length, Mark, Qty, Length"
     * answered column A for the labels Mark/Qty/Length because Mark in A, Qty in D and Length in E
     * are in order. The tightest run is the heading; the other one is an accident.
     */
    $csvService = new CsvService;
    $spec = [...probeSpec(), 'ExpectedHeadingLabels' => ['Mark', 'Qty', 'Length']];

    expect($csvService->headerStartIndex(['Mark', 'Length', 'Mark', 'Qty', 'Length'], $spec))->toBe(2)
        //One of each: still the only run there is
        ->and($csvService->headerStartIndex(['Mark', 'Qty', 'Length'], $spec))->toBe(0)
        //Two whole tables side by side: the first one, not the second
        ->and($csvService->headerStartIndex(['Mark', 'Qty', 'Length', 'Mark', 'Qty', 'Length'], $spec))->toBe(0);
});

it('would be a disaster if a trailing space stopped a template matching its own report', function () {
    /**
     * Labels are compared literally - "Length (mm)" and "Length" are two different reports - and
     * the sheet's own value was compared untrimmed. So a later export carrying a trailing space, or
     * a non-breaking space out of a sheet that has been through a web page, silently stopped
     * matching: the file reported as a format we had never seen, and learning wrote a second
     * near-duplicate template for a table that already had one.
     */
    $csvService = new CsvService;
    $spec = [...probeSpec(), 'ExpectedHeadingLabels' => ['Profile', 'Length (mm)']];

    expect($csvService->headerStartIndex(['Profile', 'Length (mm) '], $spec))->toBe(0)
        ->and($csvService->headerStartIndex([' Profile', "Length\u{a0}(mm)"], $spec))->toBe(0)
        //Still literal: a label that is genuinely a different label does not match
        ->and($csvService->headerStartIndex(['Profile', 'Length'], $spec))->toBeNull();
});

it('would be a disaster if the figure in a cell was not the figure that was written', function () {
    /**
     * Lengths and quantities used to be read by stripping the letters out of the cell and casting
     * to float, and a cast stops at the first separator. Every one of these imported at a size
     * nobody had written down, and none of them raised anything:
     *
     *  - "12,500" became 12.5 - which the meters rule then made 12500mm, half a metre short.
     *  - "9,5", a decimal comma, became 9.
     *  - "3'-6"" became 3.
     *  - "1 1/2" became 1.
     *  - a quantity of "1,500" became 1.5, so fifteen hundred parts were ordered as one.
     */
    $csvService = new CsvService;
    $algo = NestingEnums::METERAGE->value;

    $length = fn (string $cell) => $csvService->normalisedLength(
        $algo,
        SheetNumber::tryFrom($cell)?->value,
        SheetNumber::tryFrom($cell)?->units,
    );

    expect($length('12,500'))->toBe(12500.0)
        ->and($length('9,5'))->toBe(9500.0)
        ->and($length('1 250'))->toBe(1250.0)
        //Feet and inches are a measurement, so they settle their own units
        ->and(round((float) $length('3\'-6"')))->toBe(1067.0)
        ->and(round((float) $length('48"')))->toBe(1219.0)
        //A fraction with no inch mark is a fraction of whatever the column is in
        ->and($length('1 1/2'))->toBe(1500.0)
        //A cell that names its own unit is believed, which is what settles a genuine 15mm part
        ->and($length('15mm'))->toBe(15.0)
        ->and($length('9m'))->toBe(9000.0)
        //And an unmarked cell still goes by magnitude - see normalisedLength()
        ->and($length('15'))->toBe(15000.0)
        ->and($length('9000'))->toBe(9000.0)
        //Nothing to read is still nothing, which is a row reported as unreadable
        ->and($length('-'))->toBeNull()
        ->and($length('N/A'))->toBeNull()
        ->and($length('#REF!'))->toBeNull();

    expect($csvService->getSubQty('1,500'))->toBe(1500.0)
        ->and($csvService->getSubQty('2 off'))->toBe(2.0)
        //A missing multiplier must not zero the quantity
        ->and($csvService->getSubQty('0'))->toBe(1.0)
        ->and($csvService->getSubQty(''))->toBe(1.0);
});

it('would be a disaster if the grade column on the sheet was not the grade that was bought', function () {
    /**
     * The material, grade and surface columns were read off the sheet, stored on the row, and then
     * read by nothing: matching was done on the description alone. So a Tekla export with "310UB40"
     * in Profile and "GRADE 350" in Grade was matched with no grade at all - and where exactly one
     * catalogue product happened to match, it was bought at whatever grade that product was.
     *
     * The surface column is deliberately not matched on: a finish column is a fabrication
     * instruction, not a purchasing one. The examples carry "PAINTED", and nobody buys painted
     * sections from a steel merchant.
     */
    $classifier = new DataClassificationService;

    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);
    Auth::login($user);
    seedMasterMaterials();

    //The catalogue carries this section at GR300 only, which is what makes the filter visible
    $withoutGrade = $classifier->findGeneralProductMatchesFromText('310UB40', $user);
    $asStocked = $classifier->findGeneralProductMatchesFromText('310UB40', $user, ['grade' => 'GRADE 300']);
    $asAskedFor = $classifier->findGeneralProductMatchesFromText('310UB40', $user, ['grade' => 'GRADE 350']);

    expect($withoutGrade['results'])->not->toBeEmpty()
        //The grade column is read and joined on, rather than stored and ignored
        ->and($asStocked['allFieldsIndividual']['grade'])->toBeTrue()
        ->and(collect($asStocked['results'])->pluck('grade')->unique()->all())->toBe([GradeEnums::GR300->value])
        /*
         * And a grade we do not stock finds nothing, which is reported to the customer rather than
         * quietly filled with the grade we do stock.
         */
        ->and($asAskedFor['results'])->toBeEmpty();

    //A description that names its own grade is the more specific statement, so it wins
    $disagreeing = $classifier->findGeneralProductMatchesFromText('310UB40 GR300', $user, ['grade' => 'GRADE 350']);

    expect(collect($disagreeing['results'])->pluck('grade')->unique()->all())->toBe([GradeEnums::GR300->value]);

    //And the finish column changes nothing about what is matched
    expect($classifier->findGeneralProductMatchesFromText('310UB40', $user, ['surface' => 'PAINTED'])['results'])
        ->toHaveCount(count($withoutGrade['results']));
});

it('would be a disaster if a short word in a description became a grade or a finish', function () {
    /**
     * These are short strings matched against a whole BOM line, and they were matched as bare
     * substrings. "gal" found the GAL in "REGAL", "h2" the H2 in a mark like "W1H250", and "MS" the
     * MS in "BEAMS" - each one puts a finish or a grade on a row that never had one, and both are
     * ANDed into the catalogue query, so a plain black PFC came back matching nothing at all and
     * was reported to the customer as not in the price book.
     *
     * Bounded by the kind of character the pattern ends in, which is what lets "GR4.6" and "4.6S"
     * both still read as grade 4.6 while 14.65 does not.
     */
    $classifier = new DataClassificationService;
    $config = $classifier->findProductConfigFromText('250PFC');

    expect($classifier->findSurface($config, '250PFC REGAL'))->toBeNull()
        ->and($classifier->findSurface($config, '310UB40 W1H250'))->toBeNull()
        ->and($classifier->findGrades($config, 'BEAMS 310UB40'))->toBeNull()
        ->and($classifier->findGrades($config, '310UB40 14.65 KG'))->toBeNull()
        //Still found when it is actually said
        ->and($classifier->findSurface($config, '250PFC GAL'))->toBe(SurfaceEnums::GALVANISED)
        ->and($classifier->findSurface($config, '310UB40 H2'))->toBe(SurfaceEnums::TREATED_H2)
        ->and($classifier->findGrades($config, 'M16 4.6S 45mm'))->toBe([GradeEnums::GR_4_6])
        ->and($classifier->findGrades($config, 'M16x100 GR4.6'))->toBe([GradeEnums::GR_4_6])
        /*
         * And the first match wins rather than the last, so a finish named two ways is the one it
         * is named as first: "GALV ZINC RICH" used to come back as zinc.
         */
        ->and($classifier->findSurface($config, '310UB40 GALV ZINC RICH'))->toBe(SurfaceEnums::GALVANISED);
});

it('would be a disaster if back buttons lose progress requiring users to re-upload files', function () {});

it('would be a disaster if user could delete other staff material lists', function () {
    /*
     * The Bill of Materials modal opens on any colleague's project - the Nesting column is shared -
     * and it hides the row checkboxes on one that is not yours. The ids still travel in the request
     * body, and this endpoint scoped them to the BUSINESS, so the hidden checkbox was the only
     * thing standing between a colleague and your material list.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $colleaguesProject = createProject($colleague);
    $theirRow = createRawMaterialQuote200Pfc(
        $colleaguesProject,
        MaterialEnums::PLAIN_CARBON_STEEL,
        GradeEnums::GR300,
        6000,
    );

    $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('raw.material.quote.bulk.destroy'), [
            'selectedRawMaterialQuoteIds' => [$theirRow->id],
        ])
        ->assertRedirect();

    expect($theirRow->fresh())->not->toBeNull();
});

it('would be a disaster if user could clarify other staff material lists', function () {
    /*
     * The same hole, through the other endpoint, and a worse one: clarifying commits a product
     * choice and deletes the rows listed in deletedIds outright. The modal never hid this form on a
     * colleague's project at all, so the one thing you could not do to somebody else's BOM was add
     * to it.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $colleaguesProject = createProject($colleague);
    $theirRow = createRawMaterialQuote200Pfc(
        $colleaguesProject,
        MaterialEnums::PLAIN_CARBON_STEEL,
        GradeEnums::GR300,
        6000,
    );

    $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('raw.material.quote.clarifications'), [
            'deletedIds' => [$theirRow->id],
        ])
        ->assertRedirect();

    expect($theirRow->fresh())->not->toBeNull();
});

it('still lets a project manager clear rows from their own material list', function () {
    /*
     * The other half of the rule above - the scope narrowed from the business to the owner, so
     * getting it wrong would lock everybody out of their own BOM.
     */
    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $myRow = createRawMaterialQuote200Pfc(
        $project,
        MaterialEnums::PLAIN_CARBON_STEEL,
        GradeEnums::GR300,
        6000,
    );

    $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('raw.material.quote.bulk.destroy'), [
            'selectedRawMaterialQuoteIds' => [$myRow->id],
        ])
        ->assertRedirect();

    expect($myRow->fresh())->toBeNull();
});

it('would be a disaster if importing misses tables and does not notify the user', function () {});

it('would be a disaster if re-posting a clarification cut the material row twice', function () {
    /*
     * The clarification endpoint takes its whole payload from the request body and used to Piece::create
     * unconditionally, so a double click, a stale tab or a retried request minted a SECOND piece for the
     * same BOM line. The BOM could not show it - RawMaterialQuote::piece() is a hasOne and reads the
     * first - while NestingFormatter::piecesReadyForBatching reads the pieces table directly, so the
     * nest bought and cut both: a row asking for three lengths had six cut and paid for.
     */
    seedMasterMaterials();

    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    $product = Product::query()->where('product_category', ProductEnums::PFC->value)->firstOrFail();

    $row = RawMaterialQuote::create([
        'csv_index' => 1,
        'description' => 'PFC',
        'product_category' => ProductEnums::PFC->value,
        'material' => $product->material,
        'grade' => $product->grade,
        'surface' => $product->surface,
        'nominal_units' => MeasurementUnitEnums::MILLIMETERS->value,
        'length_required' => 6000,
        'sub_qty' => 3,
        'project_id' => $project->id,
        'general_product_matches' => serialize(['allFields' => false, 'allFieldsIndividual' => [], 'results' => []]),
        'assembly_mark' => '',
    ]);

    $payload = [
        'deletedIds' => [],
        '0' => [
            'selected' => 0,
            'custom' => false,
            'data' => ['id' => $row->id, 'nesting_algo' => NestingEnums::METERAGE->value],
            'options' => [[
                'product_category' => $product->product_category,
                'material' => $product->material,
                'grade' => $product->grade,
                'surface' => $product->surface,
                'nominal_units' => $product->nominal_units,
                'nominal_length' => $product->nominal_length,
                'nominal_width' => $product->nominal_width,
                'nominal_height' => $product->nominal_height,
                'wall' => $product->wall,
                'kg_per_m' => $product->kg_per_m,
            ]],
        ],
    ];

    $this->actingAs($user);

    $this->post(route('raw.material.quote.clarifications'), $payload)->assertRedirect();
    $this->post(route('raw.material.quote.clarifications'), $payload)->assertRedirect();

    expect(Piece::where('raw_material_quote_id', $row->id)->count())->toBe(1)
        //The quantity the line actually asked for, not twice it
        ->and((int) Piece::where('raw_material_quote_id', $row->id)->sum('actual_qty'))->toBe(3);
});

it('leaves a piece that is already nested alone when the clarification is re-posted', function () {
    /*
     * The other half of the rule: a piece on a batch has been costed, quoted and possibly delivered
     * around its spec, so a stale form must not rewrite it.
     */
    seedMasterMaterials();

    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);
    $project = createProject($user);
    $batch = Batch::factory()->forUser($user->id)->create();

    $row = createRawMaterialQuote200Pfc(
        $project,
        MaterialEnums::PLAIN_CARBON_STEEL,
        GradeEnums::GR300,
        9000,
    );

    $piece = Piece::create([
        'project_id' => $project->id,
        'raw_material_quote_id' => $row->id,
        'batch_id' => $batch->id,
        'product_category' => ProductEnums::PFC->value,
        'material' => MaterialEnums::PLAIN_CARBON_STEEL->value,
        'grade' => GradeEnums::GR300->value,
        'surface' => SurfaceEnums::NONE->value,
        'actual_length' => 9000,
        'actual_qty' => 2,
    ]);

    $written = (new PieceService)->writePiece($row, [
        'project_id' => $project->id,
        'product_category' => ProductEnums::PFC->value,
        'material' => MaterialEnums::PLAIN_CARBON_STEEL->value,
        'grade' => GradeEnums::GR300->value,
        'surface' => SurfaceEnums::NONE->value,
        'actual_length' => 1234,
        'actual_qty' => 99,
    ]);

    expect($written->id)->toBe($piece->id)
        ->and($piece->fresh()->actual_length)->toBe('9000')
        ->and($piece->fresh()->actual_qty)->toBe('2');
});

//todo more
