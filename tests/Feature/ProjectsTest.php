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

it('would be a disaster if user could see other business’s projects', function () {});

it('would be a disaster if user cannot see other staff materials', function () {});

it('would be a disaster if user could edit other staff projects', function () {});

it('would be a disaster if user could create projects before email verification', function () {});

it('would be a disaster if user could create projects before admin confirmation', function () {});

it('would be a disaster if user could archive other staff projects', function () {});

it('would be a disaster if user could archive project with active quotes and orders', function () {});

//todo more
