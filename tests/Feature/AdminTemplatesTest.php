<?php

use App\Models\Business;
use App\Models\Template;
use App\Models\User;
use App\Services\CsvService;
use App\Services\TemplateTestCertificate;

/**
 * The templates this business has. A business is created with none, so every row here is one the
 * test put there.
 *
 * @return \Illuminate\Database\Eloquent\Collection<int, Template>
 */
function recordedTemplates(Business $business)
{
    return $business->templates()->get();
}

/**
 * The template form, as the screen posts it.
 *
 * Given a business, it also carries proof that these values passed a test, which creating a template
 * is gated on. Signed here rather than earned by running a test, because these tests are about what
 * the store request does with a field: what a test has to do to pass, and what happens to a payload
 * with no proof at all, are AdminTemplateTestRunTest's and AdminTemplateGateTest's subjects.
 *
 * Without a business the payload is the one nobody tested - which is exactly what an update takes,
 * since editing a recorded template is deliberately not gated.
 */
function templatePayload(array $overrides = [], ?Business $business = null): array
{
    $payload = array_merge([
        'name' => 'Tekla Assembly List',
        'source' => 'TEKLA',
        'type' => 'CAD_BILL_OF_MATERIALS',
        /*
         * The two fields detection is made of: the labels find the table in an upload, and the
         * heading cell is the origin every column below is measured from. A payload without them
         * is a record that matches nothing, which the request refuses.
         */
        'heading_cell' => 'A6',
        'expected_heading_labels' => ['Mark', 'Qty', 'Profile'],
        'first_description_cell' => 'B7',
        'first_material_cell' => 'C7',
        'first_grade_cell' => null,
        'first_surface_cell' => null,
        'first_length_required_cell' => 'D7',
        'first_width_required_cell' => null,
        'first_sub_qty_cell' => 'F7',
        'skip_or_finish_check_cell' => null,
        'should_skip_row' => null,
        'is_last_data_row' => null,
        'compound_description_prefix' => null,
        'compound_description_suffix' => null,
        'compound_description_cells' => null,
        'assembly_mark_rule' => 'NONE',
        'assembly_mark_cell' => null,
        'screenshot' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        'length_width_units' => 'mm',
        'web_source' => null,
        'active' => true,
    ], $overrides);

    if ($business !== null) {
        $payload['template_test_token'] = (new TemplateTestCertificate)->issue($business, $payload);
    }

    return $payload;
}

it('would be a disaster if editing a template silently deactivated it', function () {
    /**
     * initiateUpdate() copied every field except "active", so the form fell back
     * to its false default and every save turned an active template off. There was
     * no input for "active" either, so it could never be turned back on.
     */
    $business = createBusiness('Business A');
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
    $businessA = createBusiness('Business A');
    $businessB = createBusiness('Business B');
    $admin = createUser(1, $businessA, true, true);

    $theirs = Template::factory()->for($businessB)->create(['name' => 'Theirs']);

    $this->actingAs($admin)->put(
        route('admin.businesses.templates.update', [$businessA->id, $theirs->id]),
        templatePayload(['name' => 'Hijacked']),
    )->assertNotFound();

    expect($theirs->fresh()->name)->toBe('Theirs');
});

it('would be a disaster if a template from another business could be deleted through this url', function () {
    $businessA = createBusiness('Business A');
    $businessB = createBusiness('Business B');
    $admin = createUser(1, $businessA, true, true);

    $theirs = Template::factory()->for($businessB)->create();

    $this->actingAs($admin)->delete(
        route('admin.businesses.templates.destroy', [$businessA->id, $theirs->id]),
    )->assertNotFound();

    expect($theirs->fresh())->not->toBeNull();
});

