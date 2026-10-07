<?php

use App\Enums\ProductEnums;
use App\Services\DataClassificationService;

/*
 * The notations a steel section arrives in, one group per detailing package, with the
 * attributes each one has to yield. PlateDescriptorTest does this job for plate; this does it
 * for everything that nests by the metre.
 *
 * Nothing used to assert a single extracted attribute, which is how a whole class of silent
 * wrong answers survived: the dimensions came off the relative size of every number in the
 * descriptor, so a length or a grade sharing the cell became the section's own size.
 * "100 PFC 9000 mm" - a 100 PFC nine metres long - resolved to a 9000mm-deep channel, and sat
 * in the passing corpus of ProductCategoryTest while it did.
 *
 * Each case is [descriptor, category, height, width, wall, kg/m]. Null means the descriptor
 * does not carry that attribute, which leaves the catalogue join open rather than wrong.
 */
$australian = [
    //The length in the cell is not the section's depth
    ['150PFC', ProductEnums::PFC, 150.0, null, null, null],
    ['150PFC 9000', ProductEnums::PFC, 150.0, null, null, null],
    ['150PFC 9000mm', ProductEnums::PFC, 150.0, null, null, null],
    ['100 PFC 9000 mm', ProductEnums::PFC, 100.0, null, null, null],
    ['380PFC 15000', ProductEnums::PFC, 380.0, null, null, null],
    ['150PFC x 12000 LONG', ProductEnums::PFC, 150.0, null, null, null],
    ['2 OFF 150PFC', ProductEnums::PFC, 150.0, null, null, null],
    ['150 PFC x 9.0m GR300', ProductEnums::PFC, 150.0, null, null, null],
    //Beams and columns carry a mass, not a wall
    ['310UB40', ProductEnums::UB, 310.0, null, null, 40.0],
    ['310UB40.4', ProductEnums::UB, 310.0, null, null, 40.4],
    ['310 UB 40', ProductEnums::UB, 310.0, null, null, 40.0],
    ['610UB125 12m', ProductEnums::UB, 610.0, null, null, 125.0],
    ['310UB40.4 x 12000', ProductEnums::UB, 310.0, null, null, 40.4],
    ['180UB16.1', ProductEnums::UB, 180.0, null, null, 16.1],
    ['310UC118', ProductEnums::UC, 310.0, null, null, 118.0],
    ['250 UC 89.5', ProductEnums::UC, 250.0, null, null, 89.5],
    //A grade or a length in the group does not displace a dimension
    ['75x50x2.5 RHS', ProductEnums::RHS, 75.0, 50.0, 2.5, null],
    ['150x100x6 RHS GR350', ProductEnums::RHS, 150.0, 100.0, 6.0, null],
    ['250x150x9 RHS x 12000', ProductEnums::RHS, 250.0, 150.0, 9.0, null],
    ['RHS 150x100x6.0 GR350 8000', ProductEnums::RHS, 150.0, 100.0, 6.0, null],
    ['RHS200x100x6.0 GALV', ProductEnums::RHS, 200.0, 100.0, 6.0, null],
    ['100x100x5 SHS', ProductEnums::SHS, 100.0, 100.0, 5.0, null],
    ['100x100x5 SHS x 8000', ProductEnums::SHS, 100.0, 100.0, 5.0, null],
    //A square tube written as a size and a wall, with no group at all
    ['65 SHS 2.5', ProductEnums::SHS, 65.0, 65.0, 2.5, null],
    ['100x100x10 EA', ProductEnums::EA, 100.0, 100.0, 10.0, null],
    ['100x100x10 EA 9000', ProductEnums::EA, 100.0, 100.0, 10.0, null],
    ['90x90x6 EA', ProductEnums::EA, 90.0, 90.0, 6.0, null],
    ['150x100x10 UA', ProductEnums::UA, 150.0, 100.0, 10.0, null],
    //Two equal legs and no thickness written: null beats inventing one
    ['100 x 100mm EA', ProductEnums::EA, 100.0, 100.0, null, null],
    ['165.1 CHS', ProductEnums::CHS, null, 165.1, null, null],
    ['219.1x8.2 CHS', ProductEnums::CHS, null, 219.1, 8.2, null],
    ['20mm round bar', ProductEnums::ROUND, null, 20.0, null, null],
    ['20 DIA ROUND BAR', ProductEnums::ROUND, null, 20.0, null, null],
    ['10 x 100 FLAT BAR', ProductEnums::FLAT, 10.0, 100.0, null, null],
];

