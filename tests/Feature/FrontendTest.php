<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('would be a disaster if landing page had errors', function () {
    $response = $this->get('/');
    $response->assertStatus(200); // Verify the page returns an HTTP 200 status.
});

//todo more
