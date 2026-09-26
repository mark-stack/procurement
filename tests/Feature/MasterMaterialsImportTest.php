<?php

use App\Jobs\AdminMaterialsImport;
use App\Models\Product;
use App\Notifications\AdminImportFinalised;
use App\Services\MasterMaterialsParser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

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

function adminActingAs($test)
{
    $business = createBusiness('gmail', true);
    $admin = createUser(1, $business, true, true);
    $test->actingAs($admin);

    return $admin;
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

it('would be a disaster if certificates were stored as text the app reads as a boolean', function () {
    $handle = materialsCsv([
        materialsRow([0 => 'Certified', 6 => 'TRUE']),
        materialsRow([0 => 'Uncertified', 1 => 'HEX_BOLT', 5 => 'BUNDLE', 6 => 'FALSE']),
    ]);

    $result = (new MasterMaterialsParser)->parse($handle);
    AdminMaterialsImport::dispatchSync($result->collection());

    expect(Product::where('description', 'Certified')->first()->certificates)->toBeTrue()
        ->and(Product::where('description', 'Uncertified')->first()->certificates)->toBeFalse()
        ->and(Product::where('certificates', true)->count())->toBe(1);
});

it('would be a disaster if correcting one field orphaned the product', function () {
    $original = (new MasterMaterialsParser)->parse(materialsCsv([materialsRow()]));
    AdminMaterialsImport::dispatchSync($original->collection());

    $id = Product::sole()->id;

    // The sheet is corrected: same product, new weight and pack size
    $corrected = (new MasterMaterialsParser)->parse(materialsCsv([materialsRow([18 => '25.9', 15 => '4'])]));
    AdminMaterialsImport::dispatchSync($corrected->collection());

    expect(Product::count())->toBe(1)
        ->and(Product::sole()->id)->toBe($id)
        ->and(Product::sole()->kg_per_m)->toBe(25.9)
        ->and(Product::sole()->pack_size_1)->toBe('4')
        ->and(Product::sole()->deprecated)->toBeFalse();
});

it('would be a disaster if a product dropped from the sheet stayed available', function () {
    $both = (new MasterMaterialsParser)->parse(materialsCsv([
        materialsRow([0 => 'Kept']),
        materialsRow([0 => 'Dropped', 12 => '150']),
    ]));
    AdminMaterialsImport::dispatchSync($both->collection());

    $one = (new MasterMaterialsParser)->parse(materialsCsv([materialsRow([0 => 'Kept'])]));
    AdminMaterialsImport::dispatchSync($one->collection());

    expect(Product::where('description', 'Kept')->first()->deprecated)->toBeFalse()
        ->and(Product::where('description', 'Dropped')->first()->deprecated)->toBeTrue()
        ->and(Product::query()->active()->count())->toBe(1);
});

it('would be a disaster if a product returning to the sheet stayed deprecated', function () {
    $sheet = (new MasterMaterialsParser)->parse(materialsCsv([materialsRow()]));

    AdminMaterialsImport::dispatchSync($sheet->collection());
    AdminMaterialsImport::dispatchSync(collect());
    expect(Product::sole()->deprecated)->toBeTrue();

    AdminMaterialsImport::dispatchSync($sheet->collection());

    expect(Product::count())->toBe(1)
        ->and(Product::sole()->deprecated)->toBeFalse();
});

it('would be a disaster if a failed import left the whole catalogue deprecated', function () {
    /**
     * The import used to deprecate every platform product up front and un-deprecate them row
     * by row. A failure part way through left nothing for the app to nest, quote or price.
     */
    $sheet = (new MasterMaterialsParser)->parse(materialsCsv([materialsRow()]));
    AdminMaterialsImport::dispatchSync($sheet->collection());

    $rows = $sheet->collection()->push(['no_such_column' => 'boom']);

    expect(fn () => AdminMaterialsImport::dispatchSync($rows))->toThrow(QueryException::class);

    expect(Product::count())->toBe(1)
        ->and(Product::sole()->deprecated)->toBeFalse()
        ->and(Product::query()->active()->count())->toBe(1);
});

it('would be a disaster if the spreadsheet supplier reference was thrown away', function () {
    $sheet = (new MasterMaterialsParser)->parse(materialsCsv([materialsRow()]));
    AdminMaterialsImport::dispatchSync($sheet->collection());

    expect(Product::sole()->baseline_supplier)->toBe('https://example.test/reference.pdf');
});

it('would be a disaster if a business product was deprecated by the platform import', function () {
    $business = createBusiness('gmail', true);

    $own = Product::create([
        'description' => 'Business only',
        'product_category' => 'PFC',
        'material' => 'PLAIN_CARBON_STEEL',
        'grade' => 'GR300',
        'surface' => 'NONE',
        'nesting_algo' => 'METERAGE',
        'business_id' => $business->id,
        'deprecated' => false,
    ]);

    AdminMaterialsImport::dispatchSync(collect());

    expect($own->fresh()->deprecated)->toBeFalse();
});

it('would be a disaster if the import could be triggered by following a link', function () {
    adminActingAs($this);

    // A GET rewrote the whole catalogue, so a prefetch or a stray click was enough
    $this->get(route('admin.update.master.materials.spreadsheet'))->assertStatus(405);
});

it('would be a disaster if a non-admin could rewrite the catalogue', function () {
    $business = createBusiness('gmail', true);
    $user = createUser(2, $business, false, true);

    $this->actingAs($user)
        ->post(route('admin.update.master.materials.spreadsheet'))
        ->assertRedirect('/');

    expect(Product::count())->toBe(0);
});

it('would be a disaster if the import reported success without saying what it did', function () {
    adminActingAs($this);

    $this->post(route('admin.update.master.materials.spreadsheet'))
        ->assertRedirect();

    $flash = session('materialsImport');

    expect($flash['ok'])->toBeTrue()
        ->and(implode(' ', $flash['messages']))->toContain('rows read from the spreadsheet')
        ->and(Product::count())->toBeGreaterThan(100);
});

/**
 * The tests above run the import inline because phpunit.xml sets QUEUE_CONNECTION=sync, while
 * production runs the database queue. These cover the queued path the rest of the file cannot.
 */
it('would be a disaster if the button did its work in the request instead of the queue', function () {
    adminActingAs($this);
    Queue::fake();

    $this->post(route('admin.update.master.materials.spreadsheet'))->assertRedirect();

    Queue::assertPushed(AdminMaterialsImport::class);

    // A reconcile of the whole catalogue must not be holding the admin's request open
    expect(Product::count())->toBe(0);
});

it('would be a disaster if a second click queued a second concurrent import', function () {
    adminActingAs($this);
    Queue::fake();

    $this->post(route('admin.update.master.materials.spreadsheet'))->assertRedirect();
    $this->post(route('admin.update.master.materials.spreadsheet'))->assertRedirect();

    // Two workers reconciling the same catalogue against different reads of it
    Queue::assertPushed(AdminMaterialsImport::class, 1);
});

it('would be a disaster if a blocked import was still reported as started', function () {
    adminActingAs($this);
    Queue::fake();

    $this->post(route('admin.update.master.materials.spreadsheet'));
    $this->post(route('admin.update.master.materials.spreadsheet'));

    $flash = session('materialsImport');

    expect($flash['ok'])->toBeFalse()
        ->and(implode(' ', $flash['messages']))->toContain('already queued or running');
});

it('would be a disaster if the import outlived the queue retry window unguarded', function () {
    /**
     * An untimed job that outran retry_after was handed to a second worker while the first was
     * still inside its transaction, so two imports reconciled the same catalogue at once.
     */
    $job = new AdminMaterialsImport(collect());

    expect($job->timeout)->toBeLessThan(config('queue.connections.database.retry_after'))
        ->and($job->tries)->toBe(1);
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

it('would be a disaster if two rows for one product overwrote each other silently', function () {
    $admin = adminActingAs($this);
    Notification::fake();

    $sheet = (new MasterMaterialsParser)->parse(materialsCsv([
        // Same natural key, different pack size: the second row wins and nobody was told
        materialsRow([15 => '1']),
        materialsRow([15 => '9']),
    ]));

    AdminMaterialsImport::dispatchSync($sheet->collection());

    expect(Product::count())->toBe(1)
        ->and(Product::sole()->pack_size_1)->toBe('9');

    Notification::assertSentTo($admin, AdminImportFinalised::class, function ($notification) {
        return str_contains(implode(' ', $notification->lines), '1 duplicate rows collapsed');
    });
});

it('would be a disaster if a clean import claimed duplicates it did not find', function () {
    $admin = adminActingAs($this);
    Notification::fake();

    $sheet = (new MasterMaterialsParser)->parse(materialsCsv([materialsRow()]));
    AdminMaterialsImport::dispatchSync($sheet->collection());

    Notification::assertSentTo($admin, AdminImportFinalised::class, function ($notification) {
        return ! str_contains(implode(' ', $notification->lines), 'duplicate rows collapsed');
    });
});

it('would be a disaster if an Excel byte order mark made the file look reordered', function () {
    // Excel writes a UTF-8 BOM before the first cell, and trim() does not remove it
    $header = MasterMaterialsParser::HEADER;
    $header[0] = "\xEF\xBB\xBF".$header[0];

    $result = (new MasterMaterialsParser)->parse(materialsCsv([materialsRow()], $header));

    expect($result->rows)->toHaveCount(1);
});
