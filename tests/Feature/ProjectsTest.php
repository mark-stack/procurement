<?php

use App\Enums\GradeEnums;
use App\Enums\MaterialEnums;
use App\Models\Batch;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * The "has the project been awarded to you?" reminder, stored rather than sent - the real one
 * builds a magic link and a mail message, and none of that is what these tests are asking about.
 */
function projectAwardedNotification(User $user, Project $project): DatabaseNotification
{
    return DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\ProjectAwardedCheckEmail',
        'notifiable_type' => 'App\Models\User',
        'notifiable_id' => $user->id,
        'data' => [
            'project_id' => $project->id,
            'project_name' => $project->name,
        ],
        'read_at' => null,
    ]);
}

it('would be a disaster if a past materials date locked a project out of editing', function () {
    /**
     * The modal posts the whole project back, so a project whose materials date had
     * already passed resubmitted that past date into "after:today" and failed. The
     * date field isn't even rendered, so the user saw nothing happen at all.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $project->update(['date_materials_required' => now()->subMonth()->toDateString()]);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $project->id), [
            'name' => 'Renamed project',
            'reference' => $project->reference,
            'date_materials_required' => $project->date_materials_required,
        ]);

    $response->assertValid();
    expect($project->fresh()->name)->toBe('Renamed project');
});

it('would be a disaster if a new materials date could be set in the past', function () {
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $project->id), [
            'name' => $project->name,
            'reference' => $project->reference,
            'date_materials_required' => now()->subWeek()->toDateString(),
        ]);

    $response->assertInvalid('date_materials_required');
});

it('would be a disaster if a name freed up by marking a project done stayed unreachable', function () {
    /**
     * Creating a project only checks the names of current projects, so marking one done
     * one frees its name. Renaming checked every project the business had ever
     * had, and refused under a message about "currently active projects".
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $doneProject = createProject($user);
    $doneProject->update(['name' => 'Retired name', 'done' => true]);

    $project = createProject($user);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $project->id), [
            'name' => 'Retired name',
            'reference' => $project->reference,
            'date_materials_required' => $project->date_materials_required,
        ]);

    $response->assertValid();
    expect($project->fresh()->name)->toBe('Retired name');
});

it('would be a disaster if a project could be renamed onto another current project', function () {
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $taken = createProject($user);
    $taken->update(['name' => 'Already taken']);

    $project = createProject($user);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $project->id), [
            'name' => 'Already taken',
            'reference' => $project->reference,
            'date_materials_required' => $project->date_materials_required,
        ]);

    $response->assertInvalid('name');
});

it('would be a disaster if a project could keep its own name only by accident', function () {
    //Saving any other change resubmits the current name, which must not clash with itself
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $project->id), [
            'name' => $project->name,
            'reference' => 'a new reference',
            'date_materials_required' => $project->date_materials_required,
        ]);

    $response->assertValid();
    expect($project->fresh()->reference)->toBe('a new reference');
});

it('would be a disaster if user could edit another business’s projects', function () {
    /**
     * Validation used to run first, so another business's project was checked for
     * name clashes - and told the caller about them - before anything refused it.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $otherBusiness = createBusiness('outlook');
    $otherUser = createUser(2, $otherBusiness, false, true);
    $otherProject = createProject($otherUser);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $otherProject->id), [
            'name' => 'Taken over',
        ]);

    $response->assertForbidden();
    expect($otherProject->fresh()->name)->not->toBe('Taken over');
});

it('would be a disaster if user could edit other staff projects', function () {
    /**
     * The test of this name used to assert against another BUSINESS, which the gate already
     * refused - so what it was named for went uncovered. ProjectPolicy asks only whether a
     * project belongs to your business, and the Nesting column is shared, so every colleague's
     * card carried a live Edit button beside a greyed-out Done one.
     *
     * Edit is not the smaller of the two: the name is how the rest of the business recognises
     * the project on the board and in Past Batches, and date_materials_required drives the
     * critical path and every deadline reminder its owner is sent.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $colleaguesProject = createProject($colleague);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $colleaguesProject->id), [
            'name' => 'Taken over',
            'reference' => 'renamed',
        ]);

    $response->assertForbidden();
    expect($colleaguesProject->fresh()->name)->not->toBe('Taken over');
});

it('would be a disaster if a user could not edit their own project', function () {
    /**
     * The other half of the rule above - the owner-only check runs in the form request, before
     * validation, so getting it wrong would lock everybody out rather than just colleagues.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $project->id), [
            'name' => $project->name,
            'reference' => 'my own reference',
        ]);

    $response->assertValid();
    expect($project->fresh()->reference)->toBe('my own reference');
});

it('would be a disaster if user could see other business’s projects', function () {
    /**
     * Declared as a placeholder since the file was written, and the coverage audit found nothing
     * standing behind it: every cross-business test here asks whether a write is refused, and none
     * asks what a page draws. /nesting is the screen that shows the whole business's work rather
     * than the caller's own, so a scoping mistake there puts another company's projects and batches
     * in front of you rather than merely letting you write to them.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $otherBusiness = createBusiness('outlook');
    $otherUser = createUser(2, $otherBusiness, false, true);

    $mine = createProject($user);
    pieceReadyForBatching($mine);

    $theirs = createProject($otherUser);
    pieceReadyForBatching($theirs);

    //And one of theirs already nested, so the batch columns are asked the same question
    $theirBatch = Batch::factory()->forUser($otherUser->id)->create(['done' => false]);
    pieceOnBatch(createProject($otherUser), $theirBatch);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            //The open batch card, which is what "Start quoting" would sweep in
            ->has('batches.0.projects', 1)
            ->where('batches.0.projects.0.id', $mine->id)
            //And their nested batch is not drawn at all - the open card is the only one on the page
            ->has('batches', 1)
            ->etc()
        );
});

it('would be a disaster if user cannot see other staff materials', function () {});

it('would be a disaster if user could create projects before email verification', function () {
    /*
     * The other placeholder the audit found nothing behind. Everything downstream of a project
     * assumes a confirmed address - the deadline reminders, the welcome mail, the magic link in the
     * "was it awarded?" notification - and the route group's own 'verified' is the only thing
     * holding it.
     */
    $business = createBusiness('gmail');
    $unverified = createUser(1, $business, false, false);

    $this->actingAs($unverified)
        ->from('/dashboard')
        ->post(route('projects.store'), [
            'name' => 'Too early',
            'date_fabrication_begins' => now()->addMonth()->toDateString(),
            'tentative' => false,
            'excel' => [],
        ])
        ->assertRedirect(route('verification.notice'));

    expect(Project::count())->toBe(0);
});