/*
 * Tekla, Advance Steel and ProSteel put the profile token first. The separator is configurable
 * and "x" is as common a choice as "*", which only "*" used to read.
 */
$leadingProfile = [
    ['PFC150', ProductEnums::PFC, 150.0, null, null, null],
    ['PFC 150', ProductEnums::PFC, 150.0, null, null, null],
    ['PFC150*75', ProductEnums::PFC, 150.0, null, null, null],
    ['PFC300x90x41.0kg/m', ProductEnums::PFC, 300.0, null, null, null],
    ['PFC 150 x 75 x 17.7', ProductEnums::PFC, 150.0, null, null, null],
    ['UB310*40', ProductEnums::UB, 310.0, null, null, 40.0],
    ['UB310x40', ProductEnums::UB, 310.0, null, null, 40.0],
    ['UC310*118', ProductEnums::UC, 310.0, null, null, 118.0],
    ['UC310x118', ProductEnums::UC, 310.0, null, null, 118.0],
    //Depth, flange width and mass - the mass is the last number, not the first
    ['UB 310x165x40', ProductEnums::UB, 310.0, null, null, 40.0],
    ['UC 310x305x118', ProductEnums::UC, 310.0, null, null, 118.0],
    ['RHS75*50*2.5', ProductEnums::RHS, 75.0, 50.0, 2.5, null],
    ['SHS100*100*5', ProductEnums::SHS, 100.0, 100.0, 5.0, null],
    ['EA100*100*10', ProductEnums::EA, 100.0, 100.0, 10.0, null],
    ['UA150*100*10', ProductEnums::UA, 150.0, 100.0, 10.0, null],
    ['UA150*100*10 x 6000', ProductEnums::UA, 150.0, 100.0, 10.0, null],
    ['CHS219*8', ProductEnums::CHS, null, 219.0, 8.0, null],
    ['CHS 168.3x4.8', ProductEnums::CHS, null, 168.3, 4.8, null],
    ['FL8*75', ProductEnums::FLAT, 8.0, 75.0, null, null],
    ['FL10x100 x 6000', ProductEnums::FLAT, 10.0, 100.0, null, null],
    ['D20', ProductEnums::ROUND, null, 20.0, null, null],
];

/*
 * Tekla UK, and the DIN series Advance Steel writes. All metric, all carrying their depth in
 * millimeters, so they read as written.
 */
$european = [
    ['UKB305x165x40', ProductEnums::UB, 305.0, null, null, 40.0],
    ['UKC305x305x97', ProductEnums::UC, 305.0, null, null, 97.0],
    ['IPE300', ProductEnums::UB, 300.0, null, null, null],
    ['HEA300', ProductEnums::UC, 300.0, null, null, null],
    ['HEB300', ProductEnums::UC, 300.0, null, null, null],
    ['UPN200', ProductEnums::PFC, 200.0, null, null, null],
    ['UPE200', ProductEnums::PFC, 200.0, null, null, null],
    ['L100X100X10', ProductEnums::EA, 100.0, 100.0, 10.0, null],
    ['L150X100X10', ProductEnums::UA, 150.0, 100.0, 10.0, null],
    ['A100x100x10', ProductEnums::EA, 100.0, 100.0, 10.0, null],
    //Width first, thickness second - the opposite of "FL8*75"
    ['FB100x10', ProductEnums::FLAT, 10.0, 100.0, null, null],
    ['FLT10x100', ProductEnums::FLAT, 10.0, 100.0, null, null],
];

/*
 * SDS2, in inches. Converted, never snapped: a 4-inch angle is 101.6mm, not the 100mm angle on
 * the rack. The row then reports as not found with honest numbers against it, which is the same
 * rule the imperial plate notations follow.
 */
