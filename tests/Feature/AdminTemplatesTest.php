<?php

use App\Models\Template;

function templatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Tekla Assembly List',
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
