<?php

use App\Actions\Bar\CreateBarsAndOffcuts;
use App\Enums\NestingEnums;
use App\Formatters\UniqueLetterIDGenerator;
use App\Models\Bar;
use App\Models\Batch;
use App\Models\Offcut;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
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

/** Every three-letter code the generator itself can produce - its alphabet drops the confusable letters. */
function everyGeneratableMark(): array
{
    $codes = [];
    $combinations = strlen(UniqueLetterIDGenerator::ALPHABET) ** 3;

    for ($index = 0; $index < $combinations; $index++) {
        $codes[] = UniqueLetterIDGenerator::codeFromIndex($index, 3);
    }

    return $codes;
}

/** Write marks straight onto offcut rows, bypassing the generator. */
function stampMarks(array $codes, int $batchId, ?int $businessId, string $productCategory = 'PFC'): void
{
    $now = now();

    $rows = array_map(fn (string $code) => [
        'batch_from_id' => $batchId,
        'business_id' => $businessId,
        'product_category' => $productCategory,
        'material' => 'PLAIN CARBON STEEL',
        'grade' => 'GR300',
        'surface' => 'NONE',
        'length' => 1000,
        'unique_mark' => $code,
        'created_at' => $now,
        'updated_at' => $now,
    ], $codes);

    foreach (array_chunk($rows, 2000) as $chunk) {
        DB::table('offcuts')->insert($chunk);
    }
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

    stampMarks(everyThreeLetterMark(), $batch->id, $business->id);

    $mark = (new UniqueLetterIDGenerator)->generate('PFC', $business->id);

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

    $mark = (new UniqueLetterIDGenerator)->generate('PFC', $business->id);

    //Still the three-letter pool, because PFC has no marks of its own yet
    expect(strlen($mark))->toBe(3);
});

it('keeps counting marks per business', function () {
    /*
     * An offcut is only ever visible to the business that cut it, so there is no reason for one yard
     * filling its pool to push every other yard onto longer marks. The generator used to count every
     * offcut in the database.
     */
    $businessOne = createBusiness('biz1', true);
    $userOne = createUser(1, $businessOne, false, true);
    $batchOne = Batch::factory()->forUser($userOne->id)->create();

    $businessTwo = createBusiness('biz2', true);
    createUser(2, $businessTwo, false, true);

    //Business one has used up every three-letter mark it can be given
    stampMarks(everyGeneratableMark(), $batchOne->id, $businessOne->id);

    //So it grows to four letters, while the yard next door is still on three
    expect(strlen((new UniqueLetterIDGenerator)->generate('PFC', $businessOne->id)))->toBe(4)
        ->and(strlen((new UniqueLetterIDGenerator)->generate('PFC', $businessTwo->id)))->toBe(3);
});

it('only uses letters that survive being read off a bar', function () {
    /*
     * A mark is written on the steel by hand and read back by eye. "ZIQ" was three misreads in one
     * code - Z/2, I/1, Q/O - so the alphabet lost I, O, Q, S, Z and U.
     */
    $business = createBusiness('biz', true);
    $generator = new UniqueLetterIDGenerator;

    for ($i = 0; $i < 50; $i++) {
        expect($generator->generate('PFC', $business->id))
            ->toMatch('/^['.UniqueLetterIDGenerator::ALPHABET.']{3}$/');
    }
});

it('never stamps a blocked word on a customer\'s steel', function () {
    //Leave exactly one three-letter mark free and make it one that must never be handed out
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $batch = Batch::factory()->forUser($user->id)->create();

    $codes = array_values(array_diff(everyGeneratableMark(), ['FAG']));
    stampMarks($codes, $batch->id, $business->id);

    //Growing to four letters is the right answer here, "FAG" is not
    $mark = (new UniqueLetterIDGenerator)->generate('PFC', $business->id);

    expect($mark)->not->toBe('FAG')
        ->and(strlen($mark))->toBe(4);
});

it('does not hand the same mark out twice before the offcuts are written', function () {
    /*
     * generate() re-reads the offcuts table on every call, so a mark issued a moment ago is invisible
     * until its offcut is saved. One nest asks for many marks, and the in-memory reservation that was
     * meant to cover the gap was wiped by that re-read on the very next call.
     */
    $business = createBusiness('biz', true);
    $generator = new UniqueLetterIDGenerator;

    $marks = [];
    for ($i = 0; $i < 20; $i++) {
        $marks[] = $generator->generate('PFC', $business->id);
    }

    expect(array_unique($marks))->toHaveCount(20);
});

