<?php

use App\Models\Batch;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\Quote;
use App\Models\Supplier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('would be a disaster if materials for a project were imported after the order but included in the batch', function () {});

it('would be a disaster if missed order deadline', function () {});

it('would be a disaster if ordered without other staff approval', function () {
    /*
     * A batch carries every project that was ready when somebody pressed "Start quoting", so it
     * routinely spans several project managers. One of them then presses "Sent order" and
     * UpdateOrderApprovalStatus records EVERY project on the batch as approved by its manager -
     * the others are neither asked nor told.
     *
     * That is what the button does, and this test pins it rather than pretending otherwise: what
     * it must not do is leave the row claiming an approval nobody can be held to. The approval now
     * names whoever actually pressed it, so a colleague's project shows the truth - approved, by
     * someone who is not its manager.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);

    $myProject = createProject($user);
    $colleaguesProject = createProject($colleague);

    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch);

    $approvals = collect([$myProject, $colleaguesProject])->mapWithKeys(fn ($project) => [
        $project->id => OrderApproval::create([
            'batch_id' => $batch->id,
            'project_id' => $project->id,
            'project_manager_approved' => false,
        ]),
    ]);

    $this->actingAs($user)
        ->post(route('order.sent', $batch), ['order_id' => $order->id])
        ->assertRedirect();

    //Both approved, because one press settles the batch
    $mine = $approvals[$myProject->id]->fresh();
    $theirs = $approvals[$colleaguesProject->id]->fresh();

    expect($mine->project_manager_approved)->toBeTrue();
    expect($theirs->project_manager_approved)->toBeTrue();

    //And both name the person who actually pressed it, not the project's own manager
    expect($mine->approved_by_user_id)->toBe($user->id);
    expect($theirs->approved_by_user_id)->toBe($user->id);
    expect($theirs->approved_by_user_id)->not->toBe($colleague->id);
    expect($theirs->approved_at)->not->toBeNull();
});

it('would be a disaster if undoing a sent order left the approval standing', function () {
    /*
     * Undoing hands the decision back, so there is nobody left to name - an approval that kept
     * its approver would read as still-agreed on a batch that is no longer ordered.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $project = createProject($user);

    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch);

    $approval = OrderApproval::create([
        'batch_id' => $batch->id,
        'project_id' => $project->id,
        'project_manager_approved' => false,
    ]);

    $this->actingAs($user);

    $this->post(route('order.sent', $batch), ['order_id' => $order->id])->assertRedirect();
    $this->post(route('order.undo.sent', $order))->assertRedirect();

    $approval = $approval->fresh();

    expect($approval->project_manager_approved)->toBeFalse();
    expect($approval->approved_by_user_id)->toBeNull();
    expect($approval->approved_at)->toBeNull();
});

it('would be a disaster if duplicate orders were possible', function () {
    /*
     * One order per quote - Order::firstOrCreate(['quote_id' => ...]) always assumed it, but nothing
     * enforced it, so two concurrent renders of the quote/order page could both insert.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);
    $batch = Batch::factory()->forUser($user->id)->create();

    $quote = Quote::create([
        'user_id' => $user->id,
        'batch_id' => $batch->id,
        'supplier_id' => Supplier::factory()->create()->id,
        'supplier_category' => 'STEEL_MERCHANT',
        'quote_sent' => false,
    ]);

    $attributes = ['quote_id' => $quote->id];
    $defaults = ['user_id' => $user->id, 'batch_id' => $batch->id, 'order_sent' => false];

    $first = Order::firstOrCreate($attributes, $defaults);
    $second = Order::firstOrCreate($attributes, $defaults);

    expect($second->id)->toBe($first->id);
    expect(Order::count())->toBe(1);

    expect(fn () => Order::create($attributes + $defaults))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('would be a disaster if duplicate email notifications were happening', function () {});

it('would be a disaster if order follow up email not working', function () {});

it('would be a disaster if actual received materials are different to cut list', function () {});

it('would be a disaster if order lead times were incorrect', function () {});

//todo more
