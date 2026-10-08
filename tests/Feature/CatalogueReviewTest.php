<?php

use App\Models\CatalogueReview;
use App\Models\Product;
use App\Models\Scrap;
use App\Services\CatalogueTrust;
use App\Services\ScrapReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * The master catalogue as a measuring instrument, and whether anybody has checked it.
 *
 * products.kg_per_m is what every tonne price, offcut valuation and scrap write-off in this
 * application is derived through. A row without one does not fail - the cost model falls back to
 * the business default, a flat 10.0 kg/m - so a product that cannot be measured has always looked
 * exactly like one that can. These tests are about making that difference sayable: which rows
 * cannot be relied on, who last looked, and where a figure that rests on the default admits it.
 */
function catalogueAdmin()
{
    $admin = createUser(1, createBusiness('admin'), true, true);
    test()->actingAs($admin);

    return $admin;
}

/**
 * One platform product, straight off the factory, with whatever this test needs wrong with it.
 */
function catalogueProduct(array $overrides = []): Product
{
    return Product::factory()->create($overrides);
}

/**
 * A complete edit-form body, matching materialPayload() in AdminMaterialsTest.
 *
 * Restated here rather than shared: every editable column except accepted_reason is
 * 'present'-validated, so a partial body is rejected for what it left out - which makes the exact
 * shape of a whole body the thing these two tests are each about, and a helper quietly growing a
 * column in one file would change what the other was asserting.
 *
 * @return array<string, mixed>
 */
function catalogueFormBody(array $overrides = []): array
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

it('flags a section with no mass per metre, because its figures will be guessed at', function () {
    catalogueProduct(['kg_per_m' => null]);

    $reasons = (new CatalogueTrust)->reasonsFor(Product::first());

    expect($reasons)->toContain(CatalogueTrust::NO_MASS);
});

it('does not flag a fastener with no mass per metre, because nothing ever weighs one', function () {
    /*
     * 461 of the catalogue's 492 massless rows are bolts, nuts and studs. A BUNDLE product is nested
     * by counting packs and is never handed to the cost model at all, so a bolt with no kg/m is not
     * an unmeasured resource - it is one measured in a different unit. Flagging all of them would
     * have buried the thirty sections that matter.
     */
    catalogueProduct([
        'product_category' => 'HEX_BOLT',
        'nesting_algo' => 'BUNDLE',
        'kg_per_m' => null,
    ]);

    $reasons = (new CatalogueTrust)->reasonsFor(Product::first());

    expect($reasons)->not->toContain(CatalogueTrust::NO_MASS);
});

it('tells an unanswered certificates flag apart from a no', function () {
    $unanswered = catalogueProduct(['certificates' => null]);
    $answeredNo = catalogueProduct(['certificates' => false, 'nominal_height' => '250']);

    $trust = new CatalogueTrust;

    /*
     * Null is the honest state for a row nobody has answered - but where('certificates', true) and
     * where('certificates', false) both exclude it, so such a product is asked for no certificate
     * and reported as missing none. "No" is an answer; null is a gap nothing else can see.
     */
    expect($trust->reasonsFor($unanswered))->toContain(CatalogueTrust::CERTIFICATES_UNANSWERED)
        ->and($trust->reasonsFor($answeredNo))->not->toContain(CatalogueTrust::CERTIFICATES_UNANSWERED);
});

it('flags a spec column holding the wrong kind of value', function () {
    //A grade sitting in a material column, which is how the catalogue's only stainless bolt arrived
    $wrongKind = catalogueProduct(['material' => 'SS316']);

    //A backtick typed for a 1, which matched no piece spec and made the row unpurchasable
    $notANumber = catalogueProduct(['nominal_length' => '`50', 'nominal_height' => '250']);

    $trust = new CatalogueTrust;

    expect($trust->reasonsFor($wrongKind))->toContain(CatalogueTrust::INVALID_VALUE)
        ->and($trust->invalidValues($wrongKind))->toBe(['material'])
        ->and($trust->reasonsFor($notANumber))->toContain(CatalogueTrust::INVALID_VALUE)
        ->and($trust->invalidValues($notANumber))->toBe(['nominal_length']);
});

