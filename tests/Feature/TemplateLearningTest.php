<?php

use App\Enums\TemplateLearningEnums;
use App\Models\Project;
use App\Models\RawMaterialQuote;
use App\Models\Template;
use App\Models\TemplateLearningAttempt;
use App\Models\User;
use App\Notifications\TemplateLearningFailedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * A customer uploads a spreadsheet nobody has recorded a template for, and it imports anyway.
 *
 * This is what replaced onboarding. A new business used to land on a page asking it to email us
 * example bills of materials, and wait up to two business days while somebody here read each one,
 * filled in the templates form, tested it and pressed Activate. The reading and the testing were
 * already automated and sat behind the admin templates screen; what was missing was letting a
 * customer's own upload trigger them.
 *
 * The gate is unchanged, which is the point. TemplateTestChecklist::passed() is what stops an admin
 * saving a template that would import nothing, and it is what stops one being written here - the same
 * function, over the same extraction, run by the same TemplateTestService. A template a customer's
 * upload wrote has cleared exactly what a template we type has to clear.
 *
 * So these cover the two halves: that a passing proposal becomes a live template and the file
 * imports, and that a failing one becomes nothing at all - no template, no half-template, no
 * inactive row - plus an attempt an admin can pick up and an email telling them to.
 */
function learningUpload(string $file = 'tekla_assembly_list.xlsx'): UploadedFile
{
    return new UploadedFile(
        public_path('examples/'.$file),
        $file,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    );
}

/**
 * The two OpenAI calls one learning attempt makes, in the order it makes them: the proposal that
 * describes the sheet, then the review that reads the extracted rows back.
 *
 * A sequence rather than one response for both, because they are different questions with different
 * schemas - and answering the review with the proposal's shape leaves looks_like_materials_list
 * absent, which reads as false and fails the template for a reason that is about the fake.
 *
 * @param  array<string, mixed>  $proposal
 * @param  array<string, mixed>  $review
 */
function fakeLearningCalls(array $proposal, array $review = []): void
{
    config(['openai.key' => 'sk-test', 'openai.model' => 'gpt-5-mini']);

    $answer = fn (array $body) => [
        'choices' => [[
            'finish_reason' => 'stop',
            'message' => ['content' => json_encode($body)],
        ]],
    ];

    Http::fake([
        'api.openai.com/*' => Http::sequence()
            ->push($answer([
                'name' => 'Assembly List',
                'source' => 'TEKLA',
                'type' => 'CAD_BILL_OF_MATERIALS',
                'expected_heading_labels' => [],
                'heading_cell' => null,
                'first_description_cell' => null,
                'first_material_cell' => null,
                'first_grade_cell' => null,
                'first_surface_cell' => null,
                'first_length_required_cell' => null,
                'first_width_required_cell' => null,
                'first_sub_qty_cell' => null,
                'length_width_units' => 'mm',
                'confidence' => 'high',
                'notes' => 'Heading row 6, first data row 7.',
                ...$proposal,
            ]))
            ->push($answer([
                'looks_like_materials_list' => true,
                'description_column_correct' => true,
                'columns_aligned' => true,
                'quantities_plausible' => true,
                'lengths_plausible' => true,
                'verdict' => 'valid',
                'confidence' => 'high',
                'summary' => 'Steel sections with lengths and quantities.',
                'issues' => [],
                ...$review,
            ]))
            //Anything beyond the two is the test being wrong about how many calls one attempt makes
            ->whenEmpty(Http::response(['error' => ['message' => 'asked more than twice']], 429)),
    ]);
}

/**
 * The cells that really describe public/examples/tekla_assembly_list.xlsx - the same ones
 * Tests\Support\ExampleTemplates records by hand for it.
 *
 * Given as the model's answer because a model reading that sheet correctly is the case worth testing:
 * everything after the answer is ours, and all of it has to work on a right answer before being
 * trusted with a wrong one.
 *
 * @return array<string, mixed>
 */
function correctAssemblyListReading(): array
{
    return [
        'heading_cell' => 'A6',
        'expected_heading_labels' => ['Mark', 'Qty', 'Profile', 'Name', 'Finish', 'Length (mm)', 'Unit Area (m2)', 'Unit Weight (kg)'],
        'first_description_cell' => 'D7',
        'first_surface_cell' => 'O7',
        'first_length_required_cell' => 'S7',
        'first_sub_qty_cell' => 'B7',
        'length_width_units' => 'mm',
    ];
}

/**
 * Staff of a business with no templates at all, which is what registration creates.
 */
