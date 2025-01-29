<?php

use App\Services\DataClassificationService;

$plate = [
    "Steel Plates 1220x2440",
    "20mm 1220 x 2440",
    "20PL 1220mm",
    "20PL 1220mm",
    "20PL 1220mm",
    "20mm plate GR350",
    "20mm plate Grade 350",
    "20PL 350MPA",
    "20PL 350 MPA",
    "PLT5*92",
    "PLT10*160",
    "PLT10*234",
    "PLT10*436",
    "PLT20*159",
    "PLT20*190",
];

$pfc = [
    "180PFC",
    "180mm Parallel Flange Channel",
    "200mm Parallel Flange Channels",
    "250 PFC 9m",
    "300PFC 9 m",
    "300PFC",
    "150PFC 9000mm",
    "100 PFC 9000 mm",
    "200PFC 9m",
    "200 PFC Mild Steel",
    "PFC SS316",
    "150PFC MS",
    "200PFC 9 meters",
    "PFC180*75",
    "PFC200*75",
];

$ubUc = [
    "UB250*31",
    "UC310*118",
    "UB460*67",
    "UC310*118",
    "UC310*118",
    "UB310*40",
    "UB530*82",
    "UB530*82",
    "UB310*40",
    "UC310*118",
];

$lvl = [
    "LVL 90X63",
    "LVL 90X63",
    "LVL 90X63 7 meter",
    "90X63 LVL 7 meters",
    "90X63 LVL 7 meters",
];

$fasteners = [
    "M16x100",
    "SS316 M16 x 150",
    "M12 Allthread",
    "M12 Chemset",
    "20mm x 1000mm threaded rod",
    "24mm HD bolts",
];

$chs = [
    "CHS219*8",
    "CHS193.7*6.0",
    "CHS33.7*3.2",
];

$purlin = [
    "LYS-HOOK-LOK-II H2C20PB20",
    "LYS-HOOK-LOK-II H2L20PB20",
    "LYS-HOOK-LOK-II H2C20PL20",
    "Z20019",
];

$ea = [
    "EA100*100*10",
    "EA75*75*6",
    "EA75*75*6",
    "EA75*75*6",
    "EA100*100*10",
    "100x100x10 EA",
    "100x100x10EA",
    "100 x 100 x 10 EA",
    "100 x 100mm EA",
];

$ua = [
    "UA100*75*10",
    "UA75*75*6",
    "UA75*75*6",
    "UA75*75*6",
    "UA100*100*10",
    "100x75x8UA",
    "100x75x8 UA",
];

$flat = [
    "FL8*75",
    "100x10mm flatbar",
    "100x10mm flat bar",
    "10FL x 75mm",
    "10FLx75",
    "10x75FL",
    "10mm flatbar x 75mm",
    "10x75mm flatbar",
    "FLAT10x75",
    "FLAT 10x75",
];

$round = [
    "D20",
    "20mm round",
    "20mm round bar",
    "Ø20 bar",
    "Ø20mm bar",
];

$square = [
    "10x10 Square bar",
    "10x10 bar",
    "10x10mm bar",
    "10mm x 10mm Square bar",
];

it('finds product category for PLATE', function (string $description) {
    testProductCategories($description);
})->with($plate);

it('finds product category for PFC', function (string $description) {
    testProductCategories($description);
})->with($pfc);

it('finds product category for UB and UC', function (string $description) {
    testProductCategories($description);
})->with($ubUc);

it('finds product category for LVL', function (string $description) {
    testProductCategories($description);
})->with($lvl);

it('finds product category for FASTENERS', function (string $description) {
    testProductCategories($description);
})->with($fasteners);

it('finds product CHS', function (string $description) {
    testProductCategories($description);
})->with($chs);

it('finds product PURLINS', function (string $description) {
    testProductCategories($description);
})->with($purlin);

it('finds product for EA', function (string $description) {
    testProductCategories($description);
})->with($ea);

it('finds product for UA', function (string $description) {
    testProductCategories($description);
})->with($ua);

it('finds product for FLAT', function (string $description) {
    testProductCategories($description);
})->with($flat);

it('finds product for ROUND', function (string $description) {
    testProductCategories($description);
})->with($round);

function testProductCategories(?string $description): void
{
    $dataClassificationService = new DataClassificationService();

    //Finds by regex, not by database record
    $productConfig = $dataClassificationService->findProductConfigFromText($description);

    expect($productConfig)->toBeArray();
}

