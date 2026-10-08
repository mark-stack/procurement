<?php

use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\MaterialsJsonImport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/**
 * Bulk changes to the catalogue as reviewed JSON: products generated elsewhere, and the catalogue
 * moved between environments.
 *
 * What this replaces was one admin button that rewrote all 1,150 rows with no preview, no diff and
 * no way to know afterwards what had changed.
 */
function jsonProduct(array $overrides = []): array
{
    return [
        'description' => '200PFC 9m',
        'product_category' => 'PFC',
        'material' => 'PLAIN_CARBON_STEEL',
        'grade' => 'GR300',
        'surface' => 'NONE',
        'certificates' => true,
        'nominal_length' => '9000',
        'nominal_height' => '200',
        'kg_per_m' => 25.1,
        'pack_size_1' => 1,
        ...$overrides,
    ];
}

function importPayload(array $products, string $mode = 'merge'): string
{
    return json_encode(['mode' => $mode, 'products' => $products]);
}

/*
|--------------------------------------------------------------------------
| Reading the file
|--------------------------------------------------------------------------
*/

it('would be a disaster if broken JSON was reported as "Syntax error"', function () {
    // The author of the file has to be able to act on the answer
    expect(fn () => (new MaterialsJsonImport)->decode('{"products": [}'))
        ->toThrow(RuntimeException::class, 'That is not valid JSON');
});

it('would be a disaster if a bare array of products was refused over a missing wrapper', function () {
    /**
     * It is the shape anyone asked to "generate some steel products as JSON" hands back, and
     * refusing it would be pedantry.
     */
    $payload = (new MaterialsJsonImport)->decode(json_encode([jsonProduct()]));

    expect($payload['mode'])->toBe('merge')
        ->and($payload['products'])->toHaveCount(1);
});