function customerWithNoTemplates(): User
{
    $business = createBusiness('newfabricator');

    return createUser(2, $business, false, true);
}

function uploadToNewProject(User $user, UploadedFile $file, string $name = 'First job'): Illuminate\Testing\TestResponse
{
    return test()->actingAs($user)
        ->from('/dashboard')
        ->post(route('projects.store'), [
            'name' => $name,
            'reference' => null,
            'date_materials_required' => null,
            'tentative' => false,
            'excel' => [$file],
        ]);
}

it('would be a disaster if a customer with no template still had to email us their spreadsheet', function () {
    /*
     * The whole feature. A business nobody has set up uploads a Tekla report, and it imports - the
     * template is written, tested against that same file, and saved live on the way through.
     */
    seedMasterMaterials();
    fakeLearningCalls(correctAssemblyListReading());

    $user = customerWithNoTemplates();

    expect($user->business->templates()->count())->toBe(0);

    uploadToNewProject($user, learningUpload());

    $template = $user->business->templates()->sole();

    expect($template->active)->toBeTrue()
        ->and($template->generated_by_ai)->toBeTrue()
        //Nobody has read it. That is the whole of what the flag is for
        ->and($template->reviewed_at)->toBeNull()
        ->and($template->canDetect())->toBeTrue();

    //The project was kept, and the materials came out of the file
    expect(Project::count())->toBe(1)
        ->and(RawMaterialQuote::count())->toBeGreaterThan(0);
});

it('recognises the same format next time without asking anybody', function () {
    /*
     * The reason the template is saved rather than used once and thrown away. The second upload of a
     * format we have learned has to be an ordinary import: no OpenAI call, no attempt, no second
     * template describing the same table - which would read every row twice and import it twice.
     */
    seedMasterMaterials();
    fakeLearningCalls(correctAssemblyListReading());

    $user = customerWithNoTemplates();

    uploadToNewProject($user, learningUpload(), 'First job');

    expect($user->business->templates()->count())->toBe(1);

    //Two calls were made and the fake refuses a third, so a second attempt would fail loudly
    uploadToNewProject($user, learningUpload(), 'Second job');

    expect($user->business->templates()->count())->toBe(1)
        ->and(TemplateLearningAttempt::count())->toBe(0)
        ->and(Project::count())->toBe(2);

    Http::assertSentCount(2);
});

it('would be a disaster if a template that imports nothing were saved anyway', function () {
    /*
     * The gate, from the customer's side. A model that places the columns one cell out passes every
     * check about the record - they are all real cells on the right row - and extracts a column of
     * weights as descriptions, which matches nothing in the catalogue.
     *
     * Nothing may come of that. Not a live template, not an inactive one to be tidied up later: a row
     * in this table is what uploads are matched against, and a wrong one reads the columns beside the
     * ones it wants for every file the customer sends from then on.
     */
    seedMasterMaterials();
    fakeLearningCalls([
        ...correctAssemblyListReading(),
        //Unit Weight (kg), not Profile. Plausible strings, no steel
        'first_description_cell' => 'Z7',
    ], ['looks_like_materials_list' => false, 'verdict' => 'invalid', 'summary' => 'These are weights, not sections.']);

    Notification::fake();

    $user = customerWithNoTemplates();
    createUser(1, createBusiness('platform'), true, true);

    uploadToNewProject($user, learningUpload());

    expect($user->business->templates()->count())->toBe(0);
});

it('keeps the file and the proposal when it gives up, and tells an admin', function () {
    /*
     * What used to be a sentence asking the customer to email the file to support, which meant the
     * failures we heard about were the ones a customer bothered to report. The attempt holds the
     * spreadsheet, what was proposed for it and every check that was asked - so an admin opens the
     * row, gets the form filled in with the proposal that failed, and finishes it.
     */
    seedMasterMaterials();
    fakeLearningCalls([...correctAssemblyListReading(), 'first_description_cell' => 'Z7'],
        ['looks_like_materials_list' => false, 'verdict' => 'invalid', 'summary' => 'Weights, not sections.']);

    Storage::fake('local');
    Notification::fake();

    $admin = createUser(1, createBusiness('platform'), true, true);
    $user = customerWithNoTemplates();

    uploadToNewProject($user, learningUpload());

    $attempt = TemplateLearningAttempt::query()->sole();

    expect($attempt->business_id)->toBe($user->business_id)
        ->and($attempt->user_id)->toBe($user->id)
        ->and($attempt->outcome)->toBe(TemplateLearningEnums::REFUSED)
        ->and($attempt->file_name)->toBe('tekla_assembly_list.xlsx')
        //The proposal that failed, so the form can be opened on it rather than on nothing
        ->and($attempt->proposal['heading_cell'])->toBe('A6')
        ->and($attempt->proposal['first_description_cell'])->toBe('Z7')
        //Every check, so an admin can tell "no table found" from "the table is the wrong one"
        ->and($attempt->checks)->not->toBeEmpty()
        ->and($attempt->headline)->not->toBeEmpty()
        ->and($attempt->hasSample())->toBeTrue();

    Notification::assertSentTo($admin, TemplateLearningFailedEmail::class);
});