it('flags a row with no description, which nests fine and cannot be found', function () {
    //The three 12m RHS rows the spreadsheet left blank held a bare space, not a null
    $blank = catalogueProduct(['description' => ' ']);

    expect((new CatalogueTrust)->reasonsFor($blank))->toContain(CatalogueTrust::NO_DESCRIPTION);
});

it('leaves deprecated products out of the report entirely', function () {
    catalogueProduct(['kg_per_m' => null, 'deprecated' => true]);

    $report = (new CatalogueTrust)->report();

    /*
     * A deprecated product is excluded from availableForBusiness(), so no nest resolves a mass from
     * one and no BOM line classifies into one - its blanks cost nothing. Counting them would bury
     * the rows that matter under a list of retired ones.
     */
    expect($report['products'])->toBe(0)
        ->and($report['outstanding'])->toBe(0);
});

it('counts rows rather than findings, so one bad row is one job', function () {
    //No mass AND no description: two reasons, one product to go and look at
    catalogueProduct(['kg_per_m' => null, 'description' => '']);

    $report = (new CatalogueTrust)->report();

    expect($report['outstanding'])->toBe(1);

    $byReason = collect($report['reasons'])->keyBy('reason');

    expect($byReason[CatalogueTrust::NO_MASS]['outstanding'])->toBe(1)
        ->and($byReason[CatalogueTrust::NO_DESCRIPTION]['outstanding'])->toBe(1);
});

it('moves a row to accepted once somebody records why it is being kept', function () {
    $product = catalogueProduct(['kg_per_m' => null]);

    expect((new CatalogueTrust)->report()['outstanding'])->toBe(1);

    $product->update(['accepted_reason' => 'Sold by the sheet, and nobody quotes it by mass per metre.']);

    $report = (new CatalogueTrust)->report();

    /*
     * Still on the list, in a different column. A report that quietly dropped what somebody decided
     * to live with could never say what the decision was - and one that could never reach zero is a
     * report nobody reads.
     */
    expect($report['outstanding'])->toBe(0)
        ->and($report['accepted'])->toBe(1)
        ->and(collect($report['reasons'])->firstWhere('reason', CatalogueTrust::NO_MASS)['accepted'])->toBe(1);
});

it('treats a blank acceptance as no acceptance at all', function () {
    //A textarea emptied back out sends '', and '' is not a reason
    catalogueProduct(['kg_per_m' => null, 'accepted_reason' => '  ']);

    expect((new CatalogueTrust)->report()['outstanding'])->toBe(1);
});

it('records who reviewed the catalogue, when, and what was still wrong with it', function () {
    $admin = catalogueAdmin();

    catalogueProduct(['kg_per_m' => null]);
    catalogueProduct(['certificates' => null, 'nominal_height' => '250']);

    test()->post(route('admin.materials.reviewed'), [
        'note' => 'Checked the CHS masses against the supplier guide.',
    ])->assertRedirect();

    $review = CatalogueReview::sole();

    expect($review->user_id)->toBe($admin->id)
        ->and($review->reviewed_at)->not->toBeNull()
        ->and($review->note)->toBe('Checked the CHS masses against the supplier guide.')
        ->and($review->products_reviewed)->toBe(2)
        /*
         * Counted on the server off the same report the screen was showing, not typed in. An admin
         * recording "all good" over a catalogue with thirty unmeasured rows would be recording a
         * claim; this is a reading.
         */
        ->and($review->untrusted[CatalogueTrust::NO_MASS])->toBe(1)
        ->and($review->untrusted[CatalogueTrust::CERTIFICATES_UNANSWERED])->toBe(1)
        ->and($review->untrusted[CatalogueTrust::INVALID_VALUE])->toBe(0);
});