it('would be a disaster if an unrecognised mode quietly behaved like one of the real ones', function () {
    expect(fn () => (new MaterialsJsonImport)->decode(importPayload([jsonProduct()], 'overwrite')))
        ->toThrow(RuntimeException::class, '"mode" must be one of');
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('would be a disaster if a misspelled key imported as a blank', function () {
    /**
     * A product whose kg_per_metre was ignored looks complete and costs as though it weighed
     * nothing. Every nest of that section is then priced wrongly and nothing says so.
     */
    $import = new MaterialsJsonImport;
    $plan = $import->plan($import->decode(importPayload([
        jsonProduct(['kg_per_metre' => 25.1]),
    ])));

    expect($plan['counts']['errors'])->toBe(1)
        ->and($plan['errors'][0]['messages'][0])->toContain('kg_per_metre')
        ->and($plan['counts']['create'])->toBe(0);
});

it('would be a disaster if a file could set the columns the category decides', function () {
    /**
     * nesting_algo and nominal_units follow from the product category. A PLATE imported as METERAGE
     * would nest as a bar while looking perfectly ordinary.
     */
    $import = new MaterialsJsonImport;
    $plan = $import->plan($import->decode(importPayload([
        jsonProduct(['nesting_algo' => 'BUNDLE']),
    ])));

    expect($plan['counts']['errors'])->toBe(1)
        ->and($plan['errors'][0]['messages'][0])->toContain('set by the product category');
});

it('would be a disaster if the two derived columns were not filled in from the category', function () {
    $import = new MaterialsJsonImport;
    $plan = $import->plan($import->decode(importPayload([
        jsonProduct([
            'product_category' => 'PLATE',
            'nominal_height' => '10',
            'nominal_width' => '1200',
            'nominal_length' => '2400',
            'kg_per_m' => null,
        ]),
    ])));

    $import->apply($plan);

    expect(Product::sole()->nesting_algo)->toBe('AREA')
        ->and(Product::sole()->nominal_units)->toBe('MILLIMETERS');
});

it('would be a disaster if a bad grade got in through the importer after the form refused it', function () {
    //Otherwise the importer just becomes the way past the form's rules
    $import = new MaterialsJsonImport;
    $plan = $import->plan($import->decode(importPayload([
        jsonProduct(['material' => 'SS316']),
    ])));

    expect($plan['counts']['errors'])->toBe(1)
        ->and(implode(' ', $plan['errors'][0]['messages']))->toContain('material');
});

it('would be a disaster if two rows for one product applied in file order', function () {
    // Only the last would survive, and nobody would be told the first was discarded
    $import = new MaterialsJsonImport;
    $plan = $import->plan($import->decode(importPayload([
        jsonProduct(['kg_per_m' => 25.1]),
        jsonProduct(['kg_per_m' => 30.0, 'description' => 'Same product, heavier']),
    ])));

    expect($plan['counts']['create'])->toBe(1)
        ->and($plan['counts']['errors'])->toBe(1)
        ->and($plan['errors'][0]['messages'][0])->toContain('Row 1 already describes this product');
});

it('would be a disaster if any invalid row let the rest of the file through', function () {
    /**
     * A catalogue half way between two versions of itself, with no record of where it stopped, is
     * worse than one that did not change.
     */
    $import = new MaterialsJsonImport;
    $plan = $import->plan($import->decode(importPayload([
        jsonProduct(),
        jsonProduct(['product_category' => 'NOT_A_CATEGORY', 'description' => 'Bad']),
    ])));

    expect(fn () => $import->apply($plan))->toThrow(RuntimeException::class, '1 rows are invalid');
    expect(Product::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The plan
|--------------------------------------------------------------------------
*/

it('would be a disaster if a preview wrote anything', function () {
    $import = new MaterialsJsonImport;
    $import->plan($import->decode(importPayload([jsonProduct()])));

    expect(Product::count())->toBe(0);
});

it('would be a disaster if a row matched an existing product by its description', function () {
    /**
     * The old importer keyed on description, so rewording one stranded the product as a deprecated
     * duplicate and created a second. Rows match by SPEC - which is also why an import can never
     * detach existing work: a matched row is by definition not changing any column that pieces, bars
     * or offcuts find their product through.
     */
    $import = new MaterialsJsonImport;
    $import->apply($import->plan($import->decode(importPayload([jsonProduct()]))));

    $id = Product::sole()->id;

    $plan = $import->plan($import->decode(importPayload([
        jsonProduct(['description' => '200 PFC, 9 metre', 'kg_per_m' => 25.9]),
    ])));

    expect($plan['counts']['create'])->toBe(0)
        ->and($plan['counts']['update'])->toBe(1)
        ->and($plan['update'][0]['id'])->toBe($id)
        ->and(array_keys($plan['update'][0]['changes']))->toEqualCanonicalizing(['description', 'kg_per_m']);

    $import->apply($plan);

    expect(Product::count())->toBe(1)
        ->and(Product::sole()->id)->toBe($id)
        ->and(Product::sole()->kg_per_m)->toBe(25.9);
});

it('would be a disaster if a column the file left out was blanked without saying so', function () {
    /**
     * A row is a whole product, not a patch - that is what makes an export round-trip faithful. So
     * the plan has to list every column it would blank, as plainly as the ones it would correct.
     */
    $import = new MaterialsJsonImport;
    $import->apply($import->plan($import->decode(importPayload([
        jsonProduct(['baseline_supplier' => 'https://example.test/ref.pdf']),
    ]))));

    $plan = $import->plan($import->decode(importPayload([jsonProduct()])));

    expect($plan['update'][0]['changes'])->toHaveKey('baseline_supplier')
        ->and($plan['update'][0]['changes']['baseline_supplier']['from'])->toBe('https://example.test/ref.pdf')
        ->and($plan['update'][0]['changes']['baseline_supplier']['to'])->toBeNull();
});

it('would be a disaster if a value that only looks different was reported as a change', function () {
    // "2.0" and 2.0 are the same wall thickness, and a diff full of those is a diff nobody reads
    $import = new MaterialsJsonImport;
    $import->apply($import->plan($import->decode(importPayload([
        jsonProduct(['product_category' => 'RHS', 'nominal_width' => '50', 'wall' => 2.0, 'kg_per_m' => 3.72]),
    ]))));

    $plan = $import->plan($import->decode(importPayload([
        jsonProduct(['product_category' => 'RHS', 'nominal_width' => '50', 'wall' => '2.00', 'kg_per_m' => 3.72]),
    ])));

    expect($plan['counts']['update'])->toBe(0)
        ->and($plan['counts']['unchanged'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Merge and replace
|--------------------------------------------------------------------------
*/

it('would be a disaster if adding a few new sections deprecated the rest of the catalogue', function () {
    /**
     * This is the whole reason there are two modes. The thing being replaced deprecated everything
     * absent from the file on every run, so an additive batch would have retired the catalogue.
     */
    $import = new MaterialsJsonImport;
    $import->apply($import->plan($import->decode(importPayload([jsonProduct()]))));

    $plan = $import->plan($import->decode(importPayload([
        jsonProduct(['nominal_height' => '150', 'description' => '150PFC 9m', 'kg_per_m' => 17.7]),
    ], 'merge')));

    expect($plan['counts']['deprecate'])->toBe(0);

    $import->apply($plan);

    expect(Product::query()->active()->count())->toBe(2);
});

it('would be a disaster if a full catalogue sync deleted what the source no longer lists', function () {
    /**
     * Deprecated, never deleted. Those products may still have pieces, bars, offcuts, quotes and
     * orders matched on them, and no foreign key anywhere would stop the delete - it would simply
     * leave that work matching nothing.
     */
    $import = new MaterialsJsonImport;
    $import->apply($import->plan($import->decode(importPayload([
        jsonProduct(['description' => 'Kept']),
        jsonProduct(['description' => 'Dropped', 'nominal_height' => '150']),
    ]))));

    $plan = $import->plan($import->decode(importPayload([
        jsonProduct(['description' => 'Kept']),
    ], 'replace')));

    expect($plan['counts']['deprecate'])->toBe(1)
        ->and($plan['deprecate'][0]['description'])->toBe('Dropped');

    $import->apply($plan);

    expect(Product::count())->toBe(2)
        ->and(Product::where('description', 'Kept')->first()->deprecated)->toBeFalse()
        ->and(Product::where('description', 'Dropped')->first()->deprecated)->toBeTrue();
});

it('would be a disaster if a failed import left the catalogue half changed', function () {
    $import = new MaterialsJsonImport;
    $import->apply($import->plan($import->decode(importPayload([jsonProduct()]))));

    $plan = $import->plan($import->decode(importPayload([
        jsonProduct(['kg_per_m' => 99.0]),
        jsonProduct(['nominal_height' => '150', 'description' => 'New one']),
    ])));

    //A column the table does not have, forced in past validation to make the write itself fail
    $plan['create'][0]['attributes']['no_such_column'] = 'boom';

    expect(fn () => $import->apply($plan))->toThrow(QueryException::class);

    expect(Product::count())->toBe(1)
        ->and(Product::sole()->kg_per_m)->toBe(25.1);
});

/*
|--------------------------------------------------------------------------
| Through the screen
|--------------------------------------------------------------------------
*/

it('would be a disaster if an import could be applied without being seen first', function () {
    adminActingAs($this);

    $this->post(route('admin.materials.import.preview'), [
        'json' => importPayload([jsonProduct()]),
    ])->assertRedirect();

    //The plan came back for review, and nothing was written
    $plan = session('materialsImportPlan');

    expect($plan['counts']['create'])->toBe(1)
        ->and(Product::count())->toBe(0);

    $this->post(route('admin.materials.import.apply'), [
        'json' => $plan['json'],
        'fingerprint' => $plan['fingerprint'],
    ])->assertRedirect();

    expect(Product::count())->toBe(1)
        ->and(session('materials')['ok'])->toBeTrue();
});

it('would be a disaster if a plan approved against one catalogue applied to another', function () {
    /**
     * The file is re-sent to be applied rather than parked in the session, so the catalogue can move
     * underneath it - another admin adding the very product this file would create, say.
     */
    adminActingAs($this);

    $this->post(route('admin.materials.import.preview'), [
        'json' => importPayload([jsonProduct()]),
    ]);

    $plan = session('materialsImportPlan');

    //Somebody else adds it in the meantime, so this file would now be an update, not a create
    platformProduct();

    $this->post(route('admin.materials.import.apply'), [
        'json' => $plan['json'],
        'fingerprint' => $plan['fingerprint'],
    ])->assertRedirect();

    expect(session('materials')['ok'])->toBeFalse()
        ->and(session('materials')['message'])->toContain('changed while this import was being reviewed')
        ->and(Product::count())->toBe(1);
});

it('would be a disaster if a non-admin could import or export the catalogue', function () {
    $business = createBusiness('gmail');
    $user = createUser(2, $business, false, true);

    $this->actingAs($user);

    $this->post(route('admin.materials.import.preview'), ['json' => importPayload([jsonProduct()])])
        ->assertRedirect('/');
    $this->post(route('admin.materials.import.apply'), ['json' => '{}', 'fingerprint' => 'x'])
        ->assertRedirect('/');
    $this->get(route('admin.materials.export'))->assertRedirect('/');

    expect(Product::count())->toBe(0);
});

it('would be a disaster if an uploaded file was read any differently from pasted text', function () {
    adminActingAs($this);

    $this->post(route('admin.materials.import.preview'), [
        'file' => UploadedFile::fake()->createWithContent(
            'materials.json',
            importPayload([jsonProduct()]),
        ),
    ])->assertRedirect();

    expect(session('materialsImportPlan')['counts']['create'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Keeping two environments in step
|--------------------------------------------------------------------------
*/

it('would be a disaster if the export could not be imported back', function () {
    /**
     * This is the sync story, and it only works if the round trip is exact: export from one
     * environment, import into the other, and a catalogue that already matches reports nothing to do.
     *
     * The export deliberately omits nesting_algo and nominal_units, because a file carrying them is
     * refused - so exporting them would produce a file that could not be imported at all.
     *
     * Run against the real catalogue rather than a fixture, because that is where the awkward
     * inherited rows are, and a round trip that refused any of them would mean production could
     * never be copied anywhere. The seven LVL products with no grade at all used to be the worst of
     * them; timber left the catalogue on 2026-10-08 and took that case with it.
     */
    adminActingAs($this);
    seedMasterMaterials();

    $exported = $this->get(route('admin.materials.export'))
        ->assertOk()
        ->assertHeader('content-disposition')
        ->getContent();

    $import = new MaterialsJsonImport;
    $plan = $import->plan($import->decode($exported));

    expect($plan['mode'])->toBe('replace')
        ->and($plan['counts']['create'])->toBe(0)
        ->and($plan['counts']['update'])->toBe(0)
        ->and($plan['counts']['deprecate'])->toBe(0)
        /*
         * Zero, not "few". Any invalid row blocks the whole import, so one bad row in the catalogue
         * is enough to make a full-catalogue sync impossible - which is exactly what an anchor rod
         * whose nominal length was "`50" and a hex bolt with a grade in its material column did
         * until both were corrected. This is what stops another one arriving unnoticed.
         */
        ->and($plan['counts']['errors'])->toBe(0)
        ->and($plan['counts']['unchanged'])->toBe(Product::count());
});

it('would be a disaster if a row the importer rejects were not already flagged on the screen', function () {
    /**
     * The invariant that keeps bad data findable: anything the rules refuse, the catalogue screen
     * says out loud. Otherwise the only way to discover a broken row is to try to import one, and
     * "M8 HAS 5.8 anchor rod" would still have a nominal length of "`50" - a backtick typed for a 1,
     * which matches no piece spec and quietly makes that anchor rod unpurchasable.
     */
    adminActingAs($this);
    seedMasterMaterials();

    $exported = $this->get(route('admin.materials.export'))->getContent();

    $import = new MaterialsJsonImport;
    $plan = $import->plan($import->decode($exported));

    //Every rejected row is a product this screen already marks as holding a bad value
    $flagged = collect(ProductResource::collection(Product::query()->platformCreated()->get())->resolve())
        ->filter(fn (array $product) => $product['invalidValues'] !== [])
        ->count();

    expect($plan['counts']['errors'])->toBeLessThanOrEqual($flagged);
});

it('would be a disaster if syncing an environment lost which products were deprecated', function () {
    /**
     * Deprecated products are exported too. Leaving them out would mean a round trip could not tell
     * a product that was deliberately retired from one that never existed.
     */
    adminActingAs($this);
    platformProduct(['description' => 'Current']);
    platformProduct(['description' => 'Retired', 'nominal_height' => '150', 'deprecated' => true]);

    $exported = $this->get(route('admin.materials.export'))->getContent();

    expect(json_decode($exported, true)['products'])->toHaveCount(2);

    //A second environment with an empty catalogue takes the file
    Product::query()->delete();

    $import = new MaterialsJsonImport;
    $import->apply($import->plan($import->decode($exported)));

    expect(Product::count())->toBe(2)
        ->and(Product::where('description', 'Current')->first()->deprecated)->toBeFalse()
        ->and(Product::where('description', 'Retired')->first()->deprecated)->toBeTrue();
});

it('would be a disaster if a partial export could be imported as a replace', function () {
    /**
     * The property the everyday workflow rests on. "Export what I changed this week" hands back three
     * products; if that file said mode "replace", importing it would deprecate the other 1,104.
     *
     * So the mode is derived from whether the file is the whole catalogue, not chosen by whoever
     * opens it.
     */
    adminActingAs($this);

    platformProduct(['description' => 'Old', 'updated_at' => now()->subMonths(2)]);
    platformProduct(['description' => 'Touched this week', 'nominal_height' => '150']);

    $subset = json_decode(
        $this->get(route('admin.materials.export', ['since' => now()->subWeek()->toDateString()]))->getContent(),
        true,
    );

    expect($subset['mode'])->toBe('merge')
        ->and($subset['products'])->toHaveCount(1)
        ->and($subset['products'][0]['description'])->toBe('Touched this week');

    //And the whole catalogue still comes back as a replace file
    $full = json_decode($this->get(route('admin.materials.export'))->getContent(), true);

    expect($full['mode'])->toBe('replace')
        ->and($full['products'])->toHaveCount(2);
});

it('would be a disaster if syncing a few changed items touched anything else', function () {
    /**
     * "I corrected two weights here, get them into production." Importing that subset must change
     * exactly those two products and leave every other one alone - not deprecate them for being
     * absent, and not report them as changed.
     */
    adminActingAs($this);

    $import = new MaterialsJsonImport;
    $import->apply($import->plan($import->decode(importPayload([
        jsonProduct(['description' => 'Untouched', 'kg_per_m' => 25.1]),
        jsonProduct(['description' => 'Corrected', 'nominal_height' => '150', 'kg_per_m' => 1.0]),
    ]))));

    //Only the corrected one is in the file, as a "changed since" export would have it
    $plan = $import->plan($import->decode(importPayload([
        jsonProduct(['description' => 'Corrected', 'nominal_height' => '150', 'kg_per_m' => 17.7]),
    ], 'merge')));

    expect($plan['counts']['update'])->toBe(1)
        ->and($plan['counts']['create'])->toBe(0)
        ->and($plan['counts']['deprecate'])->toBe(0);

    $import->apply($plan);

    expect(Product::where('description', 'Corrected')->first()->kg_per_m)->toBe(17.7)
        ->and(Product::where('description', 'Untouched')->first()->kg_per_m)->toBe(25.1)
        ->and(Product::query()->active()->count())->toBe(2);
});

it('would be a disaster if the export carried a blank and a null as different values', function () {
    /**
     * The table holds both for the same absence. Two environments' exports would then differ over
     * which of them happened to store '' that day, and every diff would be full of it.
     */
    adminActingAs($this);
    platformProduct(['precise_length' => '', 'baseline_supplier' => '']);

    $products = json_decode($this->get(route('admin.materials.export'))->getContent(), true)['products'];

    expect($products[0]['precise_length'])->toBeNull()
        ->and($products[0]['baseline_supplier'])->toBeNull();
});