it('tells the customer we have the file rather than asking them to send it', function () {
    /*
     * The message matters as much as the record. "Email the file to support" asks a customer to report
     * a failure we already know about and hold a copy of, and it is the sentence a new signup used to
     * meet on their first upload.
     */
    seedMasterMaterials();
    fakeLearningCalls([...correctAssemblyListReading(), 'first_description_cell' => 'Z7'],
        ['looks_like_materials_list' => false, 'verdict' => 'invalid', 'summary' => 'Weights.']);

    Notification::fake();

    $user = customerWithNoTemplates();

    uploadToNewProject($user, learningUpload())
        ->assertSessionHas('warning', TemplateLearningEnums::REFUSED->customerMessage());

    //The empty shell goes, so the customer can retry under the same name
    expect(Project::count())->toBe(0);
});

it('records an attempt rather than asking OpenAI when there is no key', function () {
    /*
     * An installation with no key cannot learn anything, and that must not read as a customer's file
     * being wrong. Nothing is sent, the attempt says so, and the customer gets the same sentence -
     * the difference between the three outcomes is ours to act on and none of it is theirs.
     */
    seedMasterMaterials();
    config(['openai.key' => null]);
    Http::fake();
    Notification::fake();

    $user = customerWithNoTemplates();

    uploadToNewProject($user, learningUpload());

    expect($user->business->templates()->count())->toBe(0)
        ->and(TemplateLearningAttempt::query()->sole()->outcome)->toBe(TemplateLearningEnums::UNREADABLE);

    Http::assertNothingSent();
});

it('would be a disaster if retrying the same unreadable file cost us a model call each time', function () {
    /*
     * Each attempt is two OpenAI calls and a full extraction, and what triggers one is a customer
     * pressing upload. Somebody retrying a file we cannot read is the ordinary shape of that, and
     * without a ceiling it is that cost over and over for the same answer.
     *
     * The attempt is still recorded past the ceiling, as THROTTLED, because a customer uploading the
     * same file ten times is worth seeing rather than silently absorbing.
     */
    seedMasterMaterials();
    config(['templates.learning.hourly_limit' => 2, 'openai.key' => null]);
    Http::fake();
    Notification::fake();

    $user = customerWithNoTemplates();

    foreach (range(1, 4) as $attempt) {
        uploadToNewProject($user, learningUpload(), 'Job '.$attempt);
    }

    $outcomes = TemplateLearningAttempt::query()->orderBy('id')->pluck('outcome')->all();

    expect($outcomes)->toBe([
        TemplateLearningEnums::UNREADABLE,
        TemplateLearningEnums::UNREADABLE,
        TemplateLearningEnums::THROTTLED,
        TemplateLearningEnums::THROTTLED,
    ]);
});

it('does not keep a copy of the spreadsheet for an attempt it never read', function () {
    /*
     * A throttled attempt read nothing, so there is nothing to reproduce - and the shape that produces
     * them is a customer retrying one file, which would otherwise store the same spreadsheet five
     * more times.
     */
    seedMasterMaterials();
    config(['templates.learning.hourly_limit' => 1, 'openai.key' => null]);
    Storage::fake('local');
    Http::fake();
    Notification::fake();

    $user = customerWithNoTemplates();

    uploadToNewProject($user, learningUpload(), 'One');
    uploadToNewProject($user, learningUpload(), 'Two');

    $attempts = TemplateLearningAttempt::query()->orderBy('id')->get();

    expect($attempts[0]->hasSample())->toBeTrue()
        ->and($attempts[1]->outcome)->toBe(TemplateLearningEnums::THROTTLED)
        ->and($attempts[1]->sample_path)->toBeNull();
});