it('would be a disaster if the index leaked another business\'s templates', function () {
    $businessA = createBusiness('Business A');
    $businessB = createBusiness('Business B');
    $admin = createUser(1, $businessA, true, true);

    Template::factory()->for($businessA)->create(['name' => 'Ours']);
    Template::factory()->for($businessB)->create(['name' => 'Theirs']);

    $this->actingAs($admin)
        ->get(route('admin.businesses.templates.index', $businessA->id))
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            //Its own, and nothing else
            ->where('templates', fn ($listed) => collect($listed)->pluck('name')->contains('Ours')
                && ! collect($listed)->pluck('name')->contains('Theirs')
            )
        );
});

it('would be a disaster if a non-admin could reach the templates screen', function () {
    $business = createBusiness('Business A');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('admin.businesses.templates.index', $business->id))
        ->assertRedirect('/');
});

it('stores a template against the business in the url', function () {
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload([], $business),
    )->assertRedirect();

    expect(recordedTemplates($business)->count())->toBe(1)
        ->and(recordedTemplates($business)->first()->active)->toBeTrue();
});

it('stores a template that is not active', function () {
    /**
     * "required" passes on a boolean false, but nothing pinned that: the rule pair
     * is "required|boolean", and a "required" that rejected false would have made
     * every new template active whether the box was ticked or not.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['active' => false], $business),
    )->assertSessionHasNoErrors();

    expect(recordedTemplates($business)->first()->active)->toBeFalse();
});

it('rejects a cell reference that is not a cell reference', function () {
    /**
     * The old rule was "min:2|max:5", which accepted "zz" and "hello".
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['first_description_cell' => 'hello']),
    )->assertSessionHasErrors('first_description_cell');

    expect(recordedTemplates($business)->count())->toBe(0);
});

it('rejects row zero, which is not a row', function () {
    /**
     * The row part of the rule was [0-9]{1,4}, so "B0" stored as a cell reference.
     * Spreadsheet rows start at 1.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['first_description_cell' => 'B0']),
    )->assertSessionHasErrors('first_description_cell');

    expect(recordedTemplates($business)->count())->toBe(0);
});

it('stores a lower case cell reference in upper case', function () {
    /**
     * "b7" used to be stored verbatim, so the table mixed cases - the seeded row
     * said "b28" while the form's own placeholder says "B7".
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['first_description_cell' => 'b7', 'first_sub_qty_cell' => 'f7'], $business),
    )->assertSessionHasNoErrors();

    $template = recordedTemplates($business)->first();

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
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['first_material_cell' => ''], $business),
    )->assertSessionHasNoErrors();

    expect(recordedTemplates($business)->first()->first_material_cell)->toBeNull();
});

it('rejects a screenshot that is not a base64 image', function () {
    /**
     * "min:50" was a length check, so any 50 characters passed and were stored
     * as the template's image.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['screenshot' => str_repeat('a', 200)]),
    )->assertSessionHasErrors('screenshot');

    expect(recordedTemplates($business)->count())->toBe(0);
});

it('rejects units the enum column cannot hold', function () {
    /**
     * length_width_units was only validated as "string", but the column is
     * enum('m','mm'), so anything else was a 500 at write time.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['length_width_units' => 'inches']),
    )->assertSessionHasErrors('length_width_units');

    expect(recordedTemplates($business)->count())->toBe(0);
});

it('rejects a second template with the same name in one business', function () {
    /**
     * Two byte-identical rows, both marked active, used to store fine - and the name
     * is the only thing distinguishing one recorded template from another.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(route('admin.businesses.templates.store', $business->id), templatePayload([], $business));
    $this->actingAs($admin)->post(route('admin.businesses.templates.store', $business->id), templatePayload([], $business))
        ->assertSessionHasErrors('name');

    expect(recordedTemplates($business)->count())->toBe(1);
});

it('lets two businesses record a template under the same name', function () {
    //The name is unique within a business, not across the platform: both customers
    //can import an "Assembly List"
    $businessA = createBusiness('Business A');
    $businessB = createBusiness('Business B');
    $admin = createUser(1, $businessA, true, true);

    $this->actingAs($admin)->post(route('admin.businesses.templates.store', $businessA->id), templatePayload([], $businessA));
    $this->actingAs($admin)->post(route('admin.businesses.templates.store', $businessB->id), templatePayload([], $businessB))
        ->assertSessionHasNoErrors();

    expect(recordedTemplates($businessA)->count())->toBe(1)
        ->and(recordedTemplates($businessB)->count())->toBe(1);
});

it('lets a template keep its own name when it is edited', function () {
    //The uniqueness rule has to ignore the row being edited, or nothing could be
    //saved twice
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $template = Template::factory()->for($business)->create(['name' => 'Their assembly list']);

    $this->actingAs($admin)->put(
        route('admin.businesses.templates.update', [$business->id, $template->id]),
        /*
         * The whole table a row lower, not the description cell alone: the cells are the columns
         * of one row of data, and moving one of them on its own is now refused.
         */
        templatePayload([
            'name' => 'Their assembly list',
            'first_description_cell' => 'B8',
            'first_material_cell' => 'C8',
            'first_length_required_cell' => 'D8',
            'first_sub_qty_cell' => 'F8',
        ]),
    )->assertSessionHasNoErrors();

    expect($template->fresh()->first_description_cell)->toBe('B8');
});

