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
    "D20",
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

$eaUa = [
    "EA100*100*10",
    "EA75*75*6",
    "EA75*75*6",
    "EA75*75*6",
    "EA100*100*10",
];

$flat = [
    "FL8*75",
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

it('finds product for EA and UA', function (string $description) {
    testProductCategories($description);
})->with($eaUa);

it('finds product for FLAT', function (string $description) {
    testProductCategories($description);
})->with($flat);

function testProductCategories(?string $description): void
{
    $dataClassificationService = new DataClassificationService();

    //Finds by regex, not by database record
    $productConfig = $dataClassificationService->findProductConfigFromText($description);

    expect($productConfig)->toBeArray();
}