it('appends each review rather than overwriting the last one', function () {
    catalogueAdmin();
    catalogueProduct(['kg_per_m' => null]);

    test()->post(route('admin.materials.reviewed'), ['note' => null]);

    //The finding is dealt with between the two, which is the thing a series has to be able to show
    Product::first()->update(['kg_per_m' => 25.1]);

    test()->post(route('admin.materials.reviewed'), ['note' => null]);

    $reviews = CatalogueReview::query()->latestFirst()->get();

    /*
     * "Reviewed every quarter" is a claim about a sequence of dates. A single reviewed_at column
     * would answer "when was it last done" and destroy the previous answer every time it was done
     * again, which is the one thing a periodic review cannot afford.
     */
    expect($reviews)->toHaveCount(2)
        ->and($reviews->first()->untrusted[CatalogueTrust::NO_MASS])->toBe(0)
        ->and($reviews->last()->untrusted[CatalogueTrust::NO_MASS])->toBe(1);
});

it('records a review with no note at all, because looking is the record', function () {
    catalogueAdmin();

    test()->post(route('admin.materials.reviewed'))->assertRedirect();

    expect(CatalogueReview::sole()->note)->toBeNull();
});

it('lets nobody but an admin record a review', function () {
    $user = createUser(1, createBusiness('fabricator'), false, true);

    test()->actingAs($user)
        ->post(route('admin.materials.reviewed'))
        ->assertRedirect('/');

    expect(CatalogueReview::count())->toBe(0);
});