it('asks nothing at all when learning is switched off', function () {
    /*
     * The off switch has to leave the product working: the upload still fails, the attempt is still
     * recorded so a business uploading formats we cannot read is still visible, and the customer still
     * gets a sentence that does not ask them to do anything.
     */
    seedMasterMaterials();
    config(['templates.learning.enabled' => false, 'openai.key' => 'sk-test']);
    Http::fake();
    Notification::fake();

    $user = customerWithNoTemplates();

    uploadToNewProject($user, learningUpload());

    expect(TemplateLearningAttempt::count())->toBe(1)
        ->and($user->business->templates()->count())->toBe(0);

    Http::assertNothingSent();
});

it('closes the attempts a template answers when an admin records one by hand', function () {
    /*
     * One customer's format usually arrives several times before anybody gets to it, so the admin who
     * finally records the template should not then be left ticking off the rows it covered. Each
     * unresolved sample is re-read and matched the way an upload is - so the list closes itself, and
     * only for the attempts the new template really reads.
     */
    seedMasterMaterials();
    config(['openai.key' => null]);
    Http::fake();
    Notification::fake();

    $user = customerWithNoTemplates();
    $business = $user->business;
    $admin = createUser(1, createBusiness('platform'), true, true);

    //Two uploads of the same format, neither of which could be learned
    uploadToNewProject($user, learningUpload(), 'One');
    uploadToNewProject($user, learningUpload(), 'Two');

    //And one of a format the template below does not read
    uploadToNewProject($user, learningUpload('material_list.xlsx'), 'Three');

    expect(TemplateLearningAttempt::query()->unresolved()->count())->toBe(3);

    //The template an admin would type for the Tekla report, recorded directly
    $template = $business->templates()->create([
        ...collect((new Tests\Support\ExampleTemplates)->definitions())
            ->firstWhere('name', 'Assembly List'),
    ]);

    $resolved = app(App\Services\TemplateLearningService::class)->resolveSettled($business, $template, $admin);

    expect($resolved)->toBe(2)
        ->and(TemplateLearningAttempt::query()->unresolved()->count())->toBe(1)
        //The sample goes with the attempt: it was kept to reproduce a failure that is now fixed
        ->and(TemplateLearningAttempt::query()->whereNotNull('resolved_at')->get()
            ->every(fn (TemplateLearningAttempt $attempt) => $attempt->sample_path === null))->toBeTrue();
});

it('would be a disaster if one customer could download another customer\'s spreadsheet', function () {
    /*
     * The sample is a customer's bill of materials - their parts, their quantities, their project -
     * held on a private disk because an admin may need to reproduce a failure with it. The route to it
     * is behind the admin panel, and it is scoped to the business in the path so that a mistyped id
     * cannot serve one business's file from another's page.
     */
    seedMasterMaterials();
    config(['openai.key' => null]);
    Http::fake();
    Notification::fake();

    $user = customerWithNoTemplates();
    $admin = createUser(1, createBusiness('platform'), true, true);
    $otherBusiness = createBusiness('somebody-else');

    uploadToNewProject($user, learningUpload());

    $attempt = TemplateLearningAttempt::query()->sole();

    //A customer, at their own business's URL
    $this->actingAs($user)
        ->get(route('admin.businesses.template.attempts.sample', [$user->business_id, $attempt->id]))
        ->assertRedirect('/');

    //An admin, at the wrong business's URL
    $this->actingAs($admin)
        ->get(route('admin.businesses.template.attempts.sample', [$otherBusiness->id, $attempt->id]))
        ->assertNotFound();

    //An admin, at the right one
    $this->actingAs($admin)
        ->get(route('admin.businesses.template.attempts.sample', [$user->business_id, $attempt->id]))
        ->assertOk();
});

it('deletes the spreadsheet when an admin closes an attempt there is no template for', function () {
    /*
     * A file sent by mistake, a scan saved as a spreadsheet, a format we have decided not to support.
     * Closing it records that somebody looked and there was nothing to record - and the copy of the
     * customer's file goes, because the only reason to hold it was to reproduce something.
     */
    seedMasterMaterials();
    config(['openai.key' => null]);
    Http::fake();
    Notification::fake();

    $user = customerWithNoTemplates();
    $admin = createUser(1, createBusiness('platform'), true, true);

    uploadToNewProject($user, learningUpload());

    $attempt = TemplateLearningAttempt::query()->sole();

    expect($attempt->hasSample())->toBeTrue();

    $this->actingAs($admin)
        ->post(route('admin.businesses.template.attempts.resolve', [$user->business_id, $attempt->id]))
        ->assertSessionHas('success');

    $attempt->refresh();

    expect($attempt->resolved_at)->not->toBeNull()
        ->and($attempt->resolved_by_user_id)->toBe($admin->id)
        ->and($attempt->template_id)->toBeNull()
        ->and($attempt->sample_path)->toBeNull();
});

