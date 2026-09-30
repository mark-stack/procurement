<?php

use App\Models\Business;
use App\Models\Template;
use App\Models\User;
use App\Services\SpreadsheetGrid;
use App\Services\SpreadsheetImage;
use Illuminate\Http\UploadedFile;

/*
 * The template screenshot, drawn rather than pasted in.
 *
 * It used to be a single-line input asking for a base64 data URL "in 800x500px", with a link to
 * somebody's CodePen for converting one: take a screenshot of the customer's spreadsheet, convert it,
 * paste three quarters of a megabyte of text into a text field, per template. By the time the screen
 * asks for it, it has already parsed that spreadsheet into a grid - so it draws it instead.
 *
 * These cover the three things that has to keep true: the picture is a real image of the right sheet,
 * it marks what the template makes of that sheet, and nobody is stopped from recording a template
 * when there is no picture to be had.
 */

/**
 * A sheet with a title block above the table, so the heading row is not row 1 and the picture has a
 * reason to start somewhere other than the top.
 */
function drawnSheet(): UploadedFile
{
    $csv = <<<'CSV'
    ConTek Fabrication,,
    Project:,WAREHOUSE 4,
    ,,
    Profile,Qty,Length (mm)
    250PFC,4,9000
    310UB40,6,12000
    CSV;

    return UploadedFile::fake()->createWithContent('acme.csv', $csv);
}

/**
 * The template that reads drawnSheet().
 *
 * @return array<string, mixed>
 */
function drawnForm(array $overrides = []): array
{
    return array_merge([
        'source' => 'TEKLA',
        'type' => 'CAD_BILL_OF_MATERIALS',
        'heading_cell' => 'A4',
        'expected_heading_labels' => ['Profile', 'Qty', 'Length (mm)'],
        'first_description_cell' => 'A5',
        'first_sub_qty_cell' => 'B5',
        'first_length_required_cell' => 'C5',
        'assembly_mark_rule' => 'NONE',
        'length_width_units' => 'mm',
    ], $overrides);
}

/**
 * @return array{0: Business, 1: User}
 */
function drawnAdmin(): array
{
    $business = createBusiness('Business A', true);
    $admin = createUser(1, $business, true, true);

    seedMasterMaterials();

    return [$business, $admin];
}

/**
 * The image a result carries, decoded, with its dimensions.
 *
 * @return array{bytes: string, width: int, height: int, mime: string}
 */
function decodedImage(string $dataUrl): array
{
    $bytes = (string) base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
    $size = getimagesizefromstring($bytes);

    return [
        'bytes' => $bytes,
        'width' => $size[0] ?? 0,
        'height' => $size[1] ?? 0,
        'mime' => $size['mime'] ?? '',
    ];
}

it('draws the screenshot from the sample rather than asking anybody to paste one in', function () {
    [$business, $admin] = drawnAdmin();

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.test', $business->id),
        [...drawnForm(), 'sample' => drawnSheet()],
    )->assertSessionHasNoErrors();

    $result = session('templateTest');
    $image = decodedImage($result['screenshot']);

    expect($result['screenshot'])->toStartWith('data:image/png;base64,')
        //A real PNG, not a string that looks like one
        ->and($image['mime'])->toBe('image/png')
        ->and($image['width'])->toBeGreaterThan(200)
        ->and($image['height'])->toBeGreaterThan(50);
});

it('draws one from a sample that was only read, before any test has been run', function () {
    //Reading a spreadsheet fills the form in; the picture is one of the fields it fills in
    [$business, $admin] = drawnAdmin();

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.propose', $business->id),
        ['sample' => drawnSheet()],
    )->assertSessionHasNoErrors();

    expect(session('templateProposal')['prefill']['screenshot'])->toStartWith('data:image/png;base64,');
});

