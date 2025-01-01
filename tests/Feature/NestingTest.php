<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createAdmin(): User
{
    //Admin business
    $adminBusiness = Business::create([
        "name" => "marko",
        "domain" => "marko.com",
        "admin_setup_complete" => true,
    ]);

    //Admin user
    return User::factory()->create([
        "name" => "Mark",
        "email" => env("ADMIN_EMAIL"),
        "business_id" => $adminBusiness->id,
    ]);
}

test('xxx', function () {

});

//todo more


