<?php

use App\Enums\MaterialEnums;
use App\Enums\NestingEnums;
use App\Services\CsvService;
use App\Services\DataClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

it('would be a disaster if could not import an excel', function () {
    expect(csvArray())->toBeArray();
});

it('would be a disaster if user had no eligible tables', function () {
    //Services
    $csvService = new CsvService;

    //User (staff of mark.laravel.coder@gmail.com)
    $business = createBusiness('gmail', true);
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
    $business = createBusiness('gmail', true);
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
    $business = createBusiness('gmail', true);
    $user = createUser(2, $business, false, true);
    Auth::login($user);

    //Detect tables
    $eligibleTables = $csvService->eligibleTables();
    $detectedTables = $csvService->detectedTables($csvArray, $eligibleTables);

    //Get rows
    $rows = $detectedTables[0]['data'];
    expect($rows)->toBeArray();

    $allSubQuantities = (collect($rows)->pluck('sub_qty')->toArray());

    $shouldBe = [
        5,
        6,
        6,
        6,
        6,
        6,
        6,
        6,
        4,
        10,
        10,
        10.0,
        10,
        10,
        10,
        10,
        10,
        10,
        10,
        10,
        10,
        5,
        5,
        5,
        5,
        5,
        13,
        100,
        13,
        13,
        13,
        13,
        13,
        13,
    ];

    foreach ($allSubQuantities as $index => $subQty) {
        expect($subQty)->toEqual($shouldBe[$index]);
    }
});

it('would be a disaster if imported duplicate materials accidentally', function () {});

it('would be a disaster if back buttons lose progress requiring users to re-upload files', function () {});

it('would be a disaster if user could delete other staff material lists', function () {});

it('would be a disaster if importing misses tables and does not notify the user', function () {});

//todo more
