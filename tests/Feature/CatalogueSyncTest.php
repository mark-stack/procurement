<?php

use App\Models\Product;
use Database\Seeders\Data\MasterMaterials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Reconciling a database to the committed catalogue, and writing the committed catalogue back.
 *
 * catalogue:sync is the only thing that writes the catalogue into a database that already holds
 * products - the seeder refuses that case, because re-seeding over an edited catalogue would
 * resurrect every row somebody had deprecated.
 *
 * The question these are really about is REMOVAL, because it is the only half that is not
 * symmetrical. Adding a product is adding a line. Taking one away is two different operations
 * depending on something the file cannot know: whether this database has work matched to it.
 */

/**
 * A platform product the committed file does not describe, so a replace-mode sync sees it as gone.
 */
function strandedProduct(array $overrides = []): Product
{
    return Product::factory()->create([
        'description' => 'A section no file mentions',
        'nominal_height' => '1234',
        ...$overrides,
    ]);
}

it('would be a disaster if a dry run wrote anything', function () {
    seedMasterMaterials();

    $stranded = strandedProduct();
    $before = Product::count();

    test()->artisan('catalogue:sync')->assertSuccessful();

    expect(Product::count())->toBe($before)
        ->and($stranded->fresh())->not->toBeNull()
        ->and($stranded->fresh()->deprecated)->toBeFalse();
});

it('deletes a product the file dropped that nothing was using', function () {
    /**
     * The whole point of asking ProductUsage rather than deprecating everything. A product nothing
     * refers to - by spec or by id - loses nothing by going, and keeping it forever as a deprecated
     * row is how a catalogue silts up.
     */
    seedMasterMaterials();

    $stranded = strandedProduct();

    test()->artisan('catalogue:sync --apply')->assertSuccessful();

    expect(Product::find($stranded->id))->toBeNull()
        ->and(Product::query()->platformCreated()->count())->toBe(count(MasterMaterials::rows()));
});

it('would be a disaster if a product something was using were deleted rather than deprecated', function () {
    /**
     * The asymmetry that matters. Nothing holds a foreign key to products for pieces, bars or
     * offcuts - they denormalise the spec and join on it field by field - so a delete here does not
     * fail, it silently leaves that work matching nothing.
     *
     * A keyword is used to make the product "in use" because it is the cheapest of the references
     * ProductUsage counts; the rule it proves is the same one that protects a piece.
     */
    seedMasterMaterials();

    $stranded = strandedProduct();

    DB::table('keywords')->insert([
        'keyword' => 'something refers to this',
        'type' => 'positive',
        'product_id' => $stranded->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    test()->artisan('catalogue:sync --apply')->assertSuccessful();

    expect(Product::find($stranded->id))->not->toBeNull()
        ->and($stranded->fresh()->deprecated)->toBeTrue();
});

it('would be a disaster if the same file deleted different products in different databases', function () {
    /**
     * Not a bug - the reason removal is decided here and never recorded in the file.
     *
     * Two databases, the same committed catalogue, the same absent product. One has work against it
     * and one does not, and they correctly disagree about what to do. A file that carried the
     * decision would take the laptop's answer to production, where the product is in use.
     */
    seedMasterMaterials();

    $used = strandedProduct(['description' => 'Used here', 'nominal_height' => '1234']);
    $unused = strandedProduct(['description' => 'Used nowhere', 'nominal_height' => '5678']);

    DB::table('keywords')->insert([
        'keyword' => 'in use',
        'type' => 'positive',
        'product_id' => $used->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    test()->artisan('catalogue:sync --apply')->assertSuccessful();

    expect(Product::find($unused->id))->toBeNull()
        ->and(Product::find($used->id)?->deprecated)->toBeTrue();
});

it('would be a disaster if a bad file could empty the catalogue in one run', function () {
    /**
     * A file that wants to remove hundreds of products is a bad file rather than an intention, and
     * "everything is deletable" is exactly what a mis-generated one looks like. The cap refuses the
     * whole run - nothing is deleted, and nothing is created either, because a plan is one fact.
     */
    seedMasterMaterials();

    for ($i = 0; $i < 4; $i++) {
        strandedProduct(['nominal_height' => (string) (9000 + $i)]);
    }

    $before = Product::count();

    test()->artisan('catalogue:sync --apply --max-deletes=3')->assertFailed();

    expect(Product::count())->toBe($before);

    /*
     * ...but a DRY run over the cap still succeeds, because it is about to do nothing. This runs
     * from a deploy script, and failing a plan nobody is applying would block shipping unrelated
     * code - on an atomic release, by never activating it. It warns instead.
     */
    test()->artisan('catalogue:sync --max-deletes=3')
        ->expectsOutputToContain('over the cap')
        ->assertSuccessful();

    expect(Product::count())->toBe($before);

    //...and says so with the cap raised
    test()->artisan('catalogue:sync --apply --max-deletes=4')->assertSuccessful();

    expect(Product::count())->toBe($before - 4);
});

it('leaves an already deprecated product alone rather than reporting it every deploy', function () {
    seedMasterMaterials();

    $stranded = strandedProduct(['deprecated' => true]);

    DB::table('keywords')->insert([
        'keyword' => 'in use',
        'type' => 'positive',
        'product_id' => $stranded->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    test()->artisan('catalogue:sync --apply')->assertSuccessful();

    //Still there, still deprecated, and not listed again
    expect($stranded->fresh()->deprecated)->toBeTrue();

    test()->artisan('catalogue:sync')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();
});

it('would be a disaster if syncing an untouched catalogue did anything at all', function () {
    /**
     * It runs on every deploy, so the ordinary case is that nothing has changed. An idempotent
     * no-op is what makes it safe to put in a deploy script at all.
     */
    seedMasterMaterials();

    $before = Product::query()->platformCreated()->get()->toArray();

    test()->artisan('catalogue:sync --apply')->assertSuccessful();

    expect(Product::query()->platformCreated()->get()->toArray())->toBe($before);
});

it('would be a disaster if the dump stopped round-tripping the file it writes', function () {
    /**
     * catalogue:dump is a rescue tool, not part of the loop - but it is only any use if what it
     * writes is what the seeder reads. Seed from the file, dump, and the file has to be unchanged;
     * anything else means the two disagree about how a row is spelled.
     */
    seedMasterMaterials();

    test()->artisan('catalogue:dump')
        ->expectsOutputToContain('No change')
        ->assertSuccessful();
});

it('refuses to overwrite the committed catalogue with an empty database', function () {
    //The one way a rescue tool becomes the thing you need rescuing from
    expect(Product::count())->toBe(0);

    test()->artisan('catalogue:dump --write')->assertFailed();

    expect(MasterMaterials::rows())->not->toBeEmpty();
});