it('refuses a template with no heading labels', function () {
    /**
     * The labels are what finds the table in an upload. Without them the row matches
     * nothing, so saving it produces a template that quietly never fires - which is
     * worse than no template, because the screen would list it as one.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['expected_heading_labels' => []]),
    )->assertSessionHasErrors('expected_heading_labels');

    expect(recordedTemplates($business)->count())->toBe(0);
});

it('refuses a template with no heading cell', function () {
    //Every column is an offset from the heading cell, so without it no column has a position
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['heading_cell' => null]),
    )->assertSessionHasErrors('heading_cell');

    expect(recordedTemplates($business)->count())->toBe(0);
});

it('refuses a template with no way to say what a row is', function () {
    /**
     * CsvService::getTableData() drops any row with a blank description, so a template
     * with neither a description column nor a compound description built out of other
     * cells detects the table and then imports none of it.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['first_description_cell' => null]),
    )->assertSessionHasErrors('first_description_cell');

    expect(recordedTemplates($business)->count())->toBe(0);
});

it('accepts a compound description in place of a description column', function () {
    //How the bolt summaries work: "M" + diameter + grade + length + "mm"
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload([
            'first_description_cell' => null,
            'compound_description_prefix' => 'M',
            'compound_description_suffix' => 'mm',
            'compound_description_cells' => ['B7', 'C7'],
        ], $business),
    )->assertSessionHasNoErrors();

    expect(recordedTemplates($business)->first()->compound_description_cells)->toBe(['B7', 'C7']);
});

it('tells the screen when a recorded template cannot detect anything', function () {
    /**
     * Rows recorded before templates drove detection hold cell references and no heading
     * row. They are worth keeping - somebody described a real spreadsheet - but the screen
     * has to say they are documentation and not a working template, or an admin will wonder
     * why the file they describe never imports.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    Template::factory()->for($business)->undetectable()->create(['name' => 'Recorded long ago']);

    $this->actingAs($admin)
        ->get(route('admin.businesses.templates.index', $business->id))
        ->assertInertia(fn ($page) => $page
            ->where('templates.0.detection.detects', false)
            ->where('templates.0.detection.blocked_by.0', 'No heading cell, so there is nothing to measure the columns from.')
        );
});

it('keeps the stored screenshot when an edit does not send one', function () {
    /**
     * Renaming a template used to re-upload up to 750KB of base64 to change one word,
     * because the form copied the screenshot out of the index props and sent it back.
     * A missing screenshot now means "keep", and must not blank the column.
     */
    $business = createBusiness('Business A');
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
    $business = createBusiness('Business A');
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
    $business = createBusiness('Business A');
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
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    Template::factory()->for($business)->create();

    $this->actingAs($admin)
        ->get(route('admin.businesses.templates.index', $business->id))
        ->assertInertia(fn ($page) => $page->missing('templates.0.screenshot'));
});

