<?php

use App\Services\CsvService;
use App\Services\DataClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Where a grouped report's table ends.
 *
 * A Tekla material list bands its rows by section and subtotals each band. The subtotal line is
 * blank in the Profile column, so to the end-of-table rule it reads as a gap - and the rule used to
 * need two rows together below a gap to believe the table carried on. A band of ONE row satisfies
 * that for nothing, so a single 300 PFC between two subtotals ended the table, and everything below
 * it was never read.
 *
 * Never read is the failure worth a test. The rows reach none of the three lists the import reports
 * back - not found, from another plan, could not be read - so there is nothing to see: the import
 * says it worked and the quote is short. This is the seven-row list that showed it, in each of the
 * three shapes the same report arrives in.
 */
$blankRow = [null, null, null, null, null, null];

$headingRow = ['Profile', 'Grade', 'Qty', 'Length(mm)', 'Area(m2)', 'Weight(kg)'];

$subtotalRow = fn (float $length) => [null, 'Subtotal', null, $length, null, null];

$preamble = [
    [null, null, 'Material_List', null, null, null],
    $blankRow,
    ['Tekla Structures Material List for contract No.:', null, null, 'Page: 1', null, null],
    ['Project:', null, null, 'Date: 12.09.2018', null, null],
    $blankRow,
];

//One band of one row, one of two, one of one, one of three - the band of one is the whole point
$chs = ['CHS114.3*5.4', '300PLUS', 1, 3865, 1.4, 56.1];

$pfc125a = ['PFC125*65', '300PLUS', 2, 3495, 1.7, 41.6];

$pfc125b = ['PFC125*65', '300PLUS', 4, 3679, 1.8, 43.8];

$pfc300 = ['PFC300*90', '300PLUS', 2, 3679, 3.4, 147.5];

$rhsA = ['RHS150*100*6.0', '300PLUS', 1, 11310, 5.4, 242.4];

$rhsB = ['RHS150*100*6.0', '300PLUS', 1, 11734, 5.6, 251.5];

$rhsC = ['RHS150*100*6.0', '300PLUS', 1, 12103, 5.7, 259.4];

$footerRow = [null, null, 'Page 1', null, null, null];

$dashedRule = ['--------------------', null, null, null, null, null];

$layouts = [
    //A spacer row either side of every subtotal, which is what the printed report looks like
    'blank spacer rows' => [array_merge($preamble, [
        $headingRow,
        $chs, $blankRow, $subtotalRow(3865), $blankRow,
        $pfc125a, $pfc125b, $blankRow, $subtotalRow(21705), $blankRow,
        $pfc300, $blankRow, $subtotalRow(7358), $blankRow,
        $rhsA, $rhsB, $rhsC,
        $footerRow,
    ])],
    //The same bands with nothing between them but the subtotal
    'subtotals alone' => [array_merge($preamble, [
        $headingRow,
        $chs, $subtotalRow(3865),
        $pfc125a, $pfc125b, $subtotalRow(21705),
        $pfc300, $subtotalRow(7358),
        $rhsA, $rhsB, $rhsC,
        $footerRow,
    ])],
    //The text report's rules, landing in the first column
    'dashed rules' => [array_merge($preamble, [
        $headingRow,
        $dashedRule, $chs, $dashedRule, $subtotalRow(3865), $dashedRule,
        $pfc125a, $pfc125b, $dashedRule, $subtotalRow(21705), $dashedRule,
        $pfc300, $dashedRule, $subtotalRow(7358), $dashedRule,
        $rhsA, $rhsB, $rhsC,
        $footerRow,
    ])],
];

$template = [
    'label' => 'Tekla material list',
    'source' => 'test',
    'type' => 'sections',
    'ExpectedHeadingLabels' => $headingRow,
    'OffsetFromHeaderToFirstDataRow' => 1,
    'skipOrFinishCheckRelativeOffset' => 0,
    'ShouldSkipRow' => null,
    'isLastDataRow' => null,
    'compoundDescription' => null,
    'assemblyMarkRule' => ['NONE', null],
    'nominalUnits' => 'MILLIMETERS',
    'DescriptionRelativeOffset' => 0,
    'MaterialRelativeOffset' => null,
    'GradeRelativeOffset' => 1,
    'SurfaceRelativeOffset' => null,
    'LengthRelativeOffset' => 3,
    'WidthRelativeOffset' => null,
    'SubQtyRelativeOffset' => 2,
];

