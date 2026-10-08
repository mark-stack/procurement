<?php

use App\Models\Product;
use App\Services\DataClassificationService;
use App\Services\MaterialsJsonImport;
use App\Services\ProductRules;
use Database\Seeders\Data\MasterMaterials;
use Database\Seeders\MasterMaterialsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The committed catalogue, and the seeder that puts it into an empty database.
 *
 * This file used to be mostly about MasterMaterialsParser - the 242 lines that stood between a
 * positionally-mapped CSV and a catalogue where every product's material, grade and dimensions were
 * one column out. Both the CSV and the parser went on 2026-10-08; the rows are PHP now, in
 * Data\MasterMaterials, and a row names every field it sets, so the class of bug the parser existed
 * to prevent cannot be written.
 *
 * What replaced all of it is the first test below, which is a bigger claim than the parser ever
 * made: the committed catalogue has to be able to REBUILD ITSELF through the same rules the admin
 * form enforces. It could not, until the day the CSV went - 25 rows were only ever inserted raw.
 */
it('would be a disaster if the committed catalogue could not rebuild itself', function () {
    /**
     * The whole file, planned against an empty database through ProductRules - which is exactly
     * what `migrate:fresh` followed by a validated sync would do.
     *
     * Zero errors is the bar, and it is a bar the CSV never cleared. Seventeen anchor rods and one
     * nut were refused for a nominal_height that no fastener has ever carried, and seven LVL rows
     * for a grade nobody grades timber by. The seeder's raw insert() was the only reason any of
     * them existed: nothing validated a row on the way in, so a column the data could not supply
     * cost nothing until something asked.
     *
     * Asserted here rather than in the seeder so it is paid once. Everything else trusts the file.
     */
    expect(Product::count())->toBe(0);

    $products = array_map(fn (array $row) => [
        ...array_fill_keys(ProductRules::EDITABLE, null),
        'deprecated' => false,
        ...$row,
    ], MasterMaterials::rows());

    $plan = (new MaterialsJsonImport)->plan(['mode' => 'replace', 'products' => $products]);

    expect($plan['errors'])->toBe([])
        ->and($plan['create'])->toHaveCount(count($products));
});

it('would be a disaster if a row named a category nothing can nest', function () {
    /**
     * nesting_algo and nominal_units are ProductRules::DERIVED, and the seeder resolves them from
     * the category. A row naming a category with no implementation would reach the derive step and
     * fatal on a null config - so the file is only as good as its category column.
     */
    $classifier = new DataClassificationService;

    $unknown = collect(MasterMaterials::rows())
        ->pluck('product_category')
        ->unique()
        ->reject(fn (string $category) => $classifier->findImplementationFromProductCategory($category) !== null)
        ->values()
        ->all();

    expect($unknown)->toBe([]);
});

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