it('serves a recorded screenshot as an image', function () {
    $business = createBusiness('Business A');
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
    $businessA = createBusiness('Business A');
    $businessB = createBusiness('Business B');
    $admin = createUser(1, $businessA, true, true);

    $theirs = Template::factory()->for($businessB)->create();

    $this->actingAs($admin)
        ->get(route('admin.businesses.templates.screenshot', [$businessA->id, $theirs->id]))
        ->assertNotFound();
});

it('would be a disaster if a non-admin could read a recorded screenshot', function () {
    $business = createBusiness('Business A');
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
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $template = Template::factory()->for($business)->create();
    //Bypassing validation the way an old row did
    $template->forceFill(['screenshot' => '<svg onload="alert(1)"></svg>'])->save();

    $this->actingAs($admin)
        ->get(route('admin.businesses.templates.screenshot', [$business->id, $template->id]))
        ->assertNotFound();
});

/**
 * The labels an upload by this user would be matched against, which is the whole of what
 * recording a template is now for.
 */
function eligibleLabels(): array
{
    return array_column((new CsvService)->eligibleTables(), 'label');
}

it('would be a disaster if recording a template did not change what an import detects', function () {
    /**
     * This test used to assert the opposite, and was right to: detection read
     * config/TableTemplates.php and the screen said so. Recording a template is now what
     * makes a spreadsheet importable, so a row that reaches the table and never reaches
     * detection is the same feature broken from the other side.
     */
    $business = createBusiness('Business A');
    $user = createUser(2, $business, false, true);

    $this->actingAs($user);

    $before = eligibleLabels();

    Template::factory()->for($business)->create(['name' => 'Their own report', 'active' => true]);

    expect(eligibleLabels())->toBe([...$before, 'Their own report']);
});

it('does not match uploads against a template that is not active', function () {
    /**
     * Active is what an admin flips once the record has been checked against a real file.
     * Before this change it decided nothing at all.
     */
    $business = createBusiness('Business A');
    $user = createUser(2, $business, false, true);

    $this->actingAs($user);

    $before = eligibleLabels();

    Template::factory()->for($business)->inactive()->create(['name' => 'Not turned on yet']);

    expect(eligibleLabels())->toBe($before);
});

it('does not match uploads against a template that cannot detect', function () {
    //Recorded before templates drove detection: cell references, and nothing to find them by
    $business = createBusiness('Business A');
    $user = createUser(2, $business, false, true);

    $this->actingAs($user);

    $before = eligibleLabels();

    Template::factory()->for($business)->undetectable()->create(['name' => 'Recorded long ago']);

    expect(eligibleLabels())->toBe($before);
});

it('would be a disaster if one business\'s template read another business\'s upload', function () {
    /**
     * Templates carry a customer's column layout, and they are now live. A template
     * leaking across businesses does not merely show the wrong row on a screen - it reads
     * a stranger's spreadsheet at the wrong offsets and imports the results.
     */
    $businessA = createBusiness('Business A');
    $businessB = createBusiness('Business B');

    Template::factory()->for($businessB)->create(['name' => 'Theirs']);

    $this->actingAs(createUser(2, $businessA, false, true));

    expect(eligibleLabels())->not->toContain('Theirs');
});

it('gives a new business no templates at all', function () {
    /**
     * config/TableTemplates.php matched its four Tekla entries against every business, and when
     * templates moved into the database every business was given its own copies to keep that
     * going. They are one customer's export settings: the columns sit where that customer's Tekla
     * was configured to put them, and a template that matches a heading row it was not calibrated
     * against reads the columns beside the ones it wants - silently, because the numbers it finds
     * there are numbers.
     *
     * So a business starts with nothing and can import nothing. The customer emails us the reports
     * they export and an admin records a template per report against their business.
     */
    $business = createBusiness('Business A');

    $this->actingAs(createUser(2, $business, false, true));

    expect($business->templates()->count())->toBe(0)
        ->and(eligibleLabels())->toBe([]);
});

