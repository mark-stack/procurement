<?php

use App\Services\DataClassificationService;

it('returns a successful response', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});

it('finds product category', function () {
    $testDescriptions = [
        "Steel Plates 1220x2440",
        "20mm 1220 x 2440",
        "20PL 1220mm",
        "20PL 1220mm",
        "20PL 1220mm",
        "20mm plate GR350",
        "20mm plate Grade 350",
        "20PL 350MPA",
        "20PL 350 MPA",
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
        "LVL 90X63",
        "LVL 90X63",
        "LVL 90X63 7 meter",
        "90X63 LVL 7 meters",
        "90X63 LVL 7 meters",
        "M16x100",
        "SS316 M16 x 150",
        "200PFC 9 meters",

        "UB250*31",
//        "CHS219*8",
//        "LYS-HOOK-LOK-II H2C20PB20",
//        "LYS-HOOK-LOK-II H2L20PB20",
//        "LYS-HOOK-LOK-II H2C20PL20",
        "UC310*118",
        "UB460*67",
        "UC310*118",
        "UC310*118",
        "PFC180*75",
//        "Z20019",
//        "EA100*100*10",
//        "CHS193.7*6.0",
//        "D20",
//        "D20",
//        "CHS33.7*3.2",
        "PLT10*178",
        "PLT6*155",
//        "Z20019",
        "UB310*40",
        "UB530*82",
        "UB530*82",
        "PFC180*75",
        "PFC180*75",
//        "EA75*75*6",
//        "EA75*75*6",
//        "CHS193.7*6.0",
//
        "UB310*40",
        "UC310*118",
//        "EA75*75*6",
//        "EA100*100*10",
//        "CHS193.7*6.0",
//        "Z20019",
//        "FL8*75",
        "PLT5*92",
        "PLT10*160",
        "PLT10*234",
        "PLT10*436",
        "PLT20*159",
        "PLT20*190",
    ];

    $dataClassificationService = new DataClassificationService();

    foreach($testDescriptions as $description){
        //Finds by regex, not by database record
        $product = $dataClassificationService->findProduct($description,null);
        expect($product)->toBeArray();
    }
});

