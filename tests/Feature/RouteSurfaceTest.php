<?php

use App\Models\Batch;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * What the application answers to, and what it deliberately does not.
 *
 * Written off a route audit: every named route cross-referenced against every test, which turned up
 * fourteen registered verbs whose controller method had no body. An unimplemented action does not
 * 404 - it runs, returns nothing, and Laravel answers 200 with an empty body. Two of them had a
 * Gate call in front of that nothing, so they authorised the caller first and then still did
 * nothing, which is the shape of an endpoint that works.
 *
 * These are pinned rather than left to the route file, because the way they come back is by someone
 * writing Route::resource without ->only(), which is exactly how they arrived.
 */
it('would be a disaster if an unimplemented verb answered 200 with an empty body', function () {
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    $quote = Quote::create([
        'batch_id' => $batch->id,
        'user_id' => $user->id,
        'supplier_category' => 'STEEL_MERCHANT',
        'quote_sent' => false,
    ]);

    $gone = [
        ['get', '/quotes'],
        ['get', '/quotes/create'],
        ['get', "/quotes/{$quote->id}"],
        ['get', "/quotes/{$quote->id}/edit"],
        //The one that mattered: authorised the caller, deleted nothing, answered 200
        ['delete', "/quotes/{$quote->id}"],
        ['get', '/orders'],
        ['get', '/orders/create'],
        ['get', '/orders/1'],
        ['get', '/orders/1/edit'],
        ['get', '/batches'],
        ['get', '/batches/create'],
        ['get', "/batches/{$batch->id}"],
        ['get', "/batches/{$batch->id}/edit"],
        //Authorised the caller and then wrote nothing, so a save read as saved
        ['put', "/batches/{$batch->id}"],
        //Deleted a material row with a bare ->delete(), so any row with a piece was a 500
        ['delete', '/raw-material-quote/1'],
    ];

    /*
     * 404 or 405. Where the URI survives for another verb - PUT /quotes/{quote} is still the real
     * update - Laravel answers "method not allowed" rather than "not found", and both mean the same
     * thing here: nothing handles it. What is being pinned is that neither answers 2xx.
     */
    foreach ($gone as [$method, $uri]) {
        $status = $this->actingAs($user)->{$method}($uri)->getStatusCode();

        expect($status)->toBeIn([404, 405], strtoupper($method)." {$uri} answered {$status}");
    }

    //And the quote is still there, which is what the DELETE above was answering 200 about
    expect(Quote::find($quote->id))->not->toBeNull();
});

it('still answers on the verbs that are implemented', function () {
    //Narrowing a resource is easy to overshoot, so the survivors are named too
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($user->id)->create();
    pieceOnBatch(createProject($user), $batch);

    //Deliberately a 404 from the controller - batches are created by QuoteController::store
    $this->actingAs($user)->post(route('batches.store'), [])->assertNotFound();

    //The unwind, which is the one batch verb that does anything
    $this->actingAs($user)->delete(route('batches.destroy', $batch))->assertRedirect();
});

it('serves the pages a signed-out visitor is given', function () {
    /*
     * Found by the route audit - neither had a test, and both render a full page for somebody who
     * has never logged in. A 500 on either is the first thing a prospective customer sees.
     */
    $this->get(route('guest.onboarding'))->assertOk();
    $this->get(route('try.nesting'))->assertOk();
});