it('would be a disaster if two offcuts in one yard wore the same mark', function () {
    /*
     * Generation reads the taken marks and then inserts, and a nest runs inside a transaction, so a
     * nest running at the same moment is invisible to that read. Only the database can stop the two
     * rows that follow - and there was no constraint on the table at all.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $batch = Batch::factory()->forUser($user->id)->create();

    $offcut = create_offcut_200PFC(1000, $batch->id);

    expect(fn () => stampMarks([$offcut->unique_mark], $batch->id, $business->id))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('marks every bar of a consolidated count, not just the first', function () {
    /*
     * Identical bars are drawn as one row with a count, but each is cut for real and each drop becomes
     * its own offcut. Only the first mark was written into the nesting data, so on a "3 off" bar two
     * offcuts sat in inventory under marks that were never on any steel.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $batch = Batch::factory()->forUser($user->id)->create();

    $product = (object) [
        'product_category' => 'PFC',
        'material' => 'PLAIN CARBON STEEL',
        'grade' => 'GR300',
        'surface' => 'NONE',
        'nominal_height' => '200',
        'product_derived_label' => '200PFC',
        'nested' => [
            'utilisedBars' => [
                [
                    'count' => 3,
                    'result' => [
                        'bar_length' => 8000,
                        'unused' => 3000,
                        'scrap_threshold_mm' => 1000,
                        'pieces' => [],
                    ],
                ],
            ],
            'bestResultOffcuts' => ['utilisedOffcutBars' => []],
        ],
    ];

    CreateBarsAndOffcuts::run([NestingEnums::METERAGE->value => collect([$product])], $batch);

    $marks = Offcut::query()
        ->where('batch_from_id', $batch->id)
        ->orderBy('id')
        ->pluck('unique_mark')
        ->all();

    expect($marks)->toHaveCount(3)
        ->and(array_unique($marks))->toHaveCount(3);

    //And all three have to reach the drawing the yard works from
    $saved = $batch->fresh()->nested_state[NestingEnums::METERAGE->value][0];

    expect(data_get($saved, 'nested.utilisedBars.0.result.unique_marks'))->toBe($marks)
        ->and(Offcut::query()->where('batch_from_id', $batch->id)->pluck('business_id')->unique()->all())
        ->toBe([$business->id]);
});

it('refuses a mark that is not plain letters', function () {
    //$guarded is empty on the offcut, so nothing but this stops a request body writing the column
    expect(fn () => Offcut::factory()->make(['unique_mark' => 'a-1']))
        ->toThrow(InvalidArgumentException::class);
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

it('deletes the bars a batch cut when the batch is unwound', function () {
    /*
     * Same reasoning as the offcuts above - the cuts were never made. The bars table had no batch
     * column at all, so every unwind left one unreachable row per utilised bar behind forever.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $this->actingAs($user);

    $batch = Batch::factory()->forUser($user->id)->create();
    pieceOnBatch(createProject($user), $batch);

    $bar = Bar::create([
        'batch_id' => $batch->id,
        'product_category' => 'PFC',
        'material' => 'PLAIN CARBON STEEL',
        'grade' => 'GR300',
        'surface' => 'NONE',
        'nominal_height' => '200',
        'product_derived_label' => '200PFC',
        'length' => 9000,
    ]);

    $this->delete(route('batches.destroy', $batch))->assertRedirect();

    expect(Bar::find($bar->id))->toBeNull();
});

it('clears the piece/quote pivot when the batch is unwound', function () {
    //The quotes go, and the rows joining them to the pieces have to go with them
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $this->actingAs($user);

    $batch = Batch::factory()->forUser($user->id)->create();
    $piece = pieceOnBatch(createProject($user), $batch);
    [$quote] = quoteAndOrder($user, $batch);
    $piece->quotes()->attach($quote);

    $this->delete(route('batches.destroy', $batch))->assertRedirect();

    expect(DB::table('piece_quote')->where('piece_id', $piece->id)->count())->toBe(0)
        ->and(Quote::find($quote->id))->toBeNull();
});

it('would be a disaster if a failed unwind left the batch half destroyed', function () {
    /*
     * The unwind is eight destructive statements and they used to autocommit one at a time, so
     * anything throwing partway left a batch whose orders were gone but whose quotes remained - or
     * pieces detached from a batch row still sitting on the board. Neither state has a repair path.
     *
     * Failing on the very last statement is the worst case: everything before it has already run.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $this->actingAs($user);

    $batch = Batch::factory()->forUser($user->id)->create();
    $piece = pieceOnBatch(createProject($user), $batch);
    [$quote, $order] = quoteAndOrder($user, $batch);
    $produced = create_offcut_200PFC(1500, $batch->id);

    DB::listen(function ($query) {
        if (str_contains(strtolower($query->sql), 'delete from "batches"')) {
            throw new RuntimeException('database went away mid-unwind');
        }
    });

    $this->delete(route('batches.destroy', $batch))->assertStatus(500);

    expect(Batch::find($batch->id))->not->toBeNull()
        ->and(Quote::find($quote->id))->not->toBeNull()
        ->and(Order::find($order->id))->not->toBeNull()
        ->and(Offcut::find($produced->id))->not->toBeNull()
        ->and($piece->fresh()->batch_id)->toBe($batch->id);
});