it('reads every band of a grouped report, including a band of one', function (array $csvArray) use ($template) {
    $materials = importedMaterialDescriptions($csvArray, $template);

    expect($materials)->toBe([
        'CHS114.3*5.4',
        'PFC125*65',
        'PFC125*65',
        //The band of one, and everything the table used to end before
        'PFC300*90',
        'RHS150*100*6.0',
        'RHS150*100*6.0',
        'RHS150*100*6.0',
    ]);
})->with($layouts);

/*
 * The other half of the rule, which this must not undo: a table that has genuinely ended stays
 * ended. A footer names no material, so on its own it is no reason to read on - which is what the
 * run-of-two was put there for, after the bolt summary example imported "MEnd of report  mm".
 */
it('ends the table at a footer with no material below it', function () use ($template, $preamble, $headingRow, $blankRow, $chs, $pfc125a) {
    $csvArray = array_merge($preamble, [
        $headingRow,
        $chs,
        $pfc125a,
        $blankRow,
        ['End of report', null, null, null, null, null],
    ]);

    expect(importedMaterialDescriptions($csvArray, $template))->toBe([
        'CHS114.3*5.4',
        'PFC125*65',
    ]);
});

it('ends the table where the next material is past the lookahead', function () use ($template, $preamble, $headingRow, $blankRow, $chs, $pfc125a, $pfc300) {
    $csvArray = array_merge($preamble, [
        $headingRow,
        $chs,
        $pfc125a,
        $blankRow,
        ['End of report', null, null, null, null, null],
        $blankRow, $blankRow, $blankRow, $blankRow, $blankRow,
        //Six clear rows below the gap, so this belongs to whatever comes next, not to this table
        $pfc300,
    ]);

    expect(importedMaterialDescriptions($csvArray, $template))->toBe([
        'CHS114.3*5.4',
        'PFC125*65',
    ]);
});

/*
 * What the change costs, written down as a test so it is a decision rather than a surprise.
 *
 * A material row within the lookahead carries the table over a footer, and the footer row comes with
 * it. It imports as nothing - "End of report" names no product, so saveRawMaterialQuoteData() drops
 * it - but the row below it is now read where it was not before.
 *
 * That is the trade the comment on isLastDataRowNoDataBelow() already makes, and in the same
 * direction: a row read that should not have been is a row somebody can see and say so about, and a
 * row never read is four missing beams and an import that reported success.
 */
it('reads on past a footer when a material follows it', function () use ($template, $preamble, $headingRow, $blankRow, $chs, $pfc125a, $pfc300) {
    $csvArray = array_merge($preamble, [
        $headingRow,
        $chs,
        $pfc125a,
        $blankRow,
        ['End of report', null, null, null, null, null],
        $blankRow,
        $pfc300,
    ]);

    expect(importedMaterialDescriptions($csvArray, $template))->toBe([
        'CHS114.3*5.4',
        'PFC125*65',
        'PFC300*90',
    ]);
});

/**
 * The descriptions this template would import out of this sheet, in order. Filtered to the rows that
 * name a material, because a subtotal or a page footer inside the table's extent is dropped later by
 * saveRawMaterialQuoteData() rather than by the table rules - see the bare continue there.
 *
 * @param  array<int, array<int, mixed>>  $csvArray
 * @param  array<string, mixed>  $template
 * @return array<int, string>
 */
function importedMaterialDescriptions(array $csvArray, array $template): array
{
    $classifier = new DataClassificationService;

    $tables = (new CsvService)->detectedTables($csvArray, [$template]);

    expect($tables)->toHaveCount(1);

    $descriptions = [];

    foreach ($tables[0]['data'] as $row) {
        if ($classifier->findProductConfigFromText($row['description']) !== null) {
            $descriptions[] = $row['description'];
        }
    }

    return $descriptions;
}
