<?php

use App\Enums\ProductEnums;
use App\Services\DataClassificationService;

/*
 * Every case here is [descriptor, expected category].
 *
 * The expected category is the point. This file used to assert only that SOME category came
 * back - expect($productConfig)->toBeArray() - which a wrong answer passes just as easily as a
 * right one. "150PFC AREA200" came back as an angle and "150PFC GALV NUT PLATE" as a nut while
 * every case below was green.
 */
$plate = [
    ['Steel Plates 1220x2440', ProductEnums::PLATE],
    ['20mm 1220 x 2440', ProductEnums::PLATE],
    ['20PL 1220mm', ProductEnums::PLATE],
    ['20mm plate GR350', ProductEnums::PLATE],
    ['20mm plate Grade 350', ProductEnums::PLATE],
    ['20PL 350MPA', ProductEnums::PLATE],
    ['20PL 350 MPA', ProductEnums::PLATE],
    ['PLT5*92', ProductEnums::PLATE],
    ['PLT10*160', ProductEnums::PLATE],
    ['PLT10*234', ProductEnums::PLATE],
    ['PLT10*436', ProductEnums::PLATE],
    ['PLT20*159', ProductEnums::PLATE],
    ['PLT20*190', ProductEnums::PLATE],
];

$pfc = [
    ['180PFC', ProductEnums::PFC],
    ['180mm Parallel Flange Channel', ProductEnums::PFC],
    ['200mm Parallel Flange Channels', ProductEnums::PFC],
    ['250 PFC 9m', ProductEnums::PFC],
    ['300PFC 9 m', ProductEnums::PFC],
    ['300PFC', ProductEnums::PFC],
    ['150PFC 9000mm', ProductEnums::PFC],
    ['100 PFC 9000 mm', ProductEnums::PFC],
    ['200PFC 9m', ProductEnums::PFC],
    ['200 PFC Mild Steel', ProductEnums::PFC],
    ['PFC SS316', ProductEnums::PFC],
    ['150PFC MS', ProductEnums::PFC],
    ['200PFC 9 meters', ProductEnums::PFC],
    ['PFC180*75', ProductEnums::PFC],
    ['PFC200*75', ProductEnums::PFC],
];

$ubUc = [
    ['UB250*31', ProductEnums::UB],
    ['UB460*67', ProductEnums::UB],
    ['UB310*40', ProductEnums::UB],
    ['UB530*82', ProductEnums::UB],
    ['UC310*118', ProductEnums::UC],
];

$lvl = [
    ['LVL 90X63', ProductEnums::LVL],
    ['LVL 90X63 7 meter', ProductEnums::LVL],
    ['90X63 LVL 7 meters', ProductEnums::LVL],
];

$fasteners = [
    ['M16x100', ProductEnums::HEX_BOLT],
    ['SS316 M16 x 150', ProductEnums::HEX_BOLT],
    ['M20 HEX BOLT', ProductEnums::HEX_BOLT],
    ['M12 Allthread', ProductEnums::ALLTHREAD],
    ['M12 Chemset', ProductEnums::ALLTHREAD],
    ['20mm x 1000mm threaded rod', ProductEnums::ALLTHREAD],
    ['24mm HD bolts', ProductEnums::ANCHOR_STUD],
    ['M20x500 D20 ANCHOR ROD', ProductEnums::ANCHOR_STUD],
    ['M16 NUT', ProductEnums::NUT],
    //A named fastener outranks the catch-all, so "csk" beats the longer word "bolt"
    ['M12 CSK BOLT', ProductEnums::CSK_BOLT],
    ['M16 COUNTERSUNK BOLT', ProductEnums::CSK_BOLT],
];

$chs = [
    ['CHS219*8', ProductEnums::CHS],
    ['CHS193.7*6.0', ProductEnums::CHS],
    ['CHS33.7*3.2', ProductEnums::CHS],
    ['150nb CHS', ProductEnums::CHS],
];

$ea = [
    ['EA100*100*10', ProductEnums::EA],
    ['EA75*75*6', ProductEnums::EA],
    ['100x100x10 EA', ProductEnums::EA],
    ['100x100x10EA', ProductEnums::EA],
    ['100 x 100 x 10 EA', ProductEnums::EA],
    ['100 x 100mm EA', ProductEnums::EA],
];

$ua = [
    ['UA100*75*10', ProductEnums::UA],
    ['UA75*75*6', ProductEnums::UA],
    ['UA100*100*10', ProductEnums::UA],
    ['100x75x8UA', ProductEnums::UA],
    ['100x75x8 UA', ProductEnums::UA],
];

$flat = [
    ['FL8*75', ProductEnums::FLAT],
    ['100x10mm flatbar', ProductEnums::FLAT],
    ['100x10mm flat bar', ProductEnums::FLAT],
    ['10FL x 75mm', ProductEnums::FLAT],
    ['10FLx75', ProductEnums::FLAT],
    ['10x75FL', ProductEnums::FLAT],
    ['10mm flatbar x 75mm', ProductEnums::FLAT],
    ['10x75mm flatbar', ProductEnums::FLAT],
    ['FLAT10x75', ProductEnums::FLAT],
    ['FLAT 10x75', ProductEnums::FLAT],
];

