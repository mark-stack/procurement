<?php

use App\Models\Project;
use App\Notifications\QuoteDueEmail;
use App\Services\NotificationImplementations\NotificationQuotingOrderingDueImplementation;
use App\Services\NotificationImplementations\NotificationQuotingOrderingOverDueImplementation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A project that the hourly check will pick up: due inside the critical path window, which is
 * quoting time plus delivery time (Project::criticalPathDays), and not archived.
 */
function projectDueForQuoting(App\Models\User $user): Project
{
    $project = createProject($user);

    $project->update([
        'date_materials_required' => now()->addDays((new Project)->criticalPathDays())->addHours(2),
    ]);

    return $project;
}

/**
 * The row hasBeenNotified() looks for.
 */
function alreadyRemindedAbout(App\Models\User $user, Project $project, string $notification): void
{
    DatabaseNotification::create([
        'id' => Str::uuid()->toString(),
        'type' => $notification,
        'notifiable_type' => App\Models\User::class,
        'notifiable_id' => $user->id,
        'data' => ['project_id' => $project->id],
        'read_at' => null,
    ]);
}

it('would be a disaster if one project manager’s reminder silenced everybody else’s', function () {
    /**
     * The loop over due projects used to `break` when a project had already been reminded about,
     * rather than `continue` - so the first project in the list ended the run for every project
     * manager behind it. Every business with more than one live project lost reminders to this,
     * which is to say every business the reminders exist for.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    //Lower id, so this one is reached first
    $alreadyChased = projectDueForQuoting($user);
    $stillNeedsChasing = projectDueForQuoting($colleague);

    alreadyRemindedAbout($user, $alreadyChased, QuoteDueEmail::class);

    Notification::fake();

    (new NotificationQuotingOrderingDueImplementation)->hourlyCheck();

    //The colleague behind the skipped project still hears about theirs
    Notification::assertSentTo($colleague, QuoteDueEmail::class);

    //And the one already chased is not chased twice
    Notification::assertNotSentTo($user, QuoteDueEmail::class);
});

it('still reminds every project manager with a project overdue', function () {
    /*
     * The overdue check carries a copy of the same loop, and had the same break in it.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $mine = projectDueForQuoting($user);
    $theirs = projectDueForQuoting($colleague);

    //Both overdue: less than the critical path left before the materials are needed
    foreach ([$mine, $theirs] as $project) {
        $project->update(['date_materials_required' => now()->addDay()]);
    }

    Notification::fake();

    (new NotificationQuotingOrderingOverDueImplementation)->hourlyCheck();

    Notification::assertSentTo($user, App\Notifications\QuoteOverdueEmail::class);
    Notification::assertSentTo($colleague, App\Notifications\QuoteOverdueEmail::class);
});
