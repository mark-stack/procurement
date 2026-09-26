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
    $business = createBusiness('gmail', true);
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
    $business = createBusiness('gmail', true);
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

it('would be a disaster if a name freed up by archiving stayed unreachable', function () {
    /**
     * Creating a project only checks the names of current projects, so archiving
     * one frees its name. Renaming checked every project the business had ever
     * had, and refused under a message about "currently active projects".
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $archived = createProject($user);
    $archived->update(['name' => 'Retired name', 'archive' => true]);

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
    $business = createBusiness('gmail', true);
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
    $business = createBusiness('gmail', true);
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

it('would be a disaster if user could edit other staff projects', function () {
    /**
     * Validation used to run first, so another business's project was checked for
     * name clashes - and told the caller about them - before anything refused it.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $otherBusiness = createBusiness('outlook', true);
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

it('would be a disaster if user could see other business’s projects', function () {});

it('would be a disaster if user cannot see other staff materials', function () {});

it('would be a disaster if user could create projects before email verification', function () {});

it('would be a disaster if user could create projects before admin confirmation', function () {});

it('would be a disaster if user could archive other staff projects', function () {
    /**
     * The gate only asks whether a project belongs to your business, and the Nesting column
     * is shared - so every colleague's project carried a live Archive button. The archived
     * list it is restored from is filtered to your own projects, so archiving a colleague's
     * project took it off the board with no way back for anyone but them.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $colleaguesProject = createProject($colleague);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->delete(route('projects.destroy', $colleaguesProject->id));

    $response->assertForbidden();
    expect($colleaguesProject->fresh()->archive)->toBeFalsy();
});

it('would be a disaster if user could archive project with active quotes and orders', function () {
    /**
     * Archiving a nested project fails undoStartQuoting condition 3, so its batch can never
     * be re-nested again - and the tooltip that says so names an archived project the rest
     * of the business cannot see. The button hid itself outside the Nesting column; nothing
     * on the server did.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    pieceOnBatch($project, $batch);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->delete(route('projects.destroy', $project->id));

    $response->assertForbidden();
    expect($project->fresh()->archive)->toBeFalsy();
});

it('would be a disaster if a project archived before nesting could not be restored', function () {
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $project = createProject($user);

    $this->actingAs($user)->from('/dashboard')->delete(route('projects.destroy', $project->id));
    expect($project->fresh()->archive)->toBeTruthy();

    $this->actingAs($user)->from('/dashboard')->delete(route('projects.destroy', $project->id));
    expect($project->fresh()->archive)->toBeFalsy();
});

it('would be a disaster if a project archived while nested could not be restored', function () {
    /**
     * Restore asks for less than archive does. A project that reached a batch while archived -
     * "Lost it" on a notification used to do exactly that - is the one holding its batch back,
     * so refusing to restore it would leave the batch stuck for good.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $project->update(['archive' => true]);

    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    pieceOnBatch($project, $batch);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->delete(route('projects.destroy', $project->id));

    $response->assertRedirect();
    expect($project->fresh()->archive)->toBeFalsy();
});

it('would be a disaster if archiving left the project’s reminders in the bell', function () {
    /**
     * Each notification clears itself when the question it asks is answered, and none of them
     * counts archiving as an answer - so an archived project kept asking whether it had been
     * awarded, from a board it no longer appears on.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $notification = projectAwardedNotification($user, $project);

    $this->actingAs($user)->from('/dashboard')->delete(route('projects.destroy', $project->id));

    expect($project->fresh()->archive)->toBeTruthy()
        ->and($notification->fresh()->read_at)->not->toBeNull();
});

it('would be a disaster if a notification id was enough to archive someone else’s project', function () {
    /**
     * The status route looked the notification up by id alone, and the red action on this one
     * archives the project it names - so an id, from any account, archived another business's
     * project.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $otherBusiness = createBusiness('outlook', true);
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
    expect($otherProject->fresh()->archive)->toBeFalsy();
});

it('would be a disaster if "Lost it" archived a project that is already nested', function () {
    $business = createBusiness('gmail', true);
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
    expect($project->fresh()->archive)->toBeFalsy();
});

it('would be a disaster if the archived list cost a walk of every material row', function () {
    /**
     * The archived list draws a name and a "Restore" link, and it only ever grows. It used to
     * be built from the full ProjectResource, which walks rawMaterialQuotes > piece > quotes
     * and > order per row with nothing eager loaded - about three queries per material line,
     * on every dashboard load, for fields nothing renders.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    createRawMaterialQuote200Pfc($project, MaterialEnums::PLAIN_CARBON_STEEL, GradeEnums::GR300, 9000);
    $project->update(['archive' => true]);

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('archivedProjects.data', 1)
            ->has('archivedProjects.data.0', fn (Assert $archived) => $archived
                ->where('id', $project->id)
                ->where('name', $project->name)
                ->where('user_id', $user->id)
                ->where('archive', true)
            )
        );
});

it('would be a disaster if the hourly checks could not ask for active projects', function () {
    /**
     * All four hourly notification checks open with Project::query()->active(), and the scope
     * did not exist - so each of them died on a BadMethodCallException as soon as the job ran.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $active = createProject($user);
    $archived = createProject($user);
    $archived->update(['archive' => true]);

    $ids = Project::query()->active()->pluck('id');

    expect($ids)->toContain($active->id)
        ->and($ids)->not->toContain($archived->id);
});

//todo more