$round = [
    ['D20', ProductEnums::ROUND],
    ['20mm round', ProductEnums::ROUND],
    ['20mm round bar', ProductEnums::ROUND],
    ['Ø20 bar', ProductEnums::ROUND],
    ['Ø20mm bar', ProductEnums::ROUND],
    ['Ø20', ProductEnums::ROUND],
];

$shs = [
    ['SHS100*100*5', ProductEnums::SHS],
    ['100x100x5 SHS', ProductEnums::SHS],
    ['65 SHS 2.5', ProductEnums::SHS],
];

/*
 * A section descriptor that mentions a fastener is still a section. The thing being bought is
 * the profile; the nut, the bolt and the washer are features welded or drilled into it.
 *
 * These all used to come back as the fastener, because the fastener pass ran first and claimed
 * the row outright - see DataClassificationService::findProductConfigFromText().
 */
$sectionsMentioningFasteners = [
    ['150PFC GALV NUT PLATE', ProductEnums::PFC],
    ['150PFC WITH M12 HOLES', ProductEnums::PFC],
    ['310UB40 HD BOLT CLEAT', ProductEnums::UB],
    ['310UB40 - 4 OFF M20 BOLTS', ProductEnums::UB],
    ['100x100x10 EA c/w M16 bolt', ProductEnums::EA],
    ['PL10 x 100 SQ WASHER', ProductEnums::PLATE],
    ['12mm PLATE - SCREW BOSS', ProductEnums::PLATE],
    ['150x100x6 RHS + NUT', ProductEnums::RHS],
];

/*
 * Tokens that only look like a profile. A category token needs a word boundary and a dimension
 * group of its own, or a part code and a quantity read as steel: "HEA300" and "AREA100" were
 * both 300mm and 100mm angles, and "4 EA" - four EACH - was a 4mm one.
 */
$notASection = [
    'AREA100',
    'LINEA50',
    '4 EA',
    '12 EA',
    'H2C20PL20',
    'LYS-HOOK-LOK-II H2C20PL20',
    'Z20019',
    /*
     * Square bar, which has no category. There is no ProductEnums case for it and nothing in
     * the catalogue to join it to, so it stays unread rather than being filed as the flat bar
     * or round bar it is not. This list sat in the file as an unused variable.
     */
    '10x10 Square bar',
    '10x10 bar',
    '10x10mm bar',
    '10mm x 10mm Square bar',
];

it('finds product category for PLATE', function (string $description, ProductEnums $expected) {
    expectProductCategory($description, $expected);
})->with($plate);

it('finds product category for PFC', function (string $description, ProductEnums $expected) {
    expectProductCategory($description, $expected);
})->with($pfc);

it('finds product category for UB and UC', function (string $description, ProductEnums $expected) {
    expectProductCategory($description, $expected);
})->with($ubUc);

it('finds product category for LVL', function (string $description, ProductEnums $expected) {
    expectProductCategory($description, $expected);
})->with($lvl);

it('finds product category for FASTENERS', function (string $description, ProductEnums $expected) {
    expectProductCategory($description, $expected);
})->with($fasteners);

it('finds product CHS', function (string $description, ProductEnums $expected) {
    expectProductCategory($description, $expected);
})->with($chs);

it('finds product for EA', function (string $description, ProductEnums $expected) {
    expectProductCategory($description, $expected);
})->with($ea);

it('finds product for UA', function (string $description, ProductEnums $expected) {
    expectProductCategory($description, $expected);
})->with($ua);

it('finds product for FLAT', function (string $description, ProductEnums $expected) {
    expectProductCategory($description, $expected);
})->with($flat);

it('finds product for ROUND', function (string $description, ProductEnums $expected) {
    expectProductCategory($description, $expected);
})->with($round);

it('finds product for SHS', function (string $description, ProductEnums $expected) {
    expectProductCategory($description, $expected);
})->with($shs);

it('reads a section that mentions a fastener as the section', function (string $description, ProductEnums $expected) {
    expectProductCategory($description, $expected);
})->with($sectionsMentioningFasteners);

it('does not read a part code or a quantity as a section', function (string $description) {
    $dataClassificationService = new DataClassificationService;

    expect($dataClassificationService->findProductConfigFromText($description))->toBeNull();
})->with($notASection);

it('reads nothing out of an empty description', function () {
    $dataClassificationService = new DataClassificationService;

    expect($dataClassificationService->findProductConfigFromText(null))->toBeNull()
        ->and($dataClassificationService->findProductConfigFromText(''))->toBeNull()
        ->and($dataClassificationService->findProductConfigFromText('   '))->toBeNull();
});

function expectProductCategory(?string $description, ProductEnums $expected): void
{
    $dataClassificationService = new DataClassificationService;

    //Finds by regex, not by database record
    $productConfig = $dataClassificationService->findProductConfigFromText($description);

    expect($productConfig)->toBeArray()
        ->and($productConfig['productCategory'])->toBe($expected->value);
}
