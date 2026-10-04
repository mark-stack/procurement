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
        'date_materials_required' => now()->addWeekdays((new Project)->criticalPathDays())->addHours(2),
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
    $business = createBusiness('gmail');
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
    $business = createBusiness('gmail');
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

it('would be a disaster if one project got both "due to quote" and "deadline has passed"', function () {
    /*
     * "Due" was [critical path, critical path + 1 day] and "overdue" was anything before the END of the
     * critical path day, so the two windows overlapped across that whole day: a materials date exactly
     * the critical path away satisfied both, and the project manager got "the materials are due to be
     * quoted" and "the deadline has passed, quote today" about the same project in the same hourly run.
     */
    $this->travelTo(now()->setTime(9, 0));

    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $project->update([
        //The boundary both windows used to claim
        'date_materials_required' => now()->addWeekdays((new Project)->criticalPathDays())->startOfDay(),
    ]);

    Notification::fake();

    (new NotificationQuotingOrderingDueImplementation)->hourlyCheck();
    (new NotificationQuotingOrderingOverDueImplementation)->hourlyCheck();

    Notification::assertSentTimes(QuoteDueEmail::class, 1);
    Notification::assertNotSentTo($user, App\Notifications\QuoteOverdueEmail::class);
});

it('would be a disaster if the day before the critical path went unchased by either', function () {
    /*
     * The other half of moving that boundary: closing the overlap must not open a gap. A materials date
     * one day inside the critical path belongs to "overdue" and has to be chased by it.
     */
    $this->travelTo(now()->setTime(9, 0));

    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);

    $project = createProject($user);
    $project->update([
        'date_materials_required' => now()->addWeekdays((new Project)->criticalPathDays() - 1)->endOfDay(),
    ]);

    Notification::fake();

    (new NotificationQuotingOrderingDueImplementation)->hourlyCheck();
    (new NotificationQuotingOrderingOverDueImplementation)->hourlyCheck();

    Notification::assertSentTimes(App\Notifications\QuoteOverdueEmail::class, 1);
    Notification::assertNotSentTo($user, QuoteDueEmail::class);
});

it('would be a disaster if an unmatched BOM row chased a project manager forever', function () {
    /*
     * The stopping condition was percentageOfMaterialsOrdered() === 100, and that figure counts every
     * material row - including one that never matched a product and so has no piece to order. Such a
     * project could never reach 100, so the reminders chased its manager daily, telling them to order a
     * batch whose every orderable line had already been ordered, with no way to silence it. See
     * Project::everyOrderableRowOrdered.
     */
    $business = createBusiness('gmail');
    $user = createUser(1, $business, false, true);
    $project = projectDueForQuoting($user);

    $batch = App\Models\Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch, 'STEEL_MERCHANT', true, true);

    //One row that was matched and ordered
    $ordered = createRawMaterialQuote200Pfc(
        $project,
        App\Enums\MaterialEnums::PLAIN_CARBON_STEEL,
        App\Enums\GradeEnums::GR300,
        9000,
    );
    App\Models\Piece::create([
        'project_id' => $project->id,
        'raw_material_quote_id' => $ordered->id,
        'batch_id' => $batch->id,
        'order_id' => $order->id,
        'product_category' => App\Enums\ProductEnums::PFC->value,
        'material' => App\Enums\MaterialEnums::PLAIN_CARBON_STEEL->value,
        'grade' => App\Enums\GradeEnums::GR300->value,
        'surface' => App\Enums\SurfaceEnums::NONE->value,
        'actual_length' => 9000,
    ]);

    //And one the price book never matched, so it has no piece at all
    createRawMaterialQuote200Pfc(
        $project,
        App\Enums\MaterialEnums::PLAIN_CARBON_STEEL,
        App\Enums\GradeEnums::GR300,
        6000,
    );

    //Still an honest figure for the board to show
    expect($project->fresh()->load('rawMaterialQuotes')->percentageOfMaterialsOrdered())->toBe(50);

    Notification::fake();

    (new NotificationQuotingOrderingDueImplementation)->hourlyCheck();

    Notification::assertNotSentTo($user, QuoteDueEmail::class);
});
