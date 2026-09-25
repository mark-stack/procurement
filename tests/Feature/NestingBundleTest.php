<?php

use App\Formatters\NestingFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it("would be a disaster if bundle nesting for a single project didn't work correctly", function () {
    $result = (new NestingFormatter())->bundleAlgorithm(250, [100, 25]);

    //2x100 + 2x25 = 250, exactly the quantity asked for
    expect($result['boxes'])->toBe([100 => 2, 25 => 2])
        ->and($result['totalBought'])->toBe(250)
        ->and($result['efficiency'])->toEqual(100);
});

it('would be a disaster if a leftover quantity did not round up to one more of the smallest box', function () {
    $result = (new NestingFormatter())->bundleAlgorithm(230, [100, 25]);

    //2x100 + 1x25 covers 225, and the remaining 5 needs one more of the smallest box
    expect($result['boxes'])->toBe([100 => 2, 25 => 2])
        ->and($result['totalBought'])->toBe(250);
});

it('would be a disaster if blank pack sizes broke bundle nesting', function () {
    /**
     * Products carry pack_size_1..3 and the unused ones come back blank or null, so the box list
     * regularly arrives with holes in it. Filtering after sorting used to leave the key lookup for
     * "the smallest box" pointing at a key that no longer existed.
     */
    $result = (new NestingFormatter())->bundleAlgorithm(230, [100, 25, null, '']);

    expect($result['boxes'])->toBe([100 => 2, 25 => 2])
        ->and($result['totalBought'])->toBe(250);
});

it('would be a disaster if a zero pack size divided by zero', function () {
    $result = (new NestingFormatter())->bundleAlgorithm(250, [100, '0', 25]);

    expect($result['boxes'])->toBe([100 => 2, 25 => 2])
        ->and($result['totalBought'])->toBe(250);
});

it('would be a disaster if a product with no pack sizes crashed the nest', function () {
    //No purchasable pack size at all - report nothing rather than dividing by zero
    $result = (new NestingFormatter())->bundleAlgorithm(250, []);

    expect($result['boxes'])->toBe([])
        ->and($result['totalBought'])->toBe(0)
        ->and($result['efficiency'])->toBe(0);
});

//todo more