it('gives the admin\'s own business no templates either', function () {
    //createBusiness('gmail') is the admin email's domain, which is how the config scoped the
    //demo template for material_list.xlsx to us. Nothing is scoped to anybody now.
    $business = createBusiness('gmail');

    $this->actingAs(createUser(2, $business, false, true));

    expect($business->templates()->count())->toBe(0);
});

it('does not serve a screenshot url for a template that has none', function () {
    //A screenshot records where a template came from, and it is not compulsory
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    Template::factory()->for($business)->create(['screenshot' => null]);

    $this->actingAs($admin)
        ->get(route('admin.businesses.templates.index', $business->id))
        ->assertInertia(fn ($page) => $page
            ->where('templates.0.has_screenshot', false)
        );
});

it('would be a disaster if the index shipped the whole business row', function () {
    /**
     * A Business serializes 23 columns including every cost and pricing setting, and
     * this page reads the id and the domain. It is the mistake UserResource records.
     */
    $business = createBusiness('Business A');
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
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $orphan = createUser(2, $business, false, true);
    $orphan->business_id = null;
    $orphan->save();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertStatus(200);
});

/*
 * The five cell fields describe one row of one table. Each one on its own is only checked for being
 * spelled like a cell reference, which is how a record that describes no spreadsheet at all used to
 * store perfectly happily.
 */

it('refuses cells that name different rows', function () {
    //They are the five columns of the first row of data. Three rows is not one row of anything.
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['first_length_required_cell' => 'D12']),
    )->assertSessionHasErrors('first_length_required_cell');

    expect(recordedTemplates($business)->count())->toBe(0);
});

it('refuses row 1 as the first row of data', function () {
    //A table's heading row is above its data, so row 1 is always the reference typed a row short
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload([
            'first_description_cell' => 'B1',
            'first_material_cell' => 'C1',
            'first_length_required_cell' => 'D1',
            'first_sub_qty_cell' => 'F1',
        ]),
    )->assertSessionHasErrors('first_description_cell');

    expect(recordedTemplates($business)->count())->toBe(0);
});

/**
 * Every warning the index shows against the first listed template.
 */
function firstTemplateWarnings(Business $business, User $admin): array
{
    $warnings = [];

    test()->actingAs($admin)
        ->get(route('admin.businesses.templates.index', $business->id))
        ->assertInertia(function ($page) use (&$warnings) {
            $warnings = $page->toArray()['props']['templates'][0]['detection']['warnings'];

            return $page;
        });

    return $warnings;
}

it('saves a record that reads oddly, and says how', function () {
    /**
     * Two columns recorded as the same column is almost always a mistake, and occasionally is not -
     * a Tekla "Profile" column really is both the description and the material.
     *
     * The ruling that a record warns rather than blocks survives templates becoming live, narrowed
     * to what it was always about: a record that cannot import anything is refused, and a record
     * that is merely unusual is saved and argued with on screen. Refusing this one would stop an
     * admin recording a spreadsheet that is genuinely shaped this way.
     */
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['first_material_cell' => 'B7'], $business),
    )->assertSessionHasNoErrors();

    expect(recordedTemplates($business)->count())->toBe(1)
        ->and(firstTemplateWarnings($business, $admin))
        ->toContain('The description and material cells are both in column B. Check that is deliberate.');
});

it('says when the heading cell is too far above the data to be the heading', function () {
    //Usually the anchor has been pointed at a report title rather than at the heading run
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['heading_cell' => 'A1'], $business),
    )->assertSessionHasNoErrors();

    expect(firstTemplateWarnings($business, $admin))
        ->toContain('There are 5 rows between the heading row and the first row of data. Check the heading cell is the heading and not a title above it.');
});

it('says when an assembly mark rule has no cell to read', function () {
    $business = createBusiness('Business A');
    $admin = createUser(1, $business, true, true);

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        templatePayload(['assembly_mark_rule' => 'COLUMN', 'assembly_mark_cell' => null], $business),
    )->assertSessionHasNoErrors();

    expect(firstTemplateWarnings($business, $admin))
        ->toContain('The assembly mark is set to COLUMN but no cell is given, so no mark is read.');
});
