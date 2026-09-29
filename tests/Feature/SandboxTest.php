<?php

use App\Models\Bar;
use App\Models\Batch;
use App\Models\Offcut;
use App\Models\Order;
use App\Models\Piece;
use App\Models\Project;
use App\Models\Quote;
use App\Models\RawMaterialQuote;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\QuoteDueEmail;
use App\Services\NotificationImplementations\NotificationQuotingOrderingDueImplementation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Act as this user with their test mode switched on.
 *
 * The stamp on a new project is read off the authenticated user, so the order matters: anything
 * created before this call is live data, anything created after it is that user's test data.
 */
function actingInTestMode(User $user): User
{
    $user->sandbox_mode = true;
    $user->save();

    test()->actingAs($user);

    return $user;
}

function actingInLiveMode(User $user): User
{
    $user->sandbox_mode = false;
    $user->save();

    test()->actingAs($user);

    return $user;
}

/**
 * A project the hourly "due for quoting" check picks up: due inside the critical path window.
 *
 * Named apart from NotificationRemindersTest's projectDueForQuoting() - pest loads every test file
 * into the one process, so a second declaration of that name is a fatal, not an override.
 */
function sandboxProjectDueForQuoting(User $user): Project
{
    $project = createProject($user);

    $project->update([
        'date_materials_required' => now()->addDays((new Project)->criticalPathDays())->addHours(2),
    ]);

    return $project;
}

/**
 * A batch with everything a real one drags behind it - a quote, an order, a piece, an offcut and
 * the bar it came off - so that clearing can be asked to account for all of it.
 *
 * @return array{0: Batch, 1: Quote, 2: Order, 3: Offcut}
 */
function sandboxBatchWithWorkings(User $user, Project $project): array
{
    $batch = Batch::create(['user_id' => $user->id]);
    $supplier = Supplier::factory()->create();

    $quote = Quote::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_id' => $supplier->id,
        'supplier_category' => 'STEEL_MERCHANT',
        'quote_sent' => true,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_id' => $supplier->id,
        'quote_id' => $quote->id,
        'order_sent' => true,
        'is_delivered' => true,
    ]);

    $piece = pieceOnBatch($project, $batch);
    $piece->order_id = $order->id;
    $piece->save();
    $piece->quotes()->attach($quote->id);

    $bar = Bar::create([
        'batch_id' => $batch->id,
        'product_category' => 'PFC',
        'material' => 'PLAIN CARBON STEEL',
        'grade' => 'GR300',
        'surface' => 'NONE',
        'nominal_height' => 200,
        'product_derived_label' => '200PFC',
        'length' => 9000,
    ]);

    $offcut = create_offcut_200PFC(1500, $batch->id);
    $offcut->bar_id = $bar->id;
    $offcut->save();

    return [$batch, $quote, $order, $offcut];
}

