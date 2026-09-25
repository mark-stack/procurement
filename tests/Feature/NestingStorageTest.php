<?php

use App\Models\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * A worked example of the shape the nesting screens and checks() read back.
 */
function sampleNestedState(): array
{
    return [
        'METERAGE' => [
            (object) [
                'product_category' => 'PFC',
                'product_derived_label' => '200PFC',
                'algo' => 'METERAGE',
                'purchasableLengths' => [9000, 12000],
                'pieces' => [
                    ['project' => ['id' => 1, 'name' => 'a project'], 'length' => 2500, 'quantity' => 5],
                ],
                'nested' => [
                    'utilisedBars' => [
                        ['count' => 2, 'result' => ['bar_length' => 9000, 'unused' => 1500]],
                    ],
                    'totals' => [
                        'oldStock' => ['total' => 0, 'used' => 0, 'unused' => 0, 'reusable' => 0, 'scrap' => 0],
                        'newStock' => ['total' => 18000, 'used' => 15000, 'unused' => 3000, 'reusable' => 3000, 'scrap' => 0],
                    ],
                ],
            ],
        ],
    ];
}

it('would be a disaster if a saved nest did not survive a round trip', function () {
    $user = createUser(1, createBusiness('biz', true), false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    $batch->nested_state = sampleNestedState();
    $batch->save();

    //Stored as JSON, not as a PHP object graph
    $raw = DB::table('batches')->where('id', $batch->id)->value('nested_state');
    expect($raw)->toStartWith('{')
        ->and($raw)->not->toContain('O:8:"stdClass"');

    //Read back in the shape the readers expect: product as an object, its contents as arrays
    $product = $batch->fresh()->nested_state['METERAGE'][0];

    expect($product)->toBeObject()
        ->and($product->product_category)->toBe('PFC')
        ->and($product->purchasableLengths)->toBe([9000, 12000])
        ->and($product->nested['utilisedBars'][0]['result']['bar_length'])->toBe(9000)
        ->and($product->nested['totals']['newStock']['used'])->toBe(15000)
        ->and($product->pieces[0]['length'])->toBe(2500);
});

it('would be a disaster if a batch nested before the move to JSON became unreadable', function () {
    /**
     * Batches saved by earlier releases hold PHP-serialized stdClass graphs. Reading them has to keep
     * working, so a batch stays legible either side of the conversion migration.
     */
    $user = createUser(1, createBusiness('biz', true), false, true);
    $batch = Batch::factory()->forUser($user->id)->create();

    DB::table('batches')
        ->where('id', $batch->id)
        ->update(['nested_state' => serialize(sampleNestedState())]);

    $product = $batch->fresh()->nested_state['METERAGE'][0];

    expect($product)->toBeObject()
        ->and($product->product_category)->toBe('PFC')
        ->and($product->nested['utilisedBars'][0]['result']['unused'])->toBe(1500);
});

it('would be a disaster if the conversion migration lost a saved nest', function () {
    $user = createUser(1, createBusiness('biz', true), false, true);

    //A batch written the old way, and one never nested at all
    $legacy = Batch::factory()->forUser($user->id)->create();
    $empty = Batch::factory()->forUser($user->id)->create();

    DB::table('batches')
        ->where('id', $legacy->id)
        ->update(['nested_state' => serialize(sampleNestedState())]);

    $migration = require database_path('migrations/2026_09_25_120000_convert_batch_nested_state_to_json.php');
    $migration->up();

    $raw = DB::table('batches')->where('id', $legacy->id)->value('nested_state');
    expect($raw)->toStartWith('{');

    $product = $legacy->fresh()->nested_state['METERAGE'][0];
    expect($product->product_category)->toBe('PFC')
        ->and($product->nested['totals']['newStock']['reusable'])->toBe(3000);

    //A batch with no nesting is left alone
    expect(DB::table('batches')->where('id', $empty->id)->value('nested_state'))->toBeNull();

    //And running it again is a no-op rather than a double encode
    $migration->up();
    expect(DB::table('batches')->where('id', $legacy->id)->value('nested_state'))->toBe($raw);
});

it('would be a disaster if unreadable nesting data took a page down', function () {
    $user = createUser(1, createBusiness('biz', true), false, true);
    $batch = Batch::factory()->forUser($user->id)->create();

    DB::table('batches')
        ->where('id', $batch->id)
        ->update(['nested_state' => 'not a nest at all']);

    expect($batch->fresh()->nested_state)->toBe([]);

    $this->actingAs($user);
    $this->get(route('batch.nesting', [$batch->id, 'current', 0]))->assertStatus(200);
});
