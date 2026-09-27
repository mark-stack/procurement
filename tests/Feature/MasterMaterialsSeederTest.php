<?php

use App\Models\Product;
use App\Services\MasterMaterialsParser;
use Database\Seeders\MasterMaterialsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The spreadsheet is no longer the source of truth - the products table is, and admins edit it
 * directly. What remains is the parser, and the seeder that uses it to fill an empty database.
 *
 * The parser is still worth all of this. It is the only thing standing between the CSV's positional
 * column mapping and a catalogue where every product's material, grade and dimensions are one column
 * out.
 */

/**
 * A CSV stream built from rows, so a test can describe the sheet it needs.
 *
 * @return resource
 */
function materialsCsv(array $rows, ?array $header = null)
{
    $handle = fopen('php://memory', 'r+');

    fputcsv($handle, $header ?? MasterMaterialsParser::HEADER, escape: '');
    foreach ($rows as $row) {
        fputcsv($handle, $row, escape: '');
    }

    rewind($handle);

    return $handle;
}

/**
 * One fully specified sheet row, overridable by column index.
 */
function materialsRow(array $overrides = []): array
{
    $row = [
        '200PFC 9m', 'PFC', 'PLAIN_CARBON_STEEL', 'GR300', 'NONE', 'METERAGE', 'TRUE',
        'MILLIMETERS', '9000', '', '', '', '200', '', '', '1', '', '', '25.1',
        'https://example.test/reference.pdf',
    ];

    foreach ($overrides as $index => $value) {
        $row[$index] = $value;
    }

    return $row;
}

it('would be a disaster if a fully specified product was dropped for having no description', function () {
    // Three real RHS products in the master sheet carry no DESCRIPTION and used to vanish
    $handle = materialsCsv([
        materialsRow(),
        materialsRow([0 => '', 1 => 'RHS', 12 => '75', 10 => '50', 14 => '2.0', 18 => '3.72']),
    ]);

    $result = (new MasterMaterialsParser)->parse($handle);

    expect($result->rows)->toHaveCount(2)
        ->and($result->rejected)->toBeEmpty()
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0]['reason'])->toContain('description');
});

it('would be a disaster if an unusable row was imported as a product', function () {
    // A stray value in one cell is not a product, and skipping it must be reported
    $handle = materialsCsv([
        materialsRow(),
        materialsRow(array_fill_keys(range(0, 17), '') + [18 => '1.75', 19 => '']),
    ]);

    $result = (new MasterMaterialsParser)->parse($handle);

    expect($result->rows)->toHaveCount(1)
        ->and($result->rejected)->toHaveCount(1)
        ->and($result->rejected[0]['line'])->toBe(3)
        ->and($result->rejected[0]['reason'])->toContain('product_category')
        ->and(implode(' ', $result->messages()))->toContain('1 rows skipped');
});

it('would be a disaster if a blank separator row was reported as a problem', function () {
    $handle = materialsCsv([
        materialsRow(),
        array_fill(0, 20, ''),
    ]);

    $result = (new MasterMaterialsParser)->parse($handle);

    expect($result->rows)->toHaveCount(1)
        ->and($result->rejected)->toBeEmpty()
        ->and($result->warnings)->toBeEmpty();
});

it('would be a disaster if a reordered spreadsheet imported silently', function () {
    // Columns are mapped by position, so a swap would write material into grade for every row
    $header = MasterMaterialsParser::HEADER;
    [$header[2], $header[3]] = [$header[3], $header[2]];

    $handle = materialsCsv([materialsRow()], $header);

    expect(fn () => (new MasterMaterialsParser)->parse($handle))
        ->toThrow(RuntimeException::class, 'Unexpected master materials columns');
});

it('would be a disaster if an Excel byte order mark made the file look reordered', function () {
    // Excel writes a UTF-8 BOM before the first cell, and trim() does not remove it
    $header = MasterMaterialsParser::HEADER;
    $header[0] = "\xEF\xBB\xBF".$header[0];

    $result = (new MasterMaterialsParser)->parse(materialsCsv([materialsRow()], $header));

    expect($result->rows)->toHaveCount(1);
});

