<?php

use App\Formatters\UniqueLetterIDGenerator;
use App\Models\Batch;
use App\Models\Offcut;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function everyThreeLetterMark(): array
{
    $letters = range('A', 'Z');
    $codes = [];

    foreach ($letters as $a) {
        foreach ($letters as $b) {
            foreach ($letters as $c) {
                $codes[] = $a.$b.$c;
            }
        }
    }

    return $codes;
}

it('never hands out a unique mark that is already stamped on an offcut', function () {
    /*
     * generate() read the used marks with pluck(), which is a LIST, then looked them up with
     * isset($usedCodes[$code]) - a lookup by key. That is never true for a three-letter code, so the
     * dedupe was dead: marks were handed out at random and the same mark could land on two offcuts.
     *
     * With every three-letter code taken, a working generator has to grow to four letters. The broken
     * one saw an empty dictionary and kept returning three.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $batch = Batch::factory()->forUser($user->id)->create();

    $now = now();
    $rows = array_map(fn (string $code) => [
        'batch_from_id' => $batch->id,
        'product_category' => 'PFC',
        'material' => 'PLAIN CARBON STEEL',
        'grade' => 'GR300',
        'surface' => 'NONE',
        'length' => 1000,
        'unique_mark' => $code,
        'created_at' => $now,
        'updated_at' => $now,
    ], everyThreeLetterMark());

    foreach (array_chunk($rows, 2000) as $chunk) {
        DB::table('offcuts')->insert($chunk);
    }

    $mark = (new UniqueLetterIDGenerator)->generate('PFC');

    expect(strlen($mark))->toBe(4)
        ->and(Offcut::query()->where('unique_mark', $mark)->exists())->toBeFalse();
});

it('keeps counting marks per product category', function () {
    //A mark taken by a UB does not stop a PFC using it - the pool is per category
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $batch = Batch::factory()->forUser($user->id)->create();

    $offcut = create_offcut_200PFC(1000, $batch->id);
    $offcut->product_category = 'UB';
    $offcut->unique_mark = 'AAA';
    $offcut->save();

    $mark = (new UniqueLetterIDGenerator)->generate('PFC');

    //Still the three-letter pool, because PFC has no marks of its own yet
    expect(strlen($mark))->toBe(3);
});

it('deletes the offcuts a batch produced when the batch is unwound', function () {
    /*
     * Unwinding a batch un-nests it, so the cuts were never made. The offcuts used to be left behind
     * pointing at a batch row that had just been deleted - invisible on the index, but still holding
     * their unique marks against the pool the next nest generates from.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $this->actingAs($user);

    $batch = Batch::factory()->forUser($user->id)->create();
    pieceOnBatch(createProject($user), $batch);
    $produced = create_offcut_200PFC(1500, $batch->id);

    $this->delete(route('batches.destroy', $batch))->assertRedirect();

    expect(Batch::find($batch->id))->toBeNull()
        ->and(Offcut::find($produced->id))->toBeNull();
});

it('still releases the offcuts a batch consumed when the batch is unwound', function () {
    //The source offcut goes back to inventory - it is only the batch's OWN offcuts that never existed
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $this->actingAs($user);

    $olderBatch = Batch::factory()->forUser($user->id)->create();
    $source = create_offcut_200PFC(3000, $olderBatch->id);

    $batch = Batch::factory()->forUser($user->id)->create();
    pieceOnBatch(createProject($user), $batch);
    $source->batch_to_id = $batch->id;
    $source->save();

    $this->delete(route('batches.destroy', $batch))->assertRedirect();

    expect(Offcut::find($source->id))->not->toBeNull()
        ->and(Offcut::find($source->id)->batch_to_id)->toBeNull();
});

it('refuses to unwind a batch whose offcuts a later batch has already nested into', function () {
    /*
     * Deleting those offcuts would strip the later batch of material it is relying on, and of the
     * certificate trail that runs back through them.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $this->actingAs($user);

    $batch = Batch::factory()->forUser($user->id)->create();
    pieceOnBatch(createProject($user), $batch);

    $produced = create_offcut_200PFC(1500, $batch->id);
    $produced->batch_to_id = Batch::factory()->forUser($user->id)->create()->id;
    $produced->save();

    $this->delete(route('batches.destroy', $batch))->assertForbidden();

    expect(Batch::find($batch->id))->not->toBeNull()
        ->and(Offcut::find($produced->id))->not->toBeNull();
});