it('would be a disaster if a test project turned up on the real board', function () {
    /*
     * The whole promise of the sandbox. A project made in test mode is one person's scratch work
     * against the real price book - it is not a job, nobody is quoting it, and it must not appear
     * on the board the business runs off.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    actingInTestMode($user);
    $test = createProject($user);
    pieceReadyForBatching($test);

    //Their own live board
    actingInLiveMode($user);
    $this->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects.READY_FOR_NESTING.projects.data', 0)
            ->etc()
        );

    //And the colleague's, who should have no way of knowing it exists
    $this->actingAs($colleague)
        ->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects.READY_FOR_NESTING.projects.data', 0)
            ->etc()
        );

    //It is not gone, it is just somewhere else
    actingInTestMode($user);
    $this->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('projects.READY_FOR_NESTING.projects.data.0.id', $test->id)
            ->etc()
        );
});

it('would be a disaster if test mode showed the real projects', function () {
    /*
     * The other half of it. A sandbox that still lists live work is not a sandbox - the first
     * thing somebody tries in there is nesting everything on the board.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $live = createProject($user);
    pieceReadyForBatching($live);

    actingInTestMode($user);

    $this->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects.READY_FOR_NESTING.projects.data', 0)
            ->etc()
        );

    //And the live project is untouched by any of it
    actingInLiveMode($user);
    expect(Project::query()->whereKey($live->id)->exists())->toBeTrue();
});

it('would be a disaster if one person’s sandbox was visible in another’s', function () {
    /*
     * Per user, not per business. Two people at the same company experimenting at the same time
     * would otherwise be handing each other rubbish to make sense of.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    actingInTestMode($user);
    $mine = createProject($user);
    pieceReadyForBatching($mine);

    actingInTestMode($colleague);
    $theirs = createProject($colleague);
    pieceReadyForBatching($theirs);

    $this->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects.READY_FOR_NESTING.projects.data', 1)
            ->where('projects.READY_FOR_NESTING.projects.data.0.id', $theirs->id)
            ->etc()
        );
});

it('would be a disaster if the suppliers went missing in test mode', function () {
    /*
     * Suppliers, products and templates are deliberately not sandboxed: the point of test mode is
     * to try a real BOM against the real price book and the real merchants. Only the work made
     * against them is disposable.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    $supplier = Supplier::create(['name' => 'Ours', 'supplier_categories' => serialize([])]);
    $business->suppliers()->attach($supplier->id);

    actingInTestMode($user);

    $this->get(route('suppliers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('suppliers.data', 1)
            ->where('suppliers.data.0.name', 'Ours')
            ->etc()
        );
});

it('would be a disaster if leaving test mode threw the sandbox away', function () {
    /*
     * Switching modes is a view, not a deletion. Somebody halfway through an experiment has to be
     * able to go and do a day's real work and come back to it.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    actingInTestMode($user);
    $test = createProject($user);

    $this->post(route('sandbox.leave'))->assertRedirect(route('projects.index'));

    expect($user->fresh()->sandbox_mode)->toBeFalse()
        ->and(Project::query()->withoutGlobalScope('sandbox')->whereKey($test->id)->exists())->toBeTrue();

    $this->post(route('sandbox.enter'))->assertRedirect(route('projects.index'));

    expect($user->fresh()->sandbox_mode)->toBeTrue()
        ->and(Project::query()->whereKey($test->id)->exists())->toBeTrue();
});

it('would be a disaster if clearing left any of the test data behind', function () {
    /*
     * "Disposable" has to mean disposable. A quote, an order, a piece or an offcut left pointing at
     * a deleted batch is exactly the wreckage the sandbox exists to avoid - and an offcut left
     * behind still holds its unique mark against the pool the next nest draws from.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);

    actingInTestMode($user);

    $project = createProject($user);
    [$batch, $quote, $order, $offcut] = sandboxBatchWithWorkings($user, $project);

    /*
     * That there was something here to delete. Every assertion below is a count of zero, and a
     * fixture that quietly built nothing would satisfy all of them.
     */
    expect(Piece::count())->toBe(1)
        ->and(Offcut::count())->toBe(1)
        ->and(DB::table('piece_quote')->count())->toBe(1)
        ->and($batch->isSandbox())->toBeTrue()
        ->and($project->isSandbox())->toBeTrue();

    $this->delete(route('sandbox.clear'))->assertRedirect();

    expect(Project::query()->withoutGlobalScope('sandbox')->count())->toBe(0)
        ->and(Batch::query()->withoutGlobalScope('sandbox')->count())->toBe(0)
        ->and(Piece::count())->toBe(0)
        ->and(RawMaterialQuote::count())->toBe(0)
        ->and(Quote::count())->toBe(0)
        ->and(Order::count())->toBe(0)
        ->and(Offcut::count())->toBe(0)
        ->and(Bar::count())->toBe(0)
        ->and(DB::table('piece_quote')->count())->toBe(0);

    //The real price book side of it is untouched
    expect(Supplier::count())->toBe(1);
});

it('would be a disaster if clearing a sandbox reached live work', function () {
    /*
     * The button says "clear everything", and everything means everything in the sandbox. A live
     * project, a live batch and a colleague's sandbox all have to survive it.
     */
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    //Live work, made before test mode was ever switched on
    $this->actingAs($user);
    $live = createProject($user);
    [$liveBatch] = sandboxBatchWithWorkings($user, $live);

    //The colleague's own sandbox
    actingInTestMode($colleague);
    $theirs = createProject($colleague);
    [$theirBatch] = sandboxBatchWithWorkings($colleague, $theirs);

    //And this user's, which is the only thing that should go
    actingInTestMode($user);
    $mine = createProject($user);
    [$myBatch] = sandboxBatchWithWorkings($user, $mine);

    $this->delete(route('sandbox.clear'));

    $projects = Project::query()->withoutGlobalScope('sandbox')->pluck('id')->all();
    $batches = Batch::query()->withoutGlobalScope('sandbox')->pluck('id')->all();

    expect($projects)->toEqualCanonicalizing([$live->id, $theirs->id])
        ->and($batches)->toEqualCanonicalizing([$liveBatch->id, $theirBatch->id]);
});

it('would be a disaster if a test project was chased by the hourly reminders', function () {
    /*
     * The checks run on a schedule, with nobody logged in, so they read live data and only live
     * data. Being emailed about a materials date on a project you invented to see what a button
     * did is the sandbox leaking into the real world.
     */
    Notification::fake();

    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    //A real project of a colleague's, on the same deadline - the control. Without it this test
    //would pass just as happily if the check had stopped noticing anything at all
    $this->actingAs($colleague);
    $real = sandboxProjectDueForQuoting($colleague);

    actingInTestMode($user);
    $test = sandboxProjectDueForQuoting($user);

    //As the scheduler runs it: no authenticated user
    auth()->logout();

    (new NotificationQuotingOrderingDueImplementation)->hourlyCheck();

    //Nobody is chased about the test project
    Notification::assertNotSentTo($user, QuoteDueEmail::class);

    //And the real one on the same deadline still goes out, so this is about who the check can see
    Notification::assertSentTo($colleague, QuoteDueEmail::class);

    //Reachable in the sandbox, so the test is about who can see it rather than whether it exists
    actingInTestMode($user);
    expect(Project::query()->whereKey($test->id)->exists())->toBeTrue();
});
