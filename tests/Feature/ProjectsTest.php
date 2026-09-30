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

it('would be a disaster if user could edit another business’s projects', function () {
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

it('would be a disaster if user could edit other staff projects', function () {
    /**
     * The test of this name used to assert against another BUSINESS, which the gate already
     * refused - so what it was named for went uncovered. ProjectPolicy asks only whether a
     * project belongs to your business, and the Nesting column is shared, so every colleague's
     * card carried a live Edit button beside a greyed-out Archive one.
     *
     * Edit is not the smaller of the two: the name is how the rest of the business recognises
     * the project on the board and in Past Projects, and date_materials_required drives the
     * critical path and every deadline reminder its owner is sent.
     */
    $business = createBusiness('gmail', true);
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
    $business = createBusiness('gmail', true);
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
     * asks what the board draws. The board is the one screen that shows the whole business's work
     * rather than the caller's own, so a scoping mistake in any of its four columns puts another
     * company's projects, batches and suppliers in front of you rather than merely letting you
     * write to them.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $otherBusiness = createBusiness('outlook', true);
    $otherUser = createUser(2, $otherBusiness, false, true);

    $mine = createProject($user);
    pieceReadyForBatching($mine);

    $theirs = createProject($otherUser);
    pieceReadyForBatching($theirs);

    //And one of theirs already nested, so the batch columns are asked the same question
    $theirBatch = Batch::factory()->forUser($otherUser->id)->create(['done' => false]);
    pieceOnBatch(createProject($otherUser), $theirBatch);

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects.READY_FOR_NESTING.projects.data', 1)
            ->where('projects.READY_FOR_NESTING.projects.data.0.id', $mine->id)
            //The batch columns are plain arrays, not resource collections
            ->has('batches.QUOTED', 0)
            ->has('batches.ORDERED', 0)
            ->has('batches.DELIVERED', 0)
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
    $business = createBusiness('gmail', true);
    $unverified = createUser(1, $business, false, false);

    $this->actingAs($unverified)
        ->from('/dashboard')
        ->post(route('projects.store'), [
            'name' => 'Too early',
            'tentative' => false,
            'excel' => [],
        ])
        ->assertRedirect(route('verification.notice'));

    expect(Project::count())->toBe(0);
});

it('would be a disaster if user could create projects before admin confirmation', function () {
    /*
     * A business is only ready once an admin has recorded its templates - without them a BOM
     * auto-detects nothing, so the project would be created and then handed back empty. That is
     * what BusinessReadyMiddleware is for, and nothing asserted it over project creation.
     */
    $business = createBusiness('gmail', false);
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->from('/dashboard')
        ->post(route('projects.store'), [
            'name' => 'Too early',
            'tentative' => false,
            'excel' => [],
        ])
        ->assertRedirect(route('onboarding'));

    expect(Project::count())->toBe(0);
});

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

it('would be a disaster if restoring a project put two of the same name on the board', function () {
    /**
     * Both project forms deliberately exclude archived names from their uniqueness check, and say
     * so: "archived ones are free to reuse". Nothing then looked at the name on the way back in, so
     * the whole sequence is legal - archive "Tower A", give the freed name to a new project, restore
     * the old one - and it ends with two live projects called "Tower A" in the shared Nesting column
     * for two different jobs.
     *
     * Nothing downstream can tell them apart for a person: two identical cards, the name twice in
     * Past Projects, and a colleague pressing "Start quoting" nests both into one batch whose spec
     * sheet, BOM download and notifications all name "Tower A". Steel gets cut for the wrong one.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $archived = createProject($user);
    $archived->update(['name' => 'Tower A', 'archive' => true]);

    //Allowed, and meant to be - the name is free while the other project is archived
    $live = createProject($user);
    $live->update(['name' => 'Tower A']);

    $response = $this->actingAs($user)
        ->from('/dashboard')
        ->delete(route('projects.destroy', $archived->id));

    //A reason rather than a 403: the owner can rename the archived project and try again
    $response->assertInvalid('archive');
    expect($archived->fresh()->archive)->toBeTruthy()
        ->and(Project::query()->where('archive', false)->where('name', 'Tower A')->count())->toBe(1);
});

it('lets a renamed project be restored once its old name is taken', function () {
    //The other half of the rule above - refusing has to leave a way through, not a dead end
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $archived = createProject($user);
    $archived->update(['name' => 'Tower A', 'archive' => true]);

    $live = createProject($user);
    $live->update(['name' => 'Tower A']);

    //Renaming an archived project is allowed - editProject does not ask whether it is archived
    $this->actingAs($user)
        ->from('/dashboard')
        ->put(route('projects.update', $archived->id), [
            'name' => 'Tower A (2024)',
            'reference' => $archived->reference,
            'date_materials_required' => $archived->date_materials_required,
        ])
        ->assertValid();

    $this->actingAs($user)
        ->from('/dashboard')
        ->delete(route('projects.destroy', $archived->id))
        ->assertRedirect();

    expect($archived->fresh()->archive)->toBeFalsy();
});

it('would be a disaster if a colleague’s project blocked a restore invisibly', function () {
    /*
     * The clash is measured across the whole business, because the board is - so a colleague
     * holding the name blocks the restore too. What matters is that it is refused with the reason
     * rather than silently, since renaming the colleague's project is not something this user can do.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $archived = createProject($user);
    $archived->update(['name' => 'Shared name', 'archive' => true]);

    $theirs = createProject($colleague);
    $theirs->update(['name' => 'Shared name']);

    $this->actingAs($user)
        ->from('/dashboard')
        ->delete(route('projects.destroy', $archived->id))
        ->assertInvalid('archive');

    expect($archived->fresh()->archive)->toBeTruthy();
});

it('would be a disaster if a project name had no length at all', function () {
    /**
     * projects.name is a TEXT column and the rules were "required|string" with no max, so anything
     * posting straight at the route could put an essay on every colleague's board. The card
     * truncates it, but the confirm dialogs do not - they build their message by joining the names
     * of every project involved, which is how "Start quoting" names whose work is being taken - so
     * one pasted wall of text makes a dialog nobody can read or reach the button of.
     */
    $business = createBusiness('gmail', true);
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
    $business = createBusiness('gmail', true);
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
    $business = createBusiness('gmail', true);
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

it('would be a disaster if a half-finished import was on nobody’s board', function () {
    /**
     * A project with an unconfirmed price book match is excluded from projectsReadyForBatching,
     * and the Nesting column is the only place a project that has not been nested is ever drawn.
     * So closing the upload modal part way through took the project off every screen in the app:
     * its owner had no route back to it, and a colleague could not so much as discover it
     * existed. It is listed separately now, alongside the column it is stuck before.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $project = createProject($colleague);
    $row = createRawMaterialQuote200Pfc($project, MaterialEnums::PLAIN_CARBON_STEEL, GradeEnums::GR300, 9000);

    //Two candidate products on the row is what "needs clarification" means - see ProductService
    $row->update(['general_product_matches' => serialize(['results' => [
        ['product_category' => 'PFC', 'grade' => 'GR300', 'surface' => 'NONE', 'nominal_height' => 200],
        ['product_category' => 'PFC', 'grade' => 'GR350', 'surface' => 'NONE', 'nominal_height' => 200],
    ]])]);

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            //Not in the column itself - it cannot be nested until the product is confirmed
            ->has('projects.READY_FOR_NESTING.projects.data', 0)
            ->has('projects.READY_FOR_NESTING.unfinishedImports.data', 1)
            ->has('projects.READY_FOR_NESTING.unfinishedImports.data.0', fn (Assert $unfinished) => $unfinished
                ->where('id', $project->id)
                ->where('name', $project->name)
                ->where('user_id', $colleague->id)
                //Named, so a colleague knows who to go and ask rather than just seeing it stuck
                ->where('projectManagerName', $colleague->name)
                //Only its owner can finish it
                ->where('prerequisiteUploadMaterials', false)
            )
        );
});

it('still offers the owner of a half-finished import a way to finish it', function () {
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $row = createRawMaterialQuote200Pfc($project, MaterialEnums::PLAIN_CARBON_STEEL, GradeEnums::GR300, 9000);

    $row->update(['general_product_matches' => serialize(['results' => [
        ['product_category' => 'PFC', 'grade' => 'GR300', 'surface' => 'NONE', 'nominal_height' => 200],
        ['product_category' => 'PFC', 'grade' => 'GR350', 'surface' => 'NONE', 'nominal_height' => 200],
    ]])]);

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects.READY_FOR_NESTING.unfinishedImports.data.0', fn (Assert $unfinished) => $unfinished
                ->where('id', $project->id)
                ->where('prerequisiteUploadMaterials', true)
                ->etc()
            )
        );
});

it('would be a disaster if an archived project reappeared as an unfinished import', function () {
    /*
     * The clarification walk covers every project the business has ever had, archived and nested
     * ones included - listing those would put projects back on the board that were deliberately
     * taken off it.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $row = createRawMaterialQuote200Pfc($project, MaterialEnums::PLAIN_CARBON_STEEL, GradeEnums::GR300, 9000);

    $row->update(['general_product_matches' => serialize(['results' => [
        ['product_category' => 'PFC', 'grade' => 'GR300', 'surface' => 'NONE', 'nominal_height' => 200],
        ['product_category' => 'PFC', 'grade' => 'GR350', 'surface' => 'NONE', 'nominal_height' => 200],
    ]])]);

    $project->update(['archive' => true]);

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects.READY_FOR_NESTING.unfinishedImports.data', 0)
        );
});

it('puts your own projects first in the shared nesting column', function () {
    /**
     * The column draws the whole business's work as one pile, in creation order, so on a board
     * with a few colleagues on it your own project was wherever it happened to land. The three
     * batch columns already sort this way through BatchService::sortByUserAndLatest; only the
     * column of projects did not.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    //Theirs first, so creation order alone would put it at the top
    $theirs = createProject($colleague);
    pieceReadyForBatching($theirs);

    $mine = createProject($user);
    pieceReadyForBatching($mine);

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('projects.READY_FOR_NESTING.projects.data.0.id', $mine->id)
            ->where('projects.READY_FOR_NESTING.projects.data.1.id', $theirs->id)
            ->etc()
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
