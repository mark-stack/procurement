<?php

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Models\Bar;
use App\Models\Batch;
use App\Models\Offcut;
use App\Models\Piece;
use App\Models\Product;
use App\Models\Project;
use App\Services\ProductUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The master materials catalogue as something an admin edits.
 *
 * The hazard the whole screen is built around: pieces, bars and offcuts have NO product_id. They
 * record the product's spec on their own rows, and nesting finds a product by querying those columns.
 * So editing one of them on a product something is already built on does not correct that work - it
 * detaches it, and nothing in the database refuses.
 */
function adminActingAs($test)
{
    $business = createBusiness('gmail', true);
    $admin = createUser(1, $business, true, true);
    $test->actingAs($admin);

    return $admin;
}

/**
 * A complete form body. Every editable column is 'present'-validated, so a partial body is rejected
 * for the columns it left out rather than leaving them alone - which is deliberate: a form that
 * silently ignored a field it forgot to send would be worse.
 */
function materialPayload(array $overrides = []): array
{
    return [
        'description' => '200PFC 9m',
        'product_category' => 'PFC',
        'material' => 'PLAIN_CARBON_STEEL',
        'grade' => 'GR300',
        'surface' => 'NONE',
        'certificates' => true,
        'nominal_length' => '9000',
        'precise_length' => null,
        'nominal_width' => null,
        'precise_width' => null,
        'nominal_height' => '200',
        'precise_height' => null,
        'wall' => null,
        'pack_size_1' => 1,
        'pack_size_2' => null,
        'pack_size_3' => null,
        'kg_per_m' => 25.1,
        'baseline_supplier' => 'https://example.test/ref.pdf',
        'deprecated' => false,
        ...$overrides,
    ];
}

/**
 * A product straight into the table, bypassing the form, so a test can set up a state the form
 * would refuse.
 */
function platformProduct(array $overrides = []): Product
{
    return Product::create([
        'description' => '200PFC 9m',
        'product_category' => 'PFC',
        'material' => 'PLAIN_CARBON_STEEL',
        'grade' => 'GR300',
        'surface' => 'NONE',
        'nesting_algo' => 'METERAGE',
        'nominal_units' => 'MILLIMETERS',
        'certificates' => true,
        'nominal_length' => '9000',
        'nominal_height' => '200',
        'kg_per_m' => 25.1,
        'pack_size_1' => '1',
        'business_id' => null,
        'deprecated' => false,
        ...$overrides,
    ]);
}

/**
 * A cut list item whose spec matches the product, the way every real one does - by value, with no
 * foreign key anywhere.
 */
function pieceMatching(Product $product, Project $project): Piece
{
    $rawMaterialQuote = createRawMaterialQuote200Pfc(
        $project,
        MaterialEnums::PLAIN_CARBON_STEEL,
        GradeEnums::GR300,
        9000,
    );

    return Piece::create([
        'project_id' => $project->id,
        'raw_material_quote_id' => $rawMaterialQuote->id,
        'product_category' => $product->product_category,
        'material' => $product->material,
        'grade' => $product->grade,
        'surface' => $product->surface,
        'nominal_units' => $product->nominal_units,
        'nesting_algo' => $product->nesting_algo,
        'nominal_length' => $product->nominal_length,
        'nominal_height' => $product->nominal_height,
        'actual_length' => 4500,
        'actual_qty' => 2,
    ]);
}

/*
|--------------------------------------------------------------------------
| Who can reach it
|--------------------------------------------------------------------------
*/

it('would be a disaster if a non-admin could edit the platform catalogue', function () {
    $business = createBusiness('gmail', true);
    $user = createUser(2, $business, false, true);
    $product = platformProduct();

    $this->actingAs($user);

    $this->get(route('admin.materials.index'))->assertRedirect('/');
    $this->post(route('admin.materials.store'), materialPayload())->assertRedirect('/');
    $this->patch(route('admin.materials.update', $product), materialPayload(['kg_per_m' => 1]))->assertRedirect('/');
    $this->delete(route('admin.materials.destroy', $product))->assertRedirect('/');

    expect(Product::count())->toBe(1)
        ->and($product->fresh()->kg_per_m)->toBe(25.1);
});