it('puts the findings and the review history on the catalogue screen', function () {
    catalogueAdmin();
    catalogueProduct(['kg_per_m' => null]);

    test()->get(route('admin.materials.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('AdminMaterialsIndex')
            ->where('trust.outstanding', 1)
            ->where('trust.products', 1)
            //Worded server side so the panel and the record a review writes cannot drift apart
            ->where('trust.reasons.0.reason', CatalogueTrust::NO_MASS)
            ->where('trust.reasons.0.label', 'No mass per metre')
            //Nobody has looked yet, which is a state the screen has to be able to say out loud
            ->where('reviews', [])
            ->where('products.data.0.trust', [CatalogueTrust::NO_MASS])
        );
});

it('says what each row is made of, and marks the rows that are not steel', function () {
    catalogueAdmin();

    catalogueProduct(['description' => 'A beam']);
    catalogueProduct([
        'description' => 'An aluminium angle',
        'product_category' => 'EA',
        'material' => 'ALUMINIUM',
        'nominal_height' => '50',
        'nominal_width' => '50',
    ]);

    //The page orders by category, so EA comes before PFC
    test()->get(route('admin.materials.index'))
        ->assertInertia(fn (Assert $page) => $page
            /*
             * Worded server side. The column itself is a join key spelled the way a database wants
             * it - PLAIN_CARBON_STEEL down 1,100 rows says nothing, because nearly all of them are.
             */
            ->where('products.data.1.material_label', 'Steel')
            ->where('products.data.1.is_steel', true)
            ->where('products.data.1.supplier_group', 'STEEL_MERCHANT')
            ->where('products.data.0.material_label', 'Aluminium')
            /*
             * The flag the screen actually marks a row on. A row that is not steel is costed
             * through a different mass, and potentially a different merchant's price per tonne -
             * which is the whole reason LVL was priced as steel for as long as the catalogue
             * carried it.
             *
             * This used to be an LVL bearer, whose supplier group was TIMBER_MERCHANT and so proved
             * both halves at once. Timber went on 2026-10-08 and no category maps to a non-steel
             * merchant any more, so the row is aluminium and the merchant is the steel one: the
             * material flag is what this test can still prove, and it is the half the screen marks.
             */
            ->where('products.data.0.is_steel', false)
            ->where('products.data.0.supplier_group', 'STEEL_MERCHANT')
        );
});

it('leaves a row whose material is not a real material unlabelled', function () {
    catalogueAdmin();

    //The inherited row that held a GRADE in its material column
    catalogueProduct(['material' => 'SS316']);

    test()->get(route('admin.materials.index'))
        ->assertInertia(fn (Assert $page) => $page
            /*
             * Null rather than the raw string dressed up as a label. The row is already flagged as
             * holding an invalid value, and inventing a tidy name for it would hide exactly what
             * the flag is for.
             */
            ->where('products.data.0.material_label', null)
            ->where('products.data.0.is_steel', null)
            ->where('products.data.0.invalidValues', ['material'])
        );
});

it('narrows the catalogue to the rows one finding is about', function () {
    catalogueAdmin();

    $massless = catalogueProduct(['kg_per_m' => null]);
    catalogueProduct(['nominal_height' => '250']);

    test()->get(route('admin.materials.index', ['trust' => CatalogueTrust::NO_MASS]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('AdminMaterialsIndex')
            ->has('products.data', 1)
            ->where('products.data.0.id', $massless->id)
        );
});

it('shows nothing rather than everything when a finding has no rows', function () {
    catalogueAdmin();
    catalogueProduct();

    /*
     * "Show me the rows with no mass" against a catalogue where every row has one is an empty list,
     * not the whole catalogue. An unfiltered query here would be the worst possible answer: it would
     * present 1,100 healthy rows as the problem.
     */
    test()->get(route('admin.materials.index', ['trust' => CatalogueTrust::NO_MASS]))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
});

it('clears an acceptance when the box is emptied, and leaves it alone when nothing mentions it', function () {
    catalogueAdmin();

    $product = catalogueProduct([
        'kg_per_m' => null,
        'accepted_reason' => 'Nobody quotes this by mass.',
    ]);

    /*
     * The deprecate button posts the whole form MINUS this column - see formValues() on the index
     * page. A body that does not mention a decision is not making one, which is why the rule is
     * 'nullable' rather than the 'present' every other editable column carries.
     */
    test()->patch(route('admin.materials.update', $product), catalogueFormBody([
        'kg_per_m' => null,
        'deprecated' => true,
    ]));

    expect($product->fresh()->accepted_reason)->toBe('Nobody quotes this by mass.');

    //An emptied textarea sends '', which blanksToNull turns into the null that clears it
    test()->patch(route('admin.materials.update', $product), catalogueFormBody([
        'kg_per_m' => null,
        'deprecated' => true,
        'accepted_reason' => '',
    ]));

    expect($product->fresh()->accepted_reason)->toBeNull();
});

it('carries an acceptance through a catalogue export', function () {
    catalogueAdmin();
    catalogueProduct(['kg_per_m' => null, 'accepted_reason' => 'Nobody quotes this by mass.']);

    $exported = test()->get(route('admin.materials.export'))->json('products.0');

    /*
     * The JSON export/import is how local and production are kept in step. An acceptance recorded in
     * one and lost on the next sync would mean the same row came back onto the other's list with no
     * reason attached, and somebody would decide it a second time.
     */
    expect($exported['accepted_reason'])->toBe('Nobody quotes this by mass.');
});

it('says how much of a scrap total was worked out from an assumed mass', function () {
    [$business, , $batch] = nestedBatch([[7000, 2], [1700, 7]]);

    $rows = Scrap::query()->where('batch_id', $batch->id)->orderBy('length')->get();

    expect($rows)->toHaveCount(3);

    /*
     * One of the three drops costed off the business default instead of the section's real mass -
     * which is what happens to any product the catalogue cannot price. See
     * Services\NestingCostModel, where the fallback lives.
     */
    $assumed = $rows->first();
    $assumed->update(['kg_per_m_estimated' => true]);

    $totals = (new ScrapReport)->forBusiness($business)['totals'];

    expect($totals['estimated_weight_kg'])->toBe(round($assumed->weight_kg, 1))
        //A share of the headline, never a second count of it
        ->and($totals['estimated_weight_kg'])->toBeLessThan($totals['weight_kg'])
        ->and($totals['weight_kg'])->toBe(round($rows->sum('weight_kg'), 1));
});

it('reports nothing estimated when every section could be priced properly', function () {
    [$business] = nestedBatch([[7000, 2], [1700, 7]]);

    $totals = (new ScrapReport)->forBusiness($business)['totals'];

    //Zero rather than absent: "none of this was guessed" is an answer worth being able to give
    expect($totals['estimated_weight_kg'])->toBe(0.0)
        ->and($totals['weight_kg'])->toBeGreaterThan(0);
});
