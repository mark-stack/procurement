<?php

use App\Models\Batch;
use App\Models\Quote;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it("would be a disaster if the one click email material list generator didn't work correctly", function () {});

it('would be a disaster if quote deadline missed', function () {});

it('would be a disaster if duplicate quotes were possible', function () {
    /*
     * The quote/order management page provisions a quote per supplier the first time it renders. That
     * was a lookup followed by a create, so two concurrent loads could both miss and both insert.
     * The unique index is what makes firstOrCreate actually safe.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $batch = Batch::factory()->forUser($user->id)->create();
    $supplier = Supplier::factory()->create();

    $attributes = [
        'batch_id' => $batch->id,
        'supplier_id' => $supplier->id,
        'supplier_category' => 'STEEL_MERCHANT',
    ];

    $first = Quote::firstOrCreate($attributes, ['user_id' => $user->id, 'quote_sent' => false]);

    //The second render reads the first one back rather than minting another
    $second = Quote::firstOrCreate($attributes, ['user_id' => $user->id, 'quote_sent' => false]);

    expect($second->id)->toBe($first->id);
    expect(Quote::count())->toBe(1);

    //And the database refuses a duplicate outright, whoever writes it
    expect(fn () => Quote::create($attributes + ['user_id' => $user->id, 'quote_sent' => false]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('would be a disaster if duplicate email notifications were happening', function () {});

it('would be a disaster if not sending quote request to all available suppliers', function () {});

it('would be a disaster if quote follow up email not working', function () {});

//todo more