it('would be a disaster if a business with no templates yet were turned away at the door', function () {
    /*
     * This test asserted the opposite until templates became self-service, and the reasoning was sound
     * at the time: without a template recorded for the business a BOM auto-detects nothing, so the
     * project would be created and handed straight back empty. BusinessReadyMiddleware held project
     * creation for exactly that reason, and redirected to onboarding.
     *
     * Uploading is now the first step of setting the business up - the file is what writes the
     * template, see TemplateLearningService - so nothing may stand between a new customer and the
     * form. The request reaches validation, which is as far as a submit with no files should get;
     * whether a file then imports is TemplateLearningTest's subject.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    expect($business->detectableTemplates()->count())->toBe(0);

    $this->actingAs($user)
        ->post(route('projects.store'), [
            'name' => 'First one',
            'date_fabrication_begins' => now()->addMonth()->toDateString(),
            'tentative' => false,
            'excel' => [],
        ])
        //Its own validation rule, not a gate in front of the route
        ->assertInvalid('excel');

    expect(Project::count())->toBe(0);
});

it('would be a disaster if user could mark other staff projects done', function () {
    /**
     * The gate only asks whether a project belongs to your business, and the Nesting column
     * is shared - so every colleague's project carried a live Done button. The done
     * list it is reopened from is filtered to your own projects, so retiring a colleague's
     * project took it off the board with no way back for anyone but them.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $colleaguesProject = createProject($colleague);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->delete(route('projects.destroy', $colleaguesProject->id));

    $response->assertForbidden();
    expect($colleaguesProject->fresh()->done)->toBeFalsy();
});

it('would be a disaster if user could mark a project done with active quotes and orders', function () {
    /**
     * Marking a nested project done fails undoStartQuoting condition 3, so its batch can never
     * be re-nested again - and the tooltip that says so names a done project the rest
     * of the business cannot see. The button hid itself outside the Nesting column; nothing
     * on the server did.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    pieceOnBatch($project, $batch);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->delete(route('projects.destroy', $project->id));

    $response->assertForbidden();
    expect($project->fresh()->done)->toBeFalsy();
});

it('lets a finished project be marked done so its name comes back', function () {
    /**
     * Nesting writes a batch_id onto the pieces and nothing ever clears it, so markProjectDone's
     * old "no batch exists for this project" condition could never pass again once a job had been
     * through. Both project forms say "ones marked done are free to reuse" - and the one kind of
     * project whose name you actually want back was the one kind that could never be marked done to
     * free it. "Move to done" is a one-way door off the board, so the tooltip's advice to re-nest
     * the batch first led nowhere either.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => true]);
    pieceOnBatch($project, $batch);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->delete(route('projects.destroy', $project->id));

    $response->assertValid();
    expect($project->fresh()->done)->toBeTruthy();
});

it('would be a disaster if a finished batch let a project off a live one be marked done', function () {
    /**
     * A project collects material over time - see the pending card, which counts the pieces added
     * since the last batch - so it can be on a closed batch and a live one at once. Only the live
     * one decides: retiring it out from under a batch is what leaves a batch un-re-nestable
     * (undoStartQuoting condition 3) and invisible to everyone but the owner.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    pieceOnBatch($project, Batch::factory()->forUser($user->id)->create(['done' => true]));
    pieceOnBatch($project, Batch::factory()->forUser($user->id)->create(['done' => false]));

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->delete(route('projects.destroy', $project->id));

    $response->assertForbidden();
    expect($project->fresh()->done)->toBeFalsy();
});

it('does not let a finished project hold on to its name', function () {
    /**
     * The uniqueness check reads projects.done, which is the owner's own filing of a job and stays
     * false until somebody sets it by hand. So a job that went all the way through - quoted,
     * ordered, delivered, its batch closed - kept its name, and the only way to get it back was to
     * go and retire a project the board stopped drawing when the batch was marked done. The name is
     * let through here and the old project is retired on the way out of ProjectController::store.
     *
     * Only the name is being asserted on - the empty upload is still refused, which is the point:
     * nothing is retired by a request that goes no further than validation.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $finished = createProject($user);
    $finished->update(['name' => 'Tower A']);
    pieceOnBatch($finished, Batch::factory()->forUser($user->id)->create(['done' => true]));

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('projects.store'), [
            'name' => 'Tower A',
            'date_fabrication_begins' => now()->addMonth()->toDateString(),
            'tentative' => false,
            'excel' => [],
        ]);

    $response->assertValid('name');
    expect($finished->fresh()->done)->toBeFalsy();
});

it('would be a disaster if a live project could have its name taken', function () {
    /**
     * Only a job that is over gives its name up. A project still being worked on is drawn on every
     * colleague's board under that name, and nothing downstream - the cut drawings, the BOM
     * download, every notification - could tell two of them apart.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $live = createProject($user);
    $live->update(['name' => 'Tower A']);
    pieceOnBatch($live, Batch::factory()->forUser($user->id)->create(['done' => false]));

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('projects.store'), [
            'name' => 'Tower A',
            'date_fabrication_begins' => now()->addMonth()->toDateString(),
            'tentative' => false,
            'excel' => [],
        ]);

    $response->assertInvalid('name');
});

it('would be a disaster if a colleague’s finished project were retired to free its name', function () {
    /**
     * Freeing the name retires the project holding it, and only the owner may retire a project
     * (markProjectDone condition 2). Taking a colleague's name would file their job away for them,
     * on nothing but somebody else typing it - so they get the plain rejection and pick another.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $theirs = createProject($colleague);
    $theirs->update(['name' => 'Tower A']);
    pieceOnBatch($theirs, Batch::factory()->forUser($colleague->id)->create(['done' => true]));

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('projects.store'), [
            'name' => 'Tower A',
            'date_fabrication_begins' => now()->addMonth()->toDateString(),
            'tentative' => false,
            'excel' => [],
        ]);

    $response->assertInvalid('name');
    expect($theirs->fresh()->done)->toBeFalsy();
});

it('would be a disaster if a project marked done before nesting could not be reopened', function () {
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);

    $this->actingAs($user)->from('/dashboard')->delete(route('projects.destroy', $project->id));
    expect($project->fresh()->done)->toBeTruthy();

    $this->actingAs($user)->from('/dashboard')->delete(route('projects.destroy', $project->id));
    expect($project->fresh()->done)->toBeFalsy();
});

it('would be a disaster if a project marked done while nested could not be reopened', function () {
    /**
     * Reopen asks for less than marking done does. A project that reached a batch while done -
     * "Lost it" on a notification used to do exactly that - is the one holding its batch back,
     * so refusing to restore it would leave the batch stuck for good.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $project->update(['done' => true]);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    pieceOnBatch($project, $batch);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->delete(route('projects.destroy', $project->id));

    $response->assertRedirect();
    expect($project->fresh()->done)->toBeFalsy();
});

it('would be a disaster if restoring a project put two of the same name on the board', function () {
    /**
     * Both project forms deliberately exclude the names of done projects from their uniqueness check, and say
     * so: "ones marked done are free to reuse". Nothing then looked at the name on the way back in, so
     * the whole sequence is legal - mark "Tower A" done, give the freed name to a new project, reopen
     * the old one - and it ends with two live projects called "Tower A" in the shared Nesting column
     * for two different jobs.
     *
     * Nothing downstream can tell them apart for a person: two identical cards, the name twice in
     * Past Batches, and a colleague pressing "Start quoting" nests both into one batch whose spec
     * sheet, BOM download and notifications all name "Tower A". Steel gets cut for the wrong one.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $doneProject = createProject($user);
    $doneProject->update(['name' => 'Tower A', 'done' => true]);

    //Allowed, and meant to be - the name is free while the other project is done
    $live = createProject($user);
    $live->update(['name' => 'Tower A']);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->delete(route('projects.destroy', $doneProject->id));

    //A reason rather than a 403: the owner can rename the done project and try again
    $response->assertInvalid('done');
    expect($doneProject->fresh()->done)->toBeTruthy()
        ->and(Project::query()->where('done', false)->where('name', 'Tower A')->count())->toBe(1);
});

it('lets a renamed project be restored once its old name is taken', function () {
    //The other half of the rule above - refusing has to leave a way through, not a dead end
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $doneProject = createProject($user);
    $doneProject->update(['name' => 'Tower A', 'done' => true]);

    $live = createProject($user);
    $live->update(['name' => 'Tower A']);

    //Renaming a done project is allowed - editProject does not ask whether it is
    $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $doneProject->id), [
            'name' => 'Tower A (2024)',
            'reference' => $doneProject->reference,
            'date_materials_required' => $doneProject->date_materials_required,
        ])
        ->assertValid();

    $this->actingAs($user)
        ->from('/dashboard')
        ->delete(route('projects.destroy', $doneProject->id))
        ->assertRedirect();

    expect($doneProject->fresh()->done)->toBeFalsy();
});

it('would be a disaster if a colleague’s project blocked a restore invisibly', function () {
    /*
     * The clash is measured across the whole business, because the board is - so a colleague
     * holding the name blocks the restore too. What matters is that it is refused with the reason
     * rather than silently, since renaming the colleague's project is not something this user can do.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $doneProject = createProject($user);
    $doneProject->update(['name' => 'Shared name', 'done' => true]);

    $theirs = createProject($colleague);
    $theirs->update(['name' => 'Shared name']);

    $this->actingAs($user)
        ->from('/dashboard')
        ->delete(route('projects.destroy', $doneProject->id))
        ->assertInvalid('done');

    expect($doneProject->fresh()->done)->toBeTruthy();
});

it('would be a disaster if a project name had no length at all', function () {
    /**
     * projects.name is a TEXT column and the rules were "required|string" with no max, so anything
     * posting straight at the route could put an essay on every colleague's board. The card
     * truncates it, but the confirm dialogs do not - they build their message by joining the names
     * of every project involved, which is how "Start quoting" names whose work is being taken - so
     * one pasted wall of text makes a dialog nobody can read or reach the button of.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', createProject($user)->id), [
            'name' => str_repeat('A', Project::MAX_NAME_CHARACTERS + 1),
        ])
        ->assertInvalid('name');
});

it('would be a disaster if a project could be named nothing but spaces', function () {
    /**
     * "required" counts a string of spaces as filled. The modal trims the name on its way out for
     * exactly this reason, and that was the only place it happened - so a project posted straight at
     * the route was saved under a name that is blank everywhere it is displayed, on a board where
     * the name is the only thing identifying it.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', createProject($user)->id), [
            'name' => '     ',
        ])
        ->assertInvalid('name');
});

it('would be a disaster if a project reference could be anything at all', function () {
    /**
     * "reference" was validated as "nullable" and nothing else, while the column is a varchar(255) -
     * so an array reached Project::create and answered with a 500, and 300 characters would have
     * been a database error on MySQL. The modal never renders the field, so the route is the only
     * way in and there was nothing on it.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $project->id), [
            'name' => $project->name,
            'reference' => ['an', 'array'],
        ])
        ->assertInvalid('reference');

    $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $project->id), [
            'name' => $project->name,
            'reference' => str_repeat('R', Project::MAX_REFERENCE_CHARACTERS + 1),
        ])
        ->assertInvalid('reference');
});

it('would be a disaster if marking a project done left its reminders in the bell', function () {
    /**
     * Each notification clears itself when the question it asks is answered, and none of them
     * counts it as an answer - so a done project kept asking whether it had been
     * awarded, from a board it no longer appears on.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $notification = projectAwardedNotification($user, $project);

    $this->actingAs($user)->from('/dashboard')->delete(route('projects.destroy', $project->id));

    expect($project->fresh()->done)->toBeTruthy()
        ->and($notification->fresh()->read_at)->not->toBeNull();
});

it('would be a disaster if a notification id was enough to retire someone else’s project', function () {
    /**
     * The status route looked the notification up by id alone, and the red action on this one
     * marks done the project it names - so an id, from any account, retired another business's
     * project.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $otherBusiness = createBusiness('outlook');
    $otherUser = createUser(2, $otherBusiness, false, true);
    $otherProject = createProject($otherUser);
    $notification = projectAwardedNotification($otherUser, $otherProject);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('mark.notification.status'), [
            'id' => $notification->id,
            'status' => 'RED',
        ]);

    $response->assertNotFound();
    expect($otherProject->fresh()->done)->toBeFalsy();
});

it('would be a disaster if "Lost it" retired a project that is already nested', function () {
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $notification = projectAwardedNotification($user, $project);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    pieceOnBatch($project, $batch);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('mark.notification.status'), [
            'id' => $notification->id,
            'status' => 'RED',
        ]);

    $response->assertRedirect();
    expect($project->fresh()->done)->toBeFalsy();
});

it('puts your own projects first in the shared nesting column', function () {
    /**
     * The column draws the whole business's work as one pile, in creation order, so on a board
     * with a few colleagues on it your own project was wherever it happened to land. The three
     * batch columns already sort this way through BatchService::sortByUserAndLatest; only the
     * column of projects did not.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    //Theirs first, so creation order alone would put it at the top
    $theirs = createProject($colleague);
    pieceReadyForBatching($theirs);

    $mine = createProject($user);
    pieceReadyForBatching($mine);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('batches.0.projects.0.id', $mine->id)
            ->where('batches.0.projects.1.id', $theirs->id)
            ->etc()
        );
});

it('would be a disaster if the hourly checks could not ask for active projects', function () {
    /**
     * All four hourly notification checks open with Project::query()->active(), and the scope
     * did not exist - so each of them died on a BadMethodCallException as soon as the job ran.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $active = createProject($user);
    $doneProject = createProject($user);
    $doneProject->update(['done' => true]);

    $ids = Project::query()->active()->pluck('id');

    expect($ids)->toContain($active->id)
        ->and($ids)->not->toContain($doneProject->id);
});

//todo more

/**
 * The edit modal's payload for a project, with whatever is being changed overridden.
 *
 * Written out rather than built field by field in each test because the modal posts the whole
 * project back on every edit - a rename carries the dates with it - and that is exactly the shape
 * the date rules have to hold against.
 */
function editProjectPayload(Project $project, array $changes = []): array
{
    return array_merge([
        'name' => $project->name,
        'reference' => $project->reference,
        'date_materials_required' => $project->date_materials_required,
        'date_fabrication_begins' => $project->date_fabrication_begins
            ? substr((string) $project->date_fabrication_begins, 0, 10)
            : null,
    ], $changes);
}

it('would be a disaster if a fabrication date could be deleted off a project that has one', function () {
    /**
     * The date is required to create a project and was nullable to edit one, so it could be emptied -
     * and emptying it is not a correction, it is the one edit that silences everything built on it.
     * The card loses its required-by pill, its critical path and the "Action required" footer; the
     * fabrication deadline warning stops selecting the project; and the two deadline reminders stop
     * considering it. A red card could be cleared by deleting the field that made it red.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $project->update(['date_fabrication_begins' => now()->addDays(10)->toDateString()]);
    $project = $project->fresh();

    $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $project->id), editProjectPayload($project, [
            'date_fabrication_begins' => null,
        ]))
        ->assertInvalid(['date_fabrication_begins']);

    expect($project->fresh()->date_fabrication_begins)->not->toBeNull();
});

it('lets an older project with no fabrication date be renamed without inventing one', function () {
    /**
     * The other side of the rule above. Projects created before the date was asked for carry none
     * (see the add_date_fabrication_begins migration), and their owners must still be able to rename
     * them - and to give them a date later - rather than being held at a field they cannot fill in
     * honestly.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $project->update(['date_fabrication_begins' => null]);
    $project = $project->fresh();

    $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $project->id), editProjectPayload($project, [
            'name' => 'Renamed project',
        ]))
        ->assertValid();

    expect($project->fresh()->name)->toBe('Renamed project');
});

it('would be a disaster if steel already in the rack could be re-dated', function () {
    /**
     * Nothing asked where a project had got to before letting its fabrication date move, so a job on
     * a delivered batch accepted a date two years in the past. The card goes on printing a
     * required-by date for a delivered batch, so the page would be showing steel that is in the rack
     * as having been wanted on a day it was not - the one reading of this edit that cannot be undone
     * by making the next decision differently.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $project->update(['date_fabrication_begins' => now()->addDays(10)->toDateString()]);
    $project = $project->fresh();

    pieceOnBatch($project, Batch::factory()->forUser($user->id)->create([
        'done' => false,
        'delivered_at' => now(),
    ]));

    $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $project->id), editProjectPayload($project, [
            'date_fabrication_begins' => now()->subYears(2)->toDateString(),
        ]))
        ->assertInvalid(['date_fabrication_begins']);

    expect($project->fresh()->date_fabrication_begins)
        ->toContain(now()->addDays(10)->toDateString());

    //And the rest of the form still saves - a job finishing is no reason to be stuck with a typo
    $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $project->id), editProjectPayload($project, [
            'name' => 'Renamed after delivery',
        ]))
        ->assertValid();

    expect($project->fresh()->name)->toBe('Renamed after delivery');
});

it('lets a slipping job be re-dated while its steel is still being bought', function () {
    /**
     * The other side of that rule, and the ordinary case: jobs slip, and while the batch is still
     * being quoted or bought the page has to say so. Only a batch with nothing left outstanding
     * settles the date.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $project->update(['date_fabrication_begins' => now()->addDays(10)->toDateString()]);
    $project = $project->fresh();

    pieceOnBatch($project, Batch::factory()->forUser($user->id)->create(['done' => false]));

    $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $project->id), editProjectPayload($project, [
            'date_fabrication_begins' => now()->addDays(20)->toDateString(),
        ]))
        ->assertValid();

    expect($project->fresh()->date_fabrication_begins)
        ->toContain(now()->addDays(20)->toDateString());
});

it('records who moved a fabrication date, and what it was before', function () {
    /**
     * The date every deadline on the job is counted back from stays editable after the steel has
     * been quoted and bought, which is exactly when a change to it is worth being able to account
     * for. The column is overwritten in place and the page derives its dates at read time, so
     * without a log the old deadline is nowhere at all. Order, Piece, Product, Template and
     * MaterialCertificate have recorded their changes for a while; the project did not.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $project->update(['date_fabrication_begins' => now()->addDays(10)->toDateString()]);
    $project = $project->fresh();

    $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $project->id), editProjectPayload($project, [
            'date_fabrication_begins' => now()->addDays(20)->toDateString(),
        ]))
        ->assertValid();

    $change = App\Models\RecordChange::query()
        ->where('record_type', $project->getMorphClass())
        ->where('record_id', $project->id)
        ->where('event', App\Models\RecordChange::UPDATED)
        ->latest('id')
        ->first();

    expect($change)->not->toBeNull()
        ->and($change->user_id)->toBe($user->id)
        ->and($change->changes)->toHaveKey('date_fabrication_begins')
        ->and($change->changes['date_fabrication_begins'][0])->toContain(now()->addDays(10)->toDateString())
        ->and($change->changes['date_fabrication_begins'][1])->toContain(now()->addDays(20)->toDateString());
});

it('tells the other managers on a batch when somebody re-dates it', function () {
    /**
     * A batch is bought as one, so the day its steel is wanted is the earliest fabrication date among
     * the jobs on it - which means one manager moving their own date moves the deadline every
     * colleague's card is coloured against, and the red "Action required" footer with it. Nothing
     * told them: they would find out by noticing the batch had gone red, with no way to see why, when
     * or who. The two batch actions a colleague can take are already reported; this is the third, and
     * the only one that needs no button pressed.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $mine = createProject($user);
    $mine->update(['name' => 'mine', 'date_fabrication_begins' => now()->addDays(30)->toDateString()]);
    $mine = $mine->fresh();

    $theirs = createProject($colleague);
    $theirs->update(['name' => 'theirs', 'date_fabrication_begins' => now()->addDays(30)->toDateString()]);

    $batch = Batch::factory()->forUser($colleague->id)->create(['done' => false]);
    pieceOnBatch($mine, $batch);
    pieceOnBatch($theirs->fresh(), $batch);

    $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $mine->id), editProjectPayload($mine, [
            'date_fabrication_begins' => now()->addDays(10)->toDateString(),
        ]))
        ->assertValid();

    $told = $colleague->fresh()->unreadNotifications()
        ->where('type', App\Notifications\ColleagueMovedMaterialsDate::class)
        ->first();

    expect($told)->not->toBeNull()
        //Their own job is what the message is about, not the one that was edited
        ->and($told->data['project_id'])->toBe($theirs->id)
        ->and($told->data['colleague_name'])->toBe($user->name)
        //And the news is the batch's new day, not the date the colleague typed
        ->and($told->data['required_by'])->toBe(
            Project::materialsRequiredDate(now()->addDays(10)->toDateString())->toDateString()
        );

    //Nobody tells you about your own edit
    expect($user->fresh()->unreadNotifications()
        ->where('type', App\Notifications\ColleagueMovedMaterialsDate::class)
        ->exists())->toBeFalse();
});

it('says nothing when a re-dated job does not move its batch’s day', function () {
    /**
     * The batch's day is the earliest of the jobs on it, so moving a later job's date further out
     * changes nothing anybody has to act on - and a bell that reports every edit regardless is a bell
     * people stop reading. Renames are the same: they move no date at all.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $mine = createProject($user);
    $mine->update(['name' => 'mine', 'date_fabrication_begins' => now()->addDays(30)->toDateString()]);
    $mine = $mine->fresh();

    //Earlier, so it is this one that sets the batch's day whatever happens to the other
    $theirs = createProject($colleague);
    $theirs->update(['name' => 'theirs', 'date_fabrication_begins' => now()->addDays(10)->toDateString()]);

    $batch = Batch::factory()->forUser($colleague->id)->create(['done' => false]);
    pieceOnBatch($mine, $batch);
    pieceOnBatch($theirs->fresh(), $batch);

    $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $mine->id), editProjectPayload($mine, [
            'date_fabrication_begins' => now()->addDays(40)->toDateString(),
        ]))
        ->assertValid();

    expect($colleague->fresh()->unreadNotifications()
        ->where('type', App\Notifications\ColleagueMovedMaterialsDate::class)
        ->exists())->toBeFalse();
});
