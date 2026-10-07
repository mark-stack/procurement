<?php

use App\Enums\ProductEnums;
use App\Services\DataClassificationService;

/*
 * The notations a plate arrives in, one per detailing package. Thickness is the only
 * attribute findGeneralProductMatches() joins a plate on - the piece's own width and
 * length come off the template's columns, not out of the descriptor - so reading the
 * thickness right is the whole job here.
 *
 * Each case is [descriptor, thickness in millimeters].
 */
$metric = [
    //Tekla
    ['PL6', 6.0],
    ['PL10', 10.0],
    ['PL10*500', 10.0],
    ['PL10*500*1000', 10.0],
    ['PLT6*60', 6.0],
    ['PLT10*100', 10.0],
    //Advance Steel
    ['PL 20x620x500', 20.0],
    ['PL 6x78.9x70', 6.0],
    ['PL 8x81.1x176.2', 8.0],
    ['PL 6x100x1000', 6.0],
    ['PL 20x600x260', 20.0],
    //Generic structural and fabrication
    ['PL10x500x1000', 10.0],
    ['PL 10 x 500 x 1000', 10.0],
    ['10 PL x 500 x 1000', 10.0],
    ['10PL-500-1000', 10.0],
    ['PL-10-500-1000', 10.0],
    ['PL10-500x1000', 10.0],
    //Australian
    ['PL10x500', 10.0],
    ['10PLx500x1000', 10.0],
    //Stock sheet
    ['6mm 1500x6000', 6.0],
    ['PL 6mm 1500x6000', 6.0],
    //Grade carried alongside the size
    ['S355 PL10', 10.0],
    ['S355 PL10x500x1000', 10.0],
    ['PL10x500x1000-S355', 10.0],
    //Property-driven CAD and sheet-metal BOMs, which name no profile at all
    ['T=10; L=1000; W=500', 10.0],
    ['10mm / 500 / 1000', 10.0],
    ['Thickness=10; Bounding Box=500x1000', 10.0],
    ['Thickness=10; FlatPatternLength=1000; FlatPatternWidth=500', 10.0],
];

/*
 * Imperial and gauge both open with a number that is not a thickness, so each needs
 * converting rather than reading. The results are deliberately NOT snapped to a stocked
 * metric thickness: 1/2" is 12.7mm, and calling it 12mm plate substitutes a material the
 * detailer did not ask for. Where the converted thickness is not stocked the row reports
 * as not found, which is a question for the user rather than an answer we invented.
 */
$imperial = [
    ['PL1/2*4', 12.7],
    ['PL1/2*4*8', 12.7],
    ['PL3/8x1-0', 9.525],
    ['PL1/2x1-0', 12.7],
    ['PL3/8x1-2', 9.525],
    ['1/4" x 4\' x 8\' PL', 6.35],
    ['PL 1/2" x 48" x 96"', 12.7],
];

$gauge = [
    ['PL 10GA', 3.416],
    ['PL 10GA x 48 x 96', 3.416],
    //The 15 1/2 is the width in inches. Reading the gauge first is what stops it becoming the thickness
    ['PL16GAx15 1/2', 1.519],
];

/*
 * Notations that are refused on purpose, each for a different reason. Classifying these
 * would be worse than dropping them, because every one of them would then nest.
 */
$refused = [
    //Unresolved CAD placeholders - exported before anyone filled the template in
    'PL %Thicknessx%Width',
    'PL %Thicknessx%Widthx%Length',
    //Round plate: a thickness and a DIAMETER, which does not nest as a rectangle
    'RPL3/8x1-2',
    //Bent plate: a developed length its bounding box does not describe
    'BPL3/8x1-0',
    /*
     * A bare stock-sheet triple with no plate token at all. 10x1500x6000 is just as good a
     * description of an RHS or a flat bar, and nothing in the row says which.
     */
    '10x1500x6000',
];

it('reads a metric plate thickness', function (string $descriptor, float $thickness) {
    expectPlateThickness($descriptor, $thickness);
})->with($metric);

it('converts an imperial plate thickness', function (string $descriptor, float $thickness) {
    expectPlateThickness($descriptor, $thickness);
})->with($imperial);

it('converts a sheet gauge to a plate thickness', function (string $descriptor, float $thickness) {
    expectPlateThickness($descriptor, $thickness);
})->with($gauge);

it('refuses a descriptor it cannot nest', function (string $descriptor) {
    $classifier = new DataClassificationService;

    expect($classifier->findProductConfigFromText($descriptor))->toBeNull();
})->with($refused);

it('does not read a galvanising note as a sheet gauge', function () {
    //"GALV" opens with the same two letters a gauge does
    expectPlateThickness('PL10 GALV', 10.0);
});

it('leaves a plate with no thickness unresolved rather than guessing one', function () {
    //Matched as plate on the word alone, which is not enough to join it to a product
    $classifier = new DataClassificationService;

    $config = $classifier->findProductConfigFromText('Steel Plate');

    expect($config['productCategory'])->toBe(ProductEnums::PLATE->value)
        ->and($classifier->findNumberByRegex($config, 'Steel Plate', 'nominalHeightRegex'))->toBeNull();
});

function expectPlateThickness(string $descriptor, float $thickness): void
{
    $classifier = new DataClassificationService;

    //Finds by regex, not by database record
    $config = $classifier->findProductConfigFromText($descriptor);

    expect($config)->toBeArray()
        ->and($config['productCategory'])->toBe(ProductEnums::PLATE->value)
        ->and($classifier->findNumberByRegex($config, $descriptor, 'nominalHeightRegex'))
        ->toEqualWithDelta($thickness, 0.001);
}
