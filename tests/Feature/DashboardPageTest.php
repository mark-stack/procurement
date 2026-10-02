<?php

use App\Formatters\KanbanFormatter;
use App\Models\Batch;
use App\Models\Order;
use App\Models\Piece;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * The two sections /dashboard grew above its upload form: where every live job stands, and what is
 * waiting on somebody.
 *
 * The upload half is covered by MaterialListUploadPageTest, which asserts the same page. What is
 * asserted here is the summary - and above all that it agrees with the board, because a dashboard
 * that says "2 quoting" over a board drawing three cards in Quoting is worse than no dashboard.
 */

/**
 * A project waiting to be nested: a material list with an un-nested piece against it.
 *
 * Named apart from the other files' helpers - pest loads every test file into one process, so a second
 * declaration of an existing name is fatal.
 */
function dashboardNestingProject(User $user, ?string $name = null): Project
{
    $project = createProject($user);

    if ($name !== null) {
        $project->update(['name' => $name]);
    }

    Piece::factory()->create(['project_id' => $project->id]);

    return $project;
}

/**
 * A nested batch carrying one project, with no order sent: the Quoting step.
 *
 * @return array{0: Batch, 1: Project, 2: Piece}
 */
function dashboardQuotingBatch(User $user): array
{
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    $project = createProject($user);
    $piece = pieceOnBatch($project, $batch);

    return [$batch, $project, $piece];
}

/**
 * An order on a batch, with the quote that carries its supplier category.
 */
function dashboardOrder(
    User $user,
    Batch $batch,
    bool $orderSent = true,
    bool $isDelivered = false,
    ?string $materialCertNumbers = 'CERT-1',
    bool $quoteSent = true,
    ?float $quotedPrice = 1000.0,
): Order {
    $supplier = Supplier::factory()->create();

    $quote = Quote::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_id' => $supplier->id,
        'supplier_category' => 'STEEL_MERCHANT',
        'quote_sent' => $quoteSent,
        'quoted_price' => $quotedPrice,
        'quoted_lead_time' => null,
    ]);

    return Order::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_id' => $supplier->id,
        'quote_id' => $quote->id,
        'order_sent' => $orderSent,
        'is_delivered' => $isDelivered,
        'material_cert_numbers' => $materialCertNumbers,
    ]);
}

/**
 * @return array<string, int>
 */
function dashboardCounts($response): array
{
    return collect($response->viewData('page')['props']['pipeline'])
        ->mapWithKeys(fn (array $step) => [$step['key'] => $step['count']])
        ->all();
}

/**
 * @return array<int, string>
 */
function dashboardActionKeys($response): array
{
    return collect($response->viewData('page')['props']['actions'])
        ->pluck('key')
        ->all();
}

it('renders the dashboard with its three sections', function () {
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            //The upload form, which is what this page used to be and nothing else
            ->has('eligibleProjects')
            ->has('pipeline', 4)
            ->has('liveProjects')
            ->has('actions')
        );
});

it('counts each step of the pipeline', function () {
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    //Nesting: a material list nothing has batched yet
    dashboardNestingProject($user);

    //Quoting: nested, nothing ordered
    dashboardQuotingBatch($user);

    /*
     * Ordering: one order sent, and a second material line on the batch that it does not cover. The
     * step turns on order coverage, not on how many order rows exist.
     */
    [$orderingBatch, $orderingProject, $orderedPiece] = dashboardQuotingBatch($user);
    $unordered = pieceOnBatch($orderingProject, $orderingBatch);
    $orderedPiece->order_id = dashboardOrder($user, $orderingBatch)->id;
    $orderedPiece->save();

    //Delivering: every line ordered
    [$deliveringBatch, , $deliveredPiece] = dashboardQuotingBatch($user);
    $deliveredPiece->order_id = dashboardOrder($user, $deliveringBatch)->id;
    $deliveredPiece->save();

    $counts = dashboardCounts($this->actingAs($user)->get(route('dashboard')));

    expect($counts)->toBe([
        'NESTING' => 1,
        'QUOTING' => 1,
        'ORDERING' => 1,
        'DELIVERING' => 1,
    ])
        //The un-ordered line is what holds the ordering batch where it is
        ->and($unordered->fresh()->order_id)->toBeNull();
});

