<?php

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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

it('would be a disaster if user could archive other staff projects', function () {});

it('would be a disaster if user could archive project with active quotes and orders', function () {});

//todo more