it('prunes the spreadsheets of attempts nobody picked up, and keeps the account of them', function () {
    /*
     * The file is held so a failure can be reproduced, and that reason expires: an attempt nobody has
     * opened in a month is not about to be opened, and what is sitting there is a customer's project
     * data. The row survives and still says what went wrong; it just holds none of their data.
     */
    seedMasterMaterials();
    config(['openai.key' => null, 'templates.learning.sample_retention_days' => 30]);
    Http::fake();
    Notification::fake();

    $user = customerWithNoTemplates();

    uploadToNewProject($user, learningUpload());

    $attempt = TemplateLearningAttempt::query()->sole();

    expect($attempt->hasSample())->toBeTrue();

    //Still inside the window
    $this->artisan('templates:prune-samples')->assertSuccessful();

    expect($attempt->fresh()->hasSample())->toBeTrue();

    $attempt->forceFill(['created_at' => now()->subDays(31)])->save();

    $this->artisan('templates:prune-samples')->assertSuccessful();

    $attempt = $attempt->fresh();

    expect($attempt->sample_path)->toBeNull()
        //The reason it failed is not a customer's data, and is the only account of it
        ->and($attempt->headline)->not->toBeEmpty()
        ->and($attempt->resolved_at)->toBeNull();
});

it('records that a person read a machine-written template without changing anything else', function () {
    /*
     * The flag is an audit of what is live and unread, not a gate: the template is already matched
     * against uploads and marking it reviewed must not be the thing that switches it on, or we are
     * back to a customer waiting on an admin.
     */
    $business = createBusiness('newfabricator');
    $admin = createUser(1, createBusiness('platform'), true, true);

    $template = $business->templates()->create([
        ...collect((new Tests\Support\ExampleTemplates)->definitions())->firstWhere('name', 'Assembly List'),
        'generated_by_ai' => true,
        'reviewed_at' => null,
    ]);

    expect(Template::query()->awaitingReview()->count())->toBe(1);

    $this->actingAs($admin)
        ->post(route('admin.businesses.templates.reviewed', [$business->id, $template->id]))
        ->assertSessionHas('success');

    $template->refresh();

    expect($template->reviewed_at)->not->toBeNull()
        ->and($template->reviewed_by_user_id)->toBe($admin->id)
        //Untouched: reviewing says somebody looked, and nothing else
        ->and($template->active)->toBeTrue()
        ->and($template->generated_by_ai)->toBeTrue()
        ->and(Template::query()->awaitingReview()->count())->toBe(0);
});

it('would be a disaster if a customer could mark their own templates reviewed', function () {
    $business = createBusiness('newfabricator');
    $user = createUser(2, $business, false, true);

    $template = $business->templates()->create([
        ...collect((new Tests\Support\ExampleTemplates)->definitions())->firstWhere('name', 'Assembly List'),
        'generated_by_ai' => true,
        'reviewed_at' => null,
    ]);

    $this->actingAs($user)
        ->post(route('admin.businesses.templates.reviewed', [$business->id, $template->id]))
        ->assertRedirect('/');

    expect($template->fresh()->reviewed_at)->toBeNull();
});

it('shows an admin the attempts and the templates nobody has read', function () {
    /*
     * The admin side of the whole thing, in the props the screen draws from: the work that is left,
     * and the templates in production that no human has looked at.
     */
    seedMasterMaterials();
    config(['openai.key' => null]);
    Http::fake();
    Notification::fake();

    $user = customerWithNoTemplates();
    $admin = createUser(1, createBusiness('platform'), true, true);

    uploadToNewProject($user, learningUpload());

    $user->business->templates()->create([
        ...collect((new Tests\Support\ExampleTemplates)->definitions())->firstWhere('name', 'Project Quote'),
        'generated_by_ai' => true,
        'reviewed_at' => null,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.businesses.templates.index', $user->business_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('attempts', 1)
            ->where('attempts.0.file_name', 'tekla_assembly_list.xlsx')
            ->where('attempts.0.outcome', 'UNREADABLE')
            ->where('attempts.0.has_sample', true)
            ->where('attempts.0.uploaded_by', $user->email)
            ->has('templates', 1)
            ->where('templates.0.awaiting_review', true)
        );
});