$imperial = [
    ['W12X26', ProductEnums::UB, 304.8, null, null, 38.7],
    ['C15X33.9', ProductEnums::PFC, 381.0, null, null, 50.4],
    ['MC18X42.7', ProductEnums::PFC, 457.2, null, null, 63.5],
    ['L4X4X1/2', ProductEnums::EA, 101.6, 101.6, 12.7, null],
    ['L6X4X1/2', ProductEnums::UA, 152.4, 101.6, 12.7, null],
    ['HSS6X4X1/4', ProductEnums::RHS, 152.4, 101.6, 6.4, null],
    ['HSS6X6X1/4', ProductEnums::SHS, 152.4, 152.4, 6.4, null],
    ['TS6X6X1/4', ProductEnums::SHS, 152.4, 152.4, 6.4, null],
    //An outside diameter and a wall, both in inches
    ['HSS6.625X0.280', ProductEnums::CHS, null, 168.3, 7.1, null],
    //A nominal pipe size converts to a nominal bore; the schedule letter is not read
    ['PIPE6STD', ProductEnums::CHS, null, 150.0, null, null],
    ['PIPE4XS', ProductEnums::CHS, null, 100.0, null, null],
];

/*
 * SDS2 writes the same designation in both units, so the depth is the only thing separating
 * them. Above IMPERIAL_MAX_INCHES it is millimeters and no conversion happens.
 */
$metricAisc = [
    ['W310X39', ProductEnums::UB, 310.0, null, null, 39.0],
];

/*
 * Designations that are left for the user on purpose, because the token they turn on means
 * something else just as often.
 *
 * "C150" is an AISC 15-inch channel and also a column mark on a drawing. "R20" is an Advance
 * Steel round bar and also a drawing revision and also a bend radius. Guessing wrong puts the
 * wrong steel in a quote; leaving it unread puts the line in front of the user.
 */
$deliberatelyUnread = [
    'C150',
    'CH150',
    'R20',
];

it('reads an Australian section descriptor', function (string $descriptor, ProductEnums $category, ?float $height, ?float $width, ?float $wall, ?float $kgPerM) {
    expectSectionAttributes($descriptor, $category, $height, $width, $wall, $kgPerM);
})->with($australian);

it('reads a leading-profile section descriptor', function (string $descriptor, ProductEnums $category, ?float $height, ?float $width, ?float $wall, ?float $kgPerM) {
    expectSectionAttributes($descriptor, $category, $height, $width, $wall, $kgPerM);
})->with($leadingProfile);

it('reads a European section designation', function (string $descriptor, ProductEnums $category, ?float $height, ?float $width, ?float $wall, ?float $kgPerM) {
    expectSectionAttributes($descriptor, $category, $height, $width, $wall, $kgPerM);
})->with($european);

it('converts an imperial section designation', function (string $descriptor, ProductEnums $category, ?float $height, ?float $width, ?float $wall, ?float $kgPerM) {
    expectSectionAttributes($descriptor, $category, $height, $width, $wall, $kgPerM);
})->with($imperial);

it('reads a metric AISC designation without converting it', function (string $descriptor, ProductEnums $category, ?float $height, ?float $width, ?float $wall, ?float $kgPerM) {
    expectSectionAttributes($descriptor, $category, $height, $width, $wall, $kgPerM);
})->with($metricAisc);

it('leaves an ambiguous designation unread rather than guessing', function (string $descriptor) {
    $classifier = new DataClassificationService;

    expect($classifier->findProductConfigFromText($descriptor))->toBeNull();
})->with($deliberatelyUnread);

function expectSectionAttributes(
    string $descriptor,
    ProductEnums $category,
    ?float $height,
    ?float $width,
    ?float $wall,
    ?float $kgPerM,
): void {
    $classifier = new DataClassificationService;

    //Finds by regex, not by database record
    $config = $classifier->findProductConfigFromText($descriptor);

    expect($config)->toBeArray()
        ->and($config['productCategory'])->toBe($category->value);

    $expected = [
        'nominalHeightRegex' => $height,
        'nominalWidthRegex' => $width,
        'wallRegex' => $wall,
        'weightRegex' => $kgPerM,
    ];

    foreach ($expected as $regexLabel => $want) {
        $got = $classifier->findNumberByRegex($config, $descriptor, $regexLabel);

        if ($want === null) {
            expect($got)->toBeNull("$descriptor: $regexLabel should be null, read $got");

            continue;
        }

        expect($got)->toEqualWithDelta($want, 0.05, "$descriptor: $regexLabel");
    }
}
