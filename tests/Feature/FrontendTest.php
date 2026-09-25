<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('would be a disaster if landing page had errors', function () {
    $response = $this->get('/');
    $response->assertStatus(200); // Verify the page returns an HTTP 200 status.
});

it('would be a disaster if a user without a business broke every page', function () {
    /**
     * HandleInertiaRequests shares these props on every response, so an
     * unguarded ->business there is a 500 on the whole app, not one page.
     * Registration always assigns a business, but a hand-created user
     * (tinker, manual SQL) has none.
     */
    $user = \App\Models\User::factory()->create(['business_id' => null]);

    expect($user->business)->toBeNull();

    $this->actingAs($user)->get('/profile')->assertStatus(200);
});

//todo more