it('would be a disaster if certificates were stored as text the app reads as a boolean', function () {
    /**
     * where('certificates', true) matched nothing while (bool) $product->certificates was true for
     * every product, so the mill certificate sense check was meaningless either way.
     */
    $handle = materialsCsv([
        materialsRow([0 => 'Certified', 6 => 'TRUE']),
        materialsRow([0 => 'Uncertified', 1 => 'HEX_BOLT', 5 => 'BUNDLE', 6 => 'FALSE']),
    ]);

    $result = (new MasterMaterialsParser)->parse($handle);

    expect($result->rows[0]['certificates'])->toBeTrue()
        ->and($result->rows[1]['certificates'])->toBeFalse();
});

it('would be a disaster if an unreadable certificates cell passed without a word', function () {
    /**
     * A blank or misspelled CERTS cell stores null, which where('certificates', true) excludes,
     * so the product silently drops out of the mill certificate sense check.
     */
    $handle = materialsCsv([
        materialsRow([0 => 'Readable', 6 => 'TRUE']),
        materialsRow([0 => 'Blank', 6 => '', 12 => '150']),
        materialsRow([0 => 'Typo', 6 => 'TRUEE', 12 => '250']),
    ]);

    $result = (new MasterMaterialsParser)->parse($handle);

    expect($result->rows)->toHaveCount(3)
        ->and($result->rejected)->toBeEmpty()
        ->and($result->warnings)->toHaveCount(2)
        ->and($result->warnings[0]['reason'])->toContain('unreadable certificates')
        ->and($result->warnings[1]['line'])->toBe(4)
        ->and(implode(' ', $result->messages()))->toContain('2 rows imported with blank fields');
});

it('would be a disaster if the spreadsheet supplier reference was thrown away', function () {
    $result = (new MasterMaterialsParser)->parse(materialsCsv([materialsRow()]));

    expect($result->rows[0]['baseline_supplier'])->toBe('https://example.test/reference.pdf');
});

/*
|--------------------------------------------------------------------------
| The seeder
|--------------------------------------------------------------------------
*/

it('would be a disaster if the catalogue could not be loaded into an empty database', function () {
    /**
     * A BOM import against an empty products table silently extracts nothing. It used to be filled
     * by an admin button nobody knew they had to press.
     */
    expect(Product::count())->toBe(0);

    (new MasterMaterialsSeeder)->run();

    expect(Product::count())->toBeGreaterThan(1000)
        ->and(Product::query()->platformCreated()->count())->toBe(Product::count())
        ->and(Product::query()->active()->count())->toBe(Product::count());
});

it('would be a disaster if re-seeding undid the edits an admin had made', function () {
    /**
     * The old import reconciled the whole catalogue against the sheet on every run, so a product an
     * admin had deprecated came back and a weight they had corrected was overwritten. The products
     * table is the source of truth now, so the seeder declines rather than reconciles.
     */
    (new MasterMaterialsSeeder)->run();

    $product = Product::query()->platformCreated()->first();
    $product->update(['kg_per_m' => 999.0, 'deprecated' => true]);

    $countBefore = Product::count();

    (new MasterMaterialsSeeder)->run();

    expect(Product::count())->toBe($countBefore)
        ->and($product->fresh()->kg_per_m)->toBe(999.0)
        ->and($product->fresh()->deprecated)->toBeTrue();
});

it('would be a disaster if seeded products carried no timestamps', function () {
    //insert() bypasses the model, so nothing fills these in unless the seeder does
    (new MasterMaterialsSeeder)->run();

    expect(Product::query()->whereNull('created_at')->count())->toBe(0);
});

it('would be a disaster if the seeded catalogue disagreed with its own categories', function () {
    /**
     * nesting_algo and nominal_units follow from the product category. A PLATE seeded as METERAGE
     * would nest as a bar while looking perfectly ordinary, so the admin screen derives both rather
     * than accepting them - which is only safe if the inherited rows already agree.
     */
    (new MasterMaterialsSeeder)->run();

    $disagreeing = Product::query()
        ->get()
        ->filter(function (Product $product) {
            $implementation = (new App\Services\DataClassificationService)
                ->findImplementationFromProductCategory($product->product_category);

            if (! $implementation) {
                return false;
            }

            return $product->nesting_algo !== $implementation->config()['algorithm']->value
                || $product->nominal_units !== $implementation->config()['measurementUnit']->value;
        });

    expect($disagreeing)->toBeEmpty();
});
