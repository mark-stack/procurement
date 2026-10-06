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

/*
 * The Google tag used to be gated on the URL containing "steelnesting", which let any
 * non-production host on that domain report into the same property. APP_ENV is the gate now.
 */
it('keeps the Google tag out of every environment but production', function () {
    $this->get('/')->assertDontSee('googletagmanager.com', false);
});

it('serves the Google tag in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/')
        ->assertStatus(200)
        ->assertSee('https://www.googletagmanager.com/gtag/js?id=G-J6FQY25TJY', false)
        ->assertSee("gtag('config', 'G-J6FQY25TJY')", false);
});

//todo more
