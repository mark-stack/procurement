<?php

use App\Models\Template;
use App\Services\CsvService;

function templatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Tekla Assembly List',
        //Must name a real entry in config/TableTemplates.php
        'source' => 'TEKLA',
        'config_label' => 'Assembly List',
        'first_description_cell' => 'B7',
        'first_material_cell' => 'C7',
        'first_length_required_cell' => 'D7',
        'first_width_required_cell' => null,
        'first_sub_qty_cell' => 'F7',
        'screenshot' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        'length_width_units' => 'mm',
        'active' => true,
    ], $overrides);
}

it('would be a disaster if editing a template silently deactivated it', function () {
    /**
     * initiateUpdate() copied every field except "active", so the form fell back
     * to its false default and every save turned an active template off. There was
     * no input for "active" either, so it could never be turned back on.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $template = Template::factory()->for($business)->create(['active' => true]);

    $this->actingAs($admin)->put(
        route('admin.businesses.templates.update', [$business->id, $template->id]),
        templatePayload(['name' => 'Renamed', 'active' => true]),
    )->assertRedirect();

    expect($template->fresh()->active)->toBeTrue()
        ->and($template->fresh()->name)->toBe('Renamed');
});

it('would be a disaster if a template from another business could be edited through this url', function () {
    /**
     * The nested resource was not scoped, so {template} resolved globally and
     * a mistyped business id edited a different customer's row.
     */
    $businessA = createBusiness('Business A', true);
    $businessB = createBusiness('Business B', true);
    $admin = createUser(1, $businessA, true, true);

    $theirs = Template::factory()->for($businessB)->create(['name' => 'Theirs']);

    $this->actingAs($admin)->put(
        route('admin.businesses.templates.update', [$businessA->id, $theirs->id]),
        templatePayload(['name' => 'Hijacked']),
    )->assertNotFound();

    expect($theirs->fresh()->name)->toBe('Theirs');
});

it('would be a disaster if a template from another business could be deleted through this url', function () {
    $businessA = createBusiness('Business A', true);
    $businessB = createBusiness('Business B', true);
    $admin = createUser(1, $businessA, true, true);

    $theirs = Template::factory()->for($businessB)->create();

    $this->actingAs($admin)->delete(
        route('admin.businesses.templates.destroy', [$businessA->id, $theirs->id]),
    )->assertNotFound();

    expect($theirs->fresh())->not->toBeNull();
});

it('would be a disaster if the index leaked another business\'s templates', function () {
    $businessA = createBusiness('Business A', true);
    $businessB = createBusiness('Business B', true);
    $admin = createUser(1, $businessA, true, true);

    Template::factory()->for($businessA)->create(['name' => 'Ours']);
    Template::factory()->for($businessB)->create(['name' => 'Theirs']);

    $this->actingAs($admin)
        ->get(route('admin.businesses.templates.index', $businessA->id))
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->has('templates', 1)
            ->where('templates.0.name', 'Ours')
        );
});

it('would be a disaster if a non-admin could reach the templates screen', function () {
    $business = createBusiness('Business A', true);
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('admin.businesses.templates.index', $business->id))
        ->assertRedirect('/');
});

it('stores a template against the business in the url', function () {
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(),
    )->assertRedirect();

    expect($business->templates()->count())->toBe(1)
        ->and($business->templates()->first()->active)->toBeTrue();
});