it('stores the drawn screenshot, and serves it back as an image', function () {
    /**
     * The end of it: nothing was typed into a screenshot field, and the template has one that the
     * list can show and a browser can render.
     */
    [$business, $admin] = drawnAdmin();

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.test', $business->id),
        [...drawnForm(), 'sample' => drawnSheet()],
    );

    $test = session('templateTest');

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        [
            ...drawnForm(),
            'name' => 'Acme Profile List',
            'active' => true,
            'web_source' => null,
            'screenshot' => $test['screenshot'],
            'template_test_token' => $test['token'],
        ],
    )->assertSessionHasNoErrors();

    $template = $business->templates()->first();

    $this->actingAs($admin)
        ->get(route('admin.businesses.templates.screenshot', [$business->id, $template->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('would be a disaster if a drawn screenshot were too big for the column that holds it', function () {
    /**
     * The rule caps the field at a million characters, which was sized for a photograph of a screen.
     * A drawn one is flat colour and text, so it is smaller by an order of magnitude - and it has to
     * stay that way, because it now travels on every test as well as on the save.
     */
    [$business, $admin] = drawnAdmin();

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.test', $business->id),
        [...drawnForm(), 'sample' => drawnSheet()],
    );

    expect(strlen(session('templateTest')['screenshot']))->toBeLessThan(200_000);
});

it('draws the table it found rather than the top left corner of the file', function () {
    /**
     * A table found on row 28 is a picture of rows 26 onwards. Drawing from row 1 every time would
     * give a wall of identical title blocks for the reports whose tables start deep in the sheet -
     * which is every report with a project header on it.
     */
    $grid = SpreadsheetGrid::fromUpload(UploadedFile::fake()->createWithContent(
        'deep.csv',
        implode("\n", [
            ...array_fill(0, 40, 'Title block,,'),
            'Profile,Qty,Length (mm)',
            '250PFC,4,9000',
        ]),
    ));

    $drawn = decodedImage((new SpreadsheetImage)->render($grid, drawnForm(), 41));
    $fromTheTop = decodedImage((new SpreadsheetImage)->render($grid, drawnForm()));

    //Same size, different pixels: one is a picture of the table, the other of the title block
    expect($drawn['height'])->toBe($fromTheTop['height'])
        ->and($drawn['bytes'])->not->toBe($fromTheTop['bytes']);
});

it('widens the picture to reach a column the record names', function () {
    /**
     * The Tekla reports use every third column - their length column is S - so a fixed width drew a
     * picture with the single most important column just off the right edge.
     */
    $grid = SpreadsheetGrid::fromUpload(UploadedFile::fake()->createWithContent(
        'wide.csv',
        implode("\n", [
            implode(',', array_fill(0, 20, 'x')),
            implode(',', array_fill(0, 20, 'y')),
        ]),
    ));

    $narrow = decodedImage((new SpreadsheetImage)->render($grid, ['first_description_cell' => 'A2']));
    $wide = decodedImage((new SpreadsheetImage)->render($grid, ['first_length_required_cell' => 'S2']));

    expect($wide['width'])->toBeGreaterThan($narrow['width']);
});

it('would be a disaster if a template could not be recorded because there was no picture to draw', function () {
    /**
     * The screenshot used to be required, which was the only thing that made anybody paste one in.
     * Nobody pastes one in any more, so "required" would have stopped meaning "the admin must supply
     * one" and started meaning "our renderer must have worked" - and an admin whose template is
     * refused for that has nothing on the form to fix.
     */
    [$business, $admin] = drawnAdmin();

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.test', $business->id),
        [...drawnForm(), 'sample' => drawnSheet()],
    );

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        [
            ...drawnForm(),
            'name' => 'Acme Profile List',
            'active' => true,
            'web_source' => null,
            'screenshot' => null,
            'template_test_token' => session('templateTest')['token'],
        ],
    )->assertSessionHasNoErrors();

    expect(Template::count())->toBe(1)
        ->and($business->templates()->first()->screenshot)->toBeNull();
});

it('still refuses a screenshot that is not an image', function () {
    //Nullable, not unvalidated: whatever does arrive in that column is still served as an image
    [$business, $admin] = drawnAdmin();

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.test', $business->id),
        [...drawnForm(), 'sample' => drawnSheet()],
    );

    $this->actingAs($admin)->post(
        route('admin.businesses.templates.store', $business->id),
        [
            ...drawnForm(),
            'name' => 'Acme Profile List',
            'active' => true,
            'screenshot' => 'data:text/html;base64,PHNjcmlwdD4=',
            'template_test_token' => session('templateTest')['token'],
        ],
    )->assertSessionHasErrors('screenshot');

    expect(Template::count())->toBe(0);
});

it('draws nothing rather than throwing for a sheet with nothing in it', function () {
    //A caller that has to handle null is a caller that never 500s on an odd upload
    expect((new SpreadsheetImage)->render(new SpreadsheetGrid([])))->toBeNull();
});