it('would be a disaster if the dashboard and the board disagreed about where a batch is', function () {
    /*
     * The two screens read the same business. The pills come from App\Services\BatchStages and the
     * board's columns are built by KanbanFormatter, and the only reason they can be trusted to agree
     * is that the columns now ask BatchStages too. Somebody splitting them apart again should fail
     * here rather than in front of a customer counting cards.
     */
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    dashboardQuotingBatch($user);

    [$orderingBatch, $orderingProject, $orderedPiece] = dashboardQuotingBatch($user);
    pieceOnBatch($orderingProject, $orderingBatch);
    $orderedPiece->order_id = dashboardOrder($user, $orderingBatch)->id;
    $orderedPiece->save();

    [$deliveringBatch, , $deliveredPiece] = dashboardQuotingBatch($user);
    $deliveredPiece->order_id = dashboardOrder($user, $deliveringBatch)->id;
    $deliveredPiece->save();

    $this->actingAs($user);

    $counts = dashboardCounts($this->get(route('dashboard')));

    $kanban = new KanbanFormatter;

    expect($counts['QUOTING'])->toBe(count($kanban->quotedColumn($business, $user)))
        ->and($counts['ORDERING'])->toBe(count($kanban->orderedColumn($business)))
        ->and($counts['DELIVERING'])->toBe(count($kanban->deliveredColumn($business)));
});

it('asks for the quote requests on a batch that has not been sent out', function () {
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    [$batch] = dashboardQuotingBatch($user);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $action = collect($response->viewData('page')['props']['actions'])
        ->firstWhere('key', 'quote-requests-'.$batch->id);

    expect($action)->not->toBeNull()
        /*
         * Into the quotes modal for this batch, not at the board in general. It is the deep link the
         * fabrication deadline emails already use, and the whole value of the action is being one tap
         * from the thing it asks for.
         */
        ->and($action['href'])->toContain('quotes='.$batch->id)
        ->and($action['projectNames'])->toHaveCount(1);
});

it('asks for the prices once the requests have gone out', function () {
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    [$batch] = dashboardQuotingBatch($user);

    //Sent, unpriced: the quote is out with the merchant and nothing has come back
    dashboardOrder($user, $batch, orderSent: false, quotedPrice: null);

    $response = $this->actingAs($user)->get(route('dashboard'));

    expect(dashboardActionKeys($response))->toContain('record-prices-'.$batch->id);
});

it('would be a disaster if a draft quote row read as having asked a supplier', function () {
    /*
     * A quote row exists for every supplier in the group from the moment somebody opens the modal, so
     * "has quotes" is not "has asked anybody". If this ever reads the row rather than quote_sent, the
     * dashboard stops asking for the one thing the batch is actually waiting for.
     */
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    [$batch] = dashboardQuotingBatch($user);

    dashboardOrder($user, $batch, orderSent: false, quoteSent: false, quotedPrice: null);

    $response = $this->actingAs($user)->get(route('dashboard'));

    expect(dashboardActionKeys($response))->toContain('quote-requests-'.$batch->id);
});

it('asks for a delivery to be booked in, then for the batch to be closed', function () {
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    [$batch, , $piece] = dashboardQuotingBatch($user);
    $order = dashboardOrder($user, $batch);
    $piece->order_id = $order->id;
    $piece->save();

    $this->actingAs($user);

    expect(dashboardActionKeys($this->get(route('dashboard'))))
        ->toContain('record-delivery-'.$batch->id);

    $order->update(['is_delivered' => true]);

    expect(dashboardActionKeys($this->get(route('dashboard'))))
        ->toContain('move-to-done-'.$batch->id);
});

it('raises delivered steel that carries no material certificate', function () {
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    [$batch, , $piece] = dashboardQuotingBatch($user);
    $order = dashboardOrder($user, $batch, isDelivered: true, materialCertNumbers: null);
    $piece->order_id = $order->id;
    $piece->save();

    $response = $this->actingAs($user)->get(route('dashboard'));

    expect(dashboardActionKeys($response))->toContain('material-certs-'.$batch->id);
});

it('raises a material line that is matched to nothing, because nothing can be bought for it', function () {
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    $project = dashboardNestingProject($user);

    //A second material row with no piece: the shape a line that matched no product leaves behind
    createRawMaterialQuote200Pfc($project, App\Enums\MaterialEnums::PLAIN_CARBON_STEEL, App\Enums\GradeEnums::GR300, 9000);

    $response = $this->actingAs($user)->get(route('dashboard'));

    expect(dashboardActionKeys($response))->toContain('unmatched-'.$project->id)
        ->and(collect($response->viewData('page')['props']['liveProjects'])->firstWhere('id', $project->id))
        ->toMatchArray(['rows' => 2, 'unmatchedRows' => 1, 'stage' => 'NESTING']);
});

