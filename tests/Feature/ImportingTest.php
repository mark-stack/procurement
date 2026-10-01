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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

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
         * Timber
         */
        MaterialEnums::TIMBER->value => [
            'LVL 100x50',
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

it('would be a disaster if imported duplicate materials accidentally', function () {});

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