it("would be a disaster if this screen could edit another business's private product", function () {
    /**
     * {product} resolves by id alone, so a mistyped id reaches a product a business added itself
     * from a BOM line the catalogue could not match. Editing it from here would change their price
     * book, and the only sign would be their product quietly changing.
     */
    adminActingAs($this);

    $otherBusiness = createBusiness('other', true);
    $theirs = platformProduct(['business_id' => $otherBusiness->id, 'description' => 'Theirs']);

    $this->patch(route('admin.materials.update', $theirs), materialPayload(['kg_per_m' => 99]))
        ->assertNotFound();
    $this->delete(route('admin.materials.destroy', $theirs))->assertNotFound();

    expect($theirs->fresh()->kg_per_m)->toBe(25.1)
        ->and($theirs->fresh()->exists)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Creating
|--------------------------------------------------------------------------
*/

it('would be a disaster if the nesting algorithm and unit could be typed wrong', function () {
    /**
     * Both follow from the product category. A PLATE saved as METERAGE would nest as a bar while
     * looking perfectly ordinary on screen, so neither is accepted from the form at all.
     */
    adminActingAs($this);

    $this->post(route('admin.materials.store'), materialPayload([
        'product_category' => 'PLATE',
        'nominal_height' => '10',
        'nominal_width' => '1200',
        'nominal_length' => '2400',
        //Both offered by a caller that thinks it knows better
        'nesting_algo' => 'METERAGE',
        'nominal_units' => 'FEET',
    ]))->assertRedirect();

    $product = Product::sole();

    expect($product->nesting_algo)->toBe('AREA')
        ->and($product->nominal_units)->toBe('MILLIMETERS');
});

it('would be a disaster if a product could be created that no cut list line can match', function () {
    /**
     * A PFC with no nominal height is matched by no piece spec ever built. It offers no stock length
     * to any nest and contributes no mass per metre, while sitting in the catalogue looking complete.
     */
    adminActingAs($this);

    $this->post(route('admin.materials.store'), materialPayload(['nominal_height' => null]))
        ->assertSessionHasErrors('nominal_height');

    expect(Product::count())->toBe(0);
});

it('would be a disaster if two products nesting cannot tell apart both got in', function () {
    /**
     * They both match every piece of that spec, so getPurchasableVariations returns each of their
     * stock lengths and resolveKgPerM silently takes the heavier of their two weights.
     */
    adminActingAs($this);

    $this->post(route('admin.materials.store'), materialPayload())->assertRedirect();

    //Same spec, different weight and description - still the same product as far as nesting can tell
    $this->post(route('admin.materials.store'), materialPayload([
        'description' => 'Two hundred PFC',
        'kg_per_m' => 30.0,
    ]))->assertSessionHasErrors('product_category');

    expect(Product::count())->toBe(1);
});

it('would be a disaster if a bolt could not be offered in a second pack size', function () {
    /**
     * getPurchasableVariations for BUNDLE gathers pack sizes across every matching product on
     * purpose. The catalogue relies on it: "M12x30 GR8.8 ZINC" in packs of 25 and the same bolt
     * "Assembly" in packs of 200 share a spec key by design, so the duplicate guard has to count
     * pack sizes as part a bundled product's identity.
     */
    adminActingAs($this);

    $bolt = [
        'product_category' => 'HEX_BOLT',
        'material' => 'PLAIN_CARBON_STEEL',
        'grade' => 'GR_8_8',
        'surface' => 'ZINC',
        'nominal_length' => '30',
        'nominal_width' => '12',
        'nominal_height' => null,
        'kg_per_m' => null,
    ];

    $this->post(route('admin.materials.store'), materialPayload([
        ...$bolt,
        'description' => 'M12x30 GR8.8 ZINC',
        'pack_size_1' => 25,
    ]))->assertSessionHasNoErrors();

    $this->post(route('admin.materials.store'), materialPayload([
        ...$bolt,
        'description' => 'M12x30 GR8.8 ZINC Assembly',
        'pack_size_1' => 200,
    ]))->assertSessionHasNoErrors();

    expect(Product::count())->toBe(2);

    //But the same bolt in the same pack is still a duplicate
    $this->post(route('admin.materials.store'), materialPayload([
        ...$bolt,
        'description' => 'M12x30 again',
        'pack_size_1' => 25,
    ]))->assertSessionHasErrors('product_category');

    expect(Product::count())->toBe(2);
});

it('would be a disaster if a grade sitting in a material column could be saved again', function () {
    // One inherited row holds "SS316" - a GRADE - in its material column, so its label is built wrong
    adminActingAs($this);

    $this->post(route('admin.materials.store'), materialPayload(['material' => 'SS316']))
        ->assertSessionHasErrors('material');

    expect(Product::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Editing something that is in use
|--------------------------------------------------------------------------
*/

it('would be a disaster if editing a spec column detached the cut list items matched on it', function () {
    $admin = adminActingAs($this);
    $product = platformProduct();
    $project = createProject($admin);
    pieceMatching($product, $project);

    // The grade is how that piece finds this product. Renaming it here corrects nothing.
    $this->patch(route('admin.materials.update', $product), materialPayload(['grade' => 'GR350']))
        ->assertSessionHasErrors('grade');

    expect($product->fresh()->grade)->toBe('GR300');
});

it('would be a disaster if a nested bar or an offcut did not lock the spec too', function () {
    /**
     * A bar records what was bought and an offcut is physical steel on a rack with a mark painted on
     * it. Both find their product by spec, and neither table has a foreign key.
     */
    adminActingAs($this);

    $barProduct = platformProduct();
    Bar::create([
        'product_category' => $barProduct->product_category,
        'material' => $barProduct->material,
        'grade' => $barProduct->grade,
        'surface' => $barProduct->surface,
        'nominal_length' => $barProduct->nominal_length,
        'nominal_height' => $barProduct->nominal_height,
        'product_derived_label' => '200PFC',
        'length' => 9000,
    ]);

    $this->patch(route('admin.materials.update', $barProduct), materialPayload(['nominal_height' => '250']))
        ->assertSessionHasErrors('nominal_height');

    $offcutProduct = platformProduct(['nominal_height' => '150', 'description' => '150PFC 9m']);
    Offcut::create([
        'batch_from_id' => 1,
        'product_category' => $offcutProduct->product_category,
        'material' => $offcutProduct->material,
        'grade' => $offcutProduct->grade,
        'surface' => $offcutProduct->surface,
        'nominal_length' => $offcutProduct->nominal_length,
        'nominal_height' => $offcutProduct->nominal_height,
        'length' => 2500,
        'unique_mark' => 'AAA',
    ]);

    $this->patch(route('admin.materials.update', $offcutProduct), materialPayload([
        'nominal_height' => '150',
        'surface' => 'GALVANISED',
    ]))->assertSessionHasErrors('surface');

    expect($barProduct->fresh()->nominal_height)->toBe('200')
        ->and($offcutProduct->fresh()->surface)->toBe('NONE');
});

it('would be a disaster if a corrected weight could not be saved on a product in use', function () {
    /**
     * The point of locking the spec is that everything else stays editable. A wrong mass per metre
     * makes every nest of that section cost wrongly, and it is not part of how anything finds the
     * product - so it has to be correctable at any time.
     */
    $admin = adminActingAs($this);
    $product = platformProduct(['kg_per_m' => 1.0]);
    $project = createProject($admin);
    pieceMatching($product, $project);

    $this->patch(route('admin.materials.update', $product), materialPayload([
        'kg_per_m' => 25.1,
        'pack_size_1' => 4,
        'certificates' => false,
        'description' => 'Corrected description',
        'baseline_supplier' => 'https://example.test/new.pdf',
    ]))->assertSessionHasNoErrors();

    $product = $product->fresh();

    expect($product->kg_per_m)->toBe(25.1)
        ->and($product->pack_size_1)->toBe('4')
        ->and($product->certificates)->toBeFalse()
        ->and($product->description)->toBe('Corrected description');
});

it('would be a disaster if an unused product could not have its spec corrected', function () {
    // Nothing is built on it, so there is nothing to detach - the lock must not be a blanket ban
    adminActingAs($this);
    $product = platformProduct();

    $this->patch(route('admin.materials.update', $product), materialPayload(['grade' => 'GR350']))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->grade)->toBe('GR350');
});

it('would be a disaster if deprecating a product in use were treated as a spec change', function () {
    /**
     * Deprecating is the honest way to retire a product with history: it stops being offered for new
     * work and everything already built on it still resolves. It must never be caught by the lock.
     */
    $admin = adminActingAs($this);
    $product = platformProduct();
    $project = createProject($admin);
    pieceMatching($product, $project);

    $this->patch(route('admin.materials.update', $product), materialPayload(['deprecated' => true]))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->deprecated)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Deleting
|--------------------------------------------------------------------------
*/

it('would be a disaster if deleting a product left cut list items matching nothing', function () {
    /**
     * No foreign key anywhere refuses this. pieces, bars and offcuts all carry the spec and not the
     * id, so the delete succeeds at the database level and the work is simply orphaned.
     */
    $admin = adminActingAs($this);
    $product = platformProduct();
    $project = createProject($admin);
    pieceMatching($product, $project);

    $this->delete(route('admin.materials.destroy', $product))->assertRedirect();

    expect(Product::find($product->id))->not->toBeNull()
        ->and(session('materials')['ok'])->toBeFalse()
        ->and(session('materials')['message'])->toContain('cut list item');
});

it('would be a disaster if a mistyped product could never be removed', function () {
    // Nothing refers to it at all, so deleting it loses nothing and tidying up has to be possible
    adminActingAs($this);
    $product = platformProduct(['description' => 'Typo']);

    $this->delete(route('admin.materials.destroy', $product))->assertRedirect();

    expect(Product::find($product->id))->toBeNull()
        ->and(session('materials')['ok'])->toBeTrue();
});

it('would be a disaster if a quoted or ordered product could be deleted', function () {
    $admin = adminActingAs($this);
    $product = platformProduct();

    $batch = Batch::factory()->create(['user_id' => $admin->id]);
    [$quote] = quoteAndOrder($admin, $batch);
    $product->quotes()->attach($quote->id, ['quantity' => 3]);

    $this->delete(route('admin.materials.destroy', $product))->assertRedirect();

    expect(Product::find($product->id))->not->toBeNull()
        ->and(session('materials')['message'])->toContain('quote');
});

/*
|--------------------------------------------------------------------------
| Usage
|--------------------------------------------------------------------------
*/

it('would be a disaster if usage were reported per-row with a query each', function () {
    /**
     * The page shows usage for 50 products. Three questions per row against tables with no
     * product_id would be 150 queries; ProductUsage groups the spec tables per category instead.
     */
    $admin = adminActingAs($this);
    $project = createProject($admin);

    $products = collect(range(1, 8))->map(fn (int $i) => platformProduct([
        'nominal_height' => (string) (100 + $i * 10),
        'description' => 'PFC '.$i,
    ]));

    pieceMatching($products->first(), $project);

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $usage = (new ProductUsage)->forMany($products);

    //One category on the page: three grouped spec queries plus one withCount
    expect($queries)->toBeLessThanOrEqual(6)
        ->and($usage[$products->first()->id]['counts']['pieces'])->toBe(1)
        ->and($usage[$products->first()->id]['specLocked'])->toBeTrue()
        ->and($usage[$products->last()->id]['specLocked'])->toBeFalse()
        ->and($usage[$products->last()->id]['deletable'])->toBeTrue();
});

it('would be a disaster if a wall thickness stored as a float missed a piece storing it as text', function () {
    /**
     * pieces.wall is a varchar and products.wall is a float, so "2.0" and 2.0 are the same wall.
     * Comparing them as text would report the product as unused and let its spec be edited out from
     * under the bars already cut to it.
     */
    $admin = adminActingAs($this);
    $project = createProject($admin);

    $product = platformProduct([
        'product_category' => 'RHS',
        'description' => '75x50x2.0 RHS',
        'nominal_height' => '75',
        'nominal_width' => '50',
        'wall' => 2.0,
    ]);

    $rawMaterialQuote = createRawMaterialQuote200Pfc(
        $project,
        MaterialEnums::PLAIN_CARBON_STEEL,
        GradeEnums::GR300,
        9000,
    );

    Piece::create([
        'project_id' => $project->id,
        'raw_material_quote_id' => $rawMaterialQuote->id,
        'product_category' => 'RHS',
        'material' => $product->material,
        'grade' => $product->grade,
        'surface' => $product->surface,
        'nominal_units' => $product->nominal_units,
        'nominal_length' => $product->nominal_length,
        'nominal_width' => '50',
        'nominal_height' => '75',
        //The text form of the same thickness
        'wall' => '2.0',
        'actual_length' => 3000,
        'actual_qty' => 1,
    ]);

    expect((new ProductUsage)->for($product)['counts']['pieces'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The screen
|--------------------------------------------------------------------------
*/

it('would be a disaster if the screen hid a value it will not let you save', function () {
    /**
     * An unflagged row looks correct. The one inherited product with a grade in its material column
     * builds the wrong label, and nothing would ever say so.
     */
    adminActingAs($this);
    platformProduct(['material' => 'SS316', 'description' => 'Odd one']);

    $this->get(route('admin.materials.index'))
        ->assertInertia(fn ($page) => $page
            ->component('AdminMaterialsIndex')
            ->where('products.data.0.invalidValues', ['material']));
});

it('would be a disaster if an empty catalogue looked like a working one', function () {
    //A BOM import against an empty products table extracts nothing and says nothing about why
    adminActingAs($this);

    $this->get(route('admin.materials.index'))
        ->assertInertia(fn ($page) => $page
            ->where('totals.active', 0)
            ->where('totals.deprecated', 0));
});

it('would be a disaster if deprecated products were mixed into the working catalogue', function () {
    adminActingAs($this);
    platformProduct(['description' => 'Current']);
    platformProduct(['description' => 'Retired', 'nominal_height' => '150', 'deprecated' => true]);

    $this->get(route('admin.materials.index'))
        ->assertInertia(fn ($page) => $page
            ->where('totals.active', 1)
            ->where('totals.deprecated', 1)
            ->count('products.data', 1)
            ->where('products.data.0.description', 'Current'));

    $this->get(route('admin.materials.index', ['status' => 'deprecated']))
        ->assertInertia(fn ($page) => $page
            ->count('products.data', 1)
            ->where('products.data.0.description', 'Retired'));
});