it('would be a disaster if it told a colleague to finish a material list they may not touch', function () {
    /*
     * The Nesting column is shared, and the one thing a colleague may not do is change a material list
     * that is nothing to do with them - see Project::isManagedBy and the upload gate behind it. An
     * action they would be refused is a dead end; the manager still gets it.
     */
    $business = createBusiness('acmesteel');
    $manager = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $project = dashboardNestingProject($manager);
    createRawMaterialQuote200Pfc($project, App\Enums\MaterialEnums::PLAIN_CARBON_STEEL, App\Enums\GradeEnums::GR300, 9000);

    expect(dashboardActionKeys($this->actingAs($colleague)->get(route('dashboard'))))
        ->not->toContain('unmatched-'.$project->id);

    expect(dashboardActionKeys($this->actingAs($manager)->get(route('dashboard'))))
        ->toContain('unmatched-'.$project->id);
});

it('raises a project with no fabrication date, which nothing will ever come and collect', function () {
    /*
     * Quoting starts five days before fabrication does and that is the only thing that moves a project
     * along unasked, so a project with no date waits in Nesting for good. Only possible for projects
     * created before the date was required.
     */
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    $project = dashboardNestingProject($user);
    $project->update(['date_fabrication_begins' => null]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    expect(dashboardActionKeys($response))->toContain('no-fabrication-date-'.$project->id)
        //And no "start quoting", which only has a date to be urgent about
        ->and(dashboardActionKeys($response))->not->toContain('start-quoting');
});

it('says to start quoting when the first cut is close and the column has not moved', function () {
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    $project = dashboardNestingProject($user);
    $project->update(['date_fabrication_begins' => now()->addDays(2)->toDateString()]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    expect(dashboardActionKeys($response))->toContain('start-quoting');
});

it('would be a disaster if it told somebody to start quoting who would be refused', function () {
    /*
     * PrerequisiteConditions::startQuoting requires the caller to own a project in the column - its
     * condition 2 - so a colleague with nothing of their own in there is offered a button that can only
     * answer 403.
     */
    $business = createBusiness('acmesteel');
    $manager = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $project = dashboardNestingProject($manager);
    $project->update(['date_fabrication_begins' => now()->addDays(2)->toDateString()]);

    expect(dashboardActionKeys($this->actingAs($colleague)->get(route('dashboard'))))
        ->not->toContain('start-quoting');
});

it('leaves a finished batch off the dashboard altogether', function () {
    //A done batch is a past project, and the past projects page is where those are read
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    [$batch, $project, $piece] = dashboardQuotingBatch($user);
    $piece->order_id = dashboardOrder($user, $batch, isDelivered: true)->id;
    $piece->save();
    $batch->update(['done' => true]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    expect(dashboardCounts($response))->toBe([
        'NESTING' => 0,
        'QUOTING' => 0,
        'ORDERING' => 0,
        'DELIVERING' => 0,
    ])
        ->and($response->viewData('page')['props']['liveProjects'])->toBe([])
        ->and($response->viewData('page')['props']['actions'])->toBe([]);
});

it('shows a test mode account only its own work', function () {
    /*
     * The sandbox is a global scope on Project and Batch, so this needs no code of its own - but the
     * dashboard is a new way into both, and a user who cannot tell which set they are looking at is the
     * one failure test mode must not have.
     */
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    dashboardNestingProject($user);
    dashboardQuotingBatch($user);

    $user->sandbox_mode = true;
    $user->save();

    $response = $this->actingAs($user->fresh())->get(route('dashboard'));

    expect(dashboardCounts($response))->toBe([
        'NESTING' => 0,
        'QUOTING' => 0,
        'ORDERING' => 0,
        'DELIVERING' => 0,
    ]);
});

it('puts an overdue batch above work that is merely in progress', function () {
    /*
     * Urgency is the steel's own deadline, not the step it is on. A batch whose first cut has passed is
     * late whatever it is waiting for, and it has to be the first thing read on the page.
     */
    $business = createBusiness('acmesteel');
    $user = createUser(1, $business, false, true);

    [$comfortable, $comfortableProject] = dashboardQuotingBatch($user);
    $comfortableProject->update(['date_fabrication_begins' => now()->addMonths(2)->toDateString()]);

    [$late, $lateProject] = dashboardQuotingBatch($user);
    $lateProject->update(['date_fabrication_begins' => now()->subDays(3)->toDateString()]);

    $actions = collect($this->actingAs($user)->get(route('dashboard'))->viewData('page')['props']['actions']);

    expect($actions->first()['key'])->toBe('quote-requests-'.$late->id)
        ->and($actions->first()['severity'])->toBe('overdue')
        ->and($actions->last()['key'])->toBe('quote-requests-'.$comfortable->id)
        ->and($actions->last()['severity'])->toBe('open');
});
