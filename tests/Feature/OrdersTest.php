<?php

use App\Models\Batch;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Supplier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('would be a disaster if materials for a project were imported after the order but included in the batch', function () {});

it('would be a disaster if missed order deadline', function () {});

it('would be a disaster if ordered without other staff approval', function () {});

it('would be a disaster if duplicate orders were possible', function () {
    /*
     * One order per quote - Order::firstOrCreate(['quote_id' => ...]) always assumed it, but nothing
     * enforced it, so two concurrent renders of the quote/order page could both insert.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $batch = Batch::factory()->forUser($user->id)->create();

    $quote = Quote::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_id' => Supplier::factory()->create()->id,
        'supplier_category' => 'STEEL_MERCHANT',
        'quote_sent' => false,
    ]);

    $attributes = ['quote_id' => $quote->id];
    $defaults = ['user_id' => $user->id, 'batch_id' => $batch->id, 'order_sent' => false];

    $first = Order::firstOrCreate($attributes, $defaults);
    $second = Order::firstOrCreate($attributes, $defaults);

    expect($second->id)->toBe($first->id);
    expect(Order::count())->toBe(1);

    expect(fn () => Order::create($attributes + $defaults))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('would be a disaster if duplicate email notifications were happening', function () {});

it('would be a disaster if order follow up email not working', function () {});

it('would be a disaster if actual received materials are different to cut list', function () {});

it('would be a disaster if order lead times were incorrect', function () {});

//todo more
