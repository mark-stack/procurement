<?php

use App\Models\Batch;
use App\Models\Piece;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * /dashboard, which used to redirect at the projects board and is now the page that takes a
 * material list - either into a new project or into one that has not been nested yet.
 *
 * The two uploads themselves are covered by ProjectUploadTest and the product upload tests; what is
 * asserted here is the one thing this page decides on its own, which is which projects it offers as
 * targets. Offering one the upload gate would refuse is offering a 403.
 */
function eligibleProjectIds($response): array
{
    return collect($response->viewData('page')['props']['eligibleProjects'])
        ->pluck('id')
        ->all();
}

it('renders the upload page rather than redirecting at the board', function () {
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('MaterialListUpload')
            ->has('eligibleProjects')
        );
});

it('offers an un-nested project of your own to upload into', function () {
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);

    //An un-nested piece, which is what the Nesting column draws a card from
    Piece::factory()->create(['project_id' => $project->id]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    expect(eligibleProjectIds($response))->toBe([$project->id]);
});

it('would be a disaster if the page offered a project the upload gate refuses', function () {
    /*
     * Three of them, each refused by PrerequisiteConditions::uploadMaterials for its own reason. A
     * project in this dropdown is a project the user picks, attaches a file to, waits on, and is
     * then answered with a 403 - having been told nothing about why the one they chose was never a
     * candidate.
     */
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    //Archived: past the point where its BOM means anything
    $archived = createProject($user);
    $archived->update(['archive' => true]);
    Piece::factory()->create(['project_id' => $archived->id]);

    //Nested into a batch: the saw is working from this, so the list cannot grow
    $batched = Project::create([
        'name' => 'already nested',
        'user_id' => $user->id,
        'tentative' => false,
        'archive' => false,
    ]);
    $batch = Batch::create(['user_id' => $user->id]);
    Piece::factory()->create(['project_id' => $batched->id, 'batch_id' => $batch->id]);

    //A colleague's, which only they may upload into - see the multi-staff ownership rules
    $theirs = createProject($colleague);
    Piece::factory()->create(['project_id' => $theirs->id]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    expect(eligibleProjectIds($response))->toBe([]);
});

it('still offers a project whose import stopped at a clarification', function () {
    /*
     * A partial price book match keeps a project out of Business::projectsReadyForBatching(), which
     * is why the board draws it as an unfinished import instead of a card. It is still a live,
     * un-nested project of the user's own, and uploadMaterials says yes to it - so leaving it out
     * here would hide a project the user can see from the only screen that can add to it.
     *
     * Asserted through a project with material rows and no pieces at all, which is the shape an
     * import that stopped leaves behind: nothing was nested, so nothing blocks an upload.
     */
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    $stuck = createProject($user);

    $response = $this->actingAs($user)->get(route('dashboard'));

    expect(eligibleProjectIds($response))->toBe([$stuck->id]);
});

it('says how many material lines a project already has, so an upload is not added to the wrong one', function () {
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    Piece::factory()->count(2)->create(['project_id' => $project->id]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $offered = $response->viewData('page')['props']['eligibleProjects'];

    expect($offered)->toHaveCount(1)
        //One raw material quote per piece, built by the factory
        ->and($offered[0]['rows'])->toBe(2)
        ->and($offered[0]['name'])->toBe($project->name);
});

it('offers the colleagues a new project can be created for', function () {
    /*
     * The draftsman uploading for a project manager. There is no staff list and no roles - a business
     * is every user whose email domain matched at registration - so every colleague is offered, and
     * the uploader is not among them: the select's own default is "me".
     */
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    //Another business's staff, who would be a stranger holding one of this company's jobs
    createUser(3, createBusiness('othersteel'), false, true);

    $response = $this->actingAs($user)->get(route('dashboard'));

    expect(collect($response->viewData('page')['props']['colleagues'])->pluck('id')->all())
        ->toBe([$colleague->id]);
});

it('asks nobody anything on a one person business', function () {
    //Most of them. The select is not drawn at all, so the page is exactly what it was.
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    $response = $this->actingAs($user)->get(route('dashboard'));

    expect($response->viewData('page')['props']['colleagues'])->toBe([]);
});

it('offers a colleague’s project you uploaded the first material list for', function () {
    /*
     * The other half of uploading on somebody's behalf. A job's materials do not arrive in one file on
     * one day, and the second file reaches the draftsman the first one did - so the project they
     * created for a manager has to be in the dropdown, or they have no way to finish what they
     * started. PrerequisiteConditions::uploadMaterials says yes to it, which is the only test this
     * list is allowed to disagree with.
     */
    $business = createBusiness('acmesteel');
    $draftsman = createUser(1, $business, false, true);
    $projectManager = createUser(2, $business, false, true);

    $theirs = createProject($projectManager);
    $theirs->update(['created_by_user_id' => $draftsman->id]);
    Piece::factory()->create(['project_id' => $theirs->id]);

    $response = $this->actingAs($draftsman)->get(route('dashboard'));

    $offered = $response->viewData('page')['props']['eligibleProjects'];

    expect(eligibleProjectIds($response))->toBe([$theirs->id])
        /*
         * Named, because the dropdown now mixes your own jobs with other people's. Two similarly
         * named projects belonging to different managers is how one manager's steel ends up on
         * another's cutting list.
         */
        ->and($offered[0]['projectManagerName'])->toBe($projectManager->name);
});

it('does not name a manager on your own projects', function () {
    //Nothing to disambiguate, and "Tower A - your name's" on every row is noise
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    Piece::factory()->create(['project_id' => $project->id]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    expect($response->viewData('page')['props']['eligibleProjects'][0]['projectManagerName'])->toBeNull();
});

it('shows a test mode account only its own projects', function () {
    /*
     * The sandbox is a global scope on the model, so this needs no code of its own - but the
     * dropdown is a new way into project data, and a user who cannot tell which set they are
     * looking at is the one failure test mode must not have.
     */
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    $real = createProject($user);
    Piece::factory()->create(['project_id' => $real->id]);

    $user->sandbox_mode = true;
    $user->save();

    $response = $this->actingAs($user->fresh())->get(route('dashboard'));

    expect(eligibleProjectIds($response))->toBe([]);
});