it('stores a template that is not active', function () {
    /**
     * "required" passes on a boolean false, but nothing pinned that: the rule pair
     * is "required|boolean", and a "required" that rejected false would have made
     * every new template active whether the box was ticked or not.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['active' => false]),
    )->assertSessionHasNoErrors();

    expect($business->templates()->first()->active)->toBeFalse();
});

it('rejects a cell reference that is not a cell reference', function () {
    /**
     * The old rule was "min:2|max:5", which accepted "zz" and "hello".
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['first_description_cell' => 'hello']),
    )->assertSessionHasErrors('first_description_cell');

    expect($business->templates()->count())->toBe(0);
});

it('rejects row zero, which is not a row', function () {
    /**
     * The row part of the rule was [0-9]{1,4}, so "B0" stored as a cell reference.
     * Spreadsheet rows start at 1.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['first_description_cell' => 'B0']),
    )->assertSessionHasErrors('first_description_cell');

    expect($business->templates()->count())->toBe(0);
});

it('stores a lower case cell reference in upper case', function () {
    /**
     * "b7" used to be stored verbatim, so the table mixed cases - the seeded row
     * said "b28" while the form's own placeholder says "B7".
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['first_description_cell' => 'b7', 'first_sub_qty_cell' => 'f7']),
    )->assertSessionHasNoErrors();

    $template = $business->templates()->first();

    expect($template->first_description_cell)->toBe('B7')
        ->and($template->first_sub_qty_cell)->toBe('F7');
});

it('stores a blank optional cell as null', function () {
    /**
     * The optional cell rules are "nullable", which skips null and not ''. A blank
     * input only reaches them as null because of Laravel's global
     * ConvertEmptyStringsToNull - pinned here, because without it every existing row
     * with a blank material cell would fail its own regex on the next save.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['first_material_cell' => '']),
    )->assertSessionHasNoErrors();

    expect($business->templates()->first()->first_material_cell)->toBeNull();
});

it('rejects a screenshot that is not a base64 image', function () {
    /**
     * "min:50" was a length check, so any 50 characters passed and were stored
     * as the template's image.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['screenshot' => str_repeat('a', 200)]),
    )->assertSessionHasErrors('screenshot');

    expect($business->templates()->count())->toBe(0);
});

it('rejects units the enum column cannot hold', function () {
    /**
     * length_width_units was only validated as "string", but the column is
     * enum('m','mm'), so anything else was a 500 at write time.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['length_width_units' => 'inches']),
    )->assertSessionHasErrors('length_width_units');

    expect($business->templates()->count())->toBe(0);
});

it('rejects a second template with the same name in one business', function () {
    /**
     * Two byte-identical rows, both marked active, used to store fine - and the name
     * is the only thing distinguishing one recorded template from another.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(route('admin.businesses.templates.store', $business->id), templatePayload());
    $this->actingAs($admin)->post(route('admin.businesses.templates.store', $business->id), templatePayload())
        ->assertSessionHasErrors('name');

    expect($business->templates()->count())->toBe(1);
});

it('lets two businesses record a template under the same name', function () {
    //The name is unique within a business, not across the platform: both customers
    //can import an "Assembly List"
    $businessA = createBusiness('Business A', true);
    $businessB = createBusiness('Business B', true);
    $admin = createUser(1, $businessA, true, true);

    $this->actingAs($admin)->post(route('admin.businesses.templates.store', $businessA->id), templatePayload());
    $this->actingAs($admin)->post(route('admin.businesses.templates.store', $businessB->id), templatePayload())
        ->assertSessionHasNoErrors();

    expect($businessA->templates()->count())->toBe(1)
        ->and($businessB->templates()->count())->toBe(1);
});

it('lets a template keep its own name when it is edited', function () {
    //The uniqueness rule has to ignore the row being edited, or nothing could be
    //saved twice
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $template = Template::factory()->for($business)->create(['name' => 'Assembly List']);

    $this->actingAs($admin)->put(
        route('admin.businesses.templates.update', [$business->id, $template->id]),
        templatePayload(['name' => 'Assembly List', 'first_description_cell' => 'B9']),
    )->assertSessionHasNoErrors();

    expect($template->fresh()->first_description_cell)->toBe('B9');
});

it('rejects a detection entry that is not in the config', function () {
    /**
     * A recorded template could not be matched to the thing it documents, so nothing
     * noticed when the config entry it described was renamed or removed. The pair now
     * has to name one that exists.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['config_label' => 'A report nobody wrote']),
    )->assertSessionHasErrors('config_label');

    expect($business->templates()->count())->toBe(0);
});

it('rejects a label that exists but not under the source given', function () {
    //Both halves exist in the config, but not together: the pair is what names an entry
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['source' => 'PROJECT_MANAGER', 'config_label' => 'Assembly List']),
    )->assertSessionHasErrors('config_label');

    expect($business->templates()->count())->toBe(0);
});

it('tells the screen when a recorded template names an entry the config no longer has', function () {
    /**
     * The drift this pair exists to surface. A row is worth keeping only while it still
     * describes something real, and the config is edited by hand.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    Template::factory()->for($business)->create([
        'name' => 'Renamed upstream',
        'source' => 'TEKLA',
        'config_label' => 'A label that was removed',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.businesses.templates.index', $business->id))
        ->assertInertia(fn ($page) => $page->where('templates.0.detection.configured', false));
});

it('keeps the stored screenshot when an edit does not send one', function () {
    /**
     * Renaming a template used to re-upload up to 750KB of base64 to change one word,
     * because the form copied the screenshot out of the index props and sent it back.
     * A missing screenshot now means "keep", and must not blank the column.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $template = Template::factory()->for($business)->create();
    $stored = $template->screenshot;

    $payload = templatePayload(['name' => 'Renamed']);
    unset($payload['screenshot']);

    $this->actingAs($admin)->put(
        route('admin.businesses.templates.update', [$business->id, $template->id]),
        $payload,
    )->assertSessionHasNoErrors();

    expect($template->fresh()->screenshot)->toBe($stored)
        ->and($template->fresh()->name)->toBe('Renamed');
});

it('keeps the stored screenshot when the field comes back blank', function () {
    //What the form actually sends: the input is present and empty, which
    //ConvertEmptyStringsToNull turns into a present null
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $template = Template::factory()->for($business)->create();
    $stored = $template->screenshot;

    $this->actingAs($admin)->put(
        route('admin.businesses.templates.update', [$business->id, $template->id]),
        templatePayload(['name' => 'Renamed', 'screenshot' => '']),
    )->assertSessionHasNoErrors();

    expect($template->fresh()->screenshot)->toBe($stored);
});

it('still rejects a screenshot that is sent and is not an image', function () {
    //"keep what is stored" must not become "accept anything"
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $template = Template::factory()->for($business)->create();

    $this->actingAs($admin)->put(
        route('admin.businesses.templates.update', [$business->id, $template->id]),
        templatePayload(['screenshot' => str_repeat('a', 200)]),
    )->assertSessionHasErrors('screenshot');
});

it('would be a disaster if the index carried every screenshot inline', function () {
    /**
     * Each one is up to 750KB of base64, and they were in the page props on every visit
     * and on the redirect back after every create, update and delete, to render a
     * 128x80 thumbnail. The same mistake on the admin users page measured 11.5MB.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    Template::factory()->for($business)->create();

    $this->actingAs($admin)
        ->get(route('admin.businesses.templates.index', $business->id))
        ->assertInertia(fn ($page) => $page->missing('templates.0.screenshot'));
});

it('serves a recorded screenshot as an image', function () {
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $template = Template::factory()->for($business)->create();

    $response = $this->actingAs($admin)
        ->get(route('admin.businesses.templates.screenshot', [$business->id, $template->id]))
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'image/png');

    //The bytes of the 1x1 PNG the factory stores, not the data URL
    expect($response->getContent())
        ->toBe(base64_decode(explode(',', $template->screenshot)[1], true));
});

it('would be a disaster if a screenshot could be read through another business\'s url', function () {
    //Same reason the resource routes are scoped: a mistyped business id must not
    //serve another customer's document
    $businessA = createBusiness('Business A', true);
    $businessB = createBusiness('Business B', true);
    $admin = createUser(1, $businessA, true, true);

    $theirs = Template::factory()->for($businessB)->create();

    $this->actingAs($admin)
        ->get(route('admin.businesses.templates.screenshot', [$businessA->id, $theirs->id]))
        ->assertNotFound();
});

it('would be a disaster if a non-admin could read a recorded screenshot', function () {
    $business = createBusiness('Business A', true);
    $user = createUser(1, $business, false, true);
    $template = Template::factory()->for($business)->create();

    $this->actingAs($user)
        ->get(route('admin.businesses.templates.screenshot', [$business->id, $template->id]))
        ->assertRedirect('/');
});

it('does not serve a screenshot that is not a data url this app wrote', function () {
    /**
     * Rows predating the data-URL rule can hold anything, and serving a stored payload
     * as a file is exactly where that matters.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $template = Template::factory()->for($business)->create();
    //Bypassing validation the way an old row did
    $template->forceFill(['screenshot' => '<svg onload="alert(1)"></svg>'])->save();

    $this->actingAs($admin)
        ->get(route('admin.businesses.templates.screenshot', [$business->id, $template->id]))
        ->assertNotFound();
});

it('would be a disaster if recording a template changed what an import detects', function () {
    /**
     * The screen says it is reference only, and nothing tested that. Detection reads
     * config/TableTemplates.php and nothing else; had someone wired the templates table
     * into eligibleTables(), an admin recording a row would start changing how real
     * uploads are read, and the suite would have stayed green.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $csvService = new CsvService;

    $this->actingAs($admin);

    $before = $csvService->eligibleTables();

    Template::factory()->for($business)->create(['active' => true]);
    Template::factory()->for($business)->create(['active' => false]);

    expect($csvService->eligibleTables())->toBe($before)
        //And it is the config that decides, not the table
        ->and(count($before))->toBe(count(config('TableTemplates')));
});

it('would be a disaster if the index shipped the whole business row', function () {
    /**
     * A Business serializes 23 columns including every cost and pricing setting, and
     * this page reads the id and the domain. It is the mistake UserResource records.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)
        ->get(route('admin.businesses.templates.index', $business->id))
        ->assertInertia(fn ($page) => $page
            ->has('business', fn ($businessProp) => $businessProp
                ->where('id', $business->id)
                ->where('domain', $business->domain)
                //Nothing else: no labour rate, no material cost, no scrap recovery rate
                ->etc()
            )
            ->where('business', fn ($businessProp) => count($businessProp) === 2)
        );
});

it('would be a disaster if the admin users list broke for a user with no business', function () {
    /**
     * users.business_id is nullable and UserResource dereferenced it straight
     * away, so one orphaned user took the whole list down for every admin.
     */
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    $orphan = createUser(2, $business, false, true);
    $orphan->business_id = null;
    $orphan->save();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertStatus(200);
});
