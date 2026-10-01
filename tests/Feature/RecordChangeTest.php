<?php

use App\Models\Batch;
use App\Models\MaterialCertificate;
use App\Models\Order;
use App\Models\Product;
use App\Models\RecordChange;
use App\Models\Supplier;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Every change recorded against one row, newest first.
 *
 * @return Illuminate\Database\Eloquent\Collection<int, RecordChange>
 */
function changesFor(Illuminate\Database\Eloquent\Model $model)
{
    return RecordChange::query()
        ->forRecord($model->getMorphClass(), $model->getKey())
        ->get();
}

it('records what changed on an order, with the before and the after', function () {
    /*
     * Nothing in this application recorded a change to anything. A corrected PO number, a re-keyed
     * certificate reference, a quantity fixed after a phone call - all of them left the row looking as
     * though it had always said what it says now, with updated_at as the only clue and one value to
     * carry it.
     */
    $user = createUser(1, createBusiness('biz'), false, true);
    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch);

    $this->actingAs($user)
        ->put(route('orders.update', $order), [
            'purchase_order_number' => 'PO-1001',
            'material_cert_numbers' => 'HEAT-4471882',
        ])
        ->assertRedirect();

    $change = changesFor($order)->first();

    expect($change->event)->toBe(RecordChange::UPDATED)
        ->and($change->user_id)->toBe($user->id)
        ->and($change->business_id)->toBe($user->business_id)
        ->and($change->record_type)->toBe('order')
        ->and($change->changes)->toHaveKey('purchase_order_number')
        //The before and the after, so "it was changed" is answerable as "from what, to what"
        ->and($change->changes['purchase_order_number'])->toBe([null, 'PO-1001'])
        ->and($change->changes['material_cert_numbers'])->toBe([null, 'HEAT-4471882']);

    //And a second edit is a second event rather than a rewrite of the first
    $this->put(route('orders.update', $order), [
        'purchase_order_number' => 'PO-1002',
        'material_cert_numbers' => 'HEAT-4471882',
    ]);

    //Just the edits - creating the order is an event of its own, and it is the first of the three
    $updates = changesFor($order)->where('event', RecordChange::UPDATED)->values();

    expect($updates)->toHaveCount(2)
        ->and($updates->first()->changes['purchase_order_number'])->toBe(['PO-1001', 'PO-1002'])
        //Newest first
        ->and($updates->last()->changes['purchase_order_number'])->toBe([null, 'PO-1001'])
        ->and(changesFor($order)->last()->event)->toBe(RecordChange::CREATED);
});

it('records nothing for a save that changed nothing', function () {
    /*
     * A save with no change is not an event. Recording it would mean every page that re-saves a row it
     * did not alter leaves a row in the log saying so, and the handful that matter would be buried.
     */
    $user = createUser(1, createBusiness('biz'), false, true);
    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch);

    //One row for creating the order, one for the edit
    $updates = fn () => changesFor($order)->where('event', RecordChange::UPDATED);

    $this->actingAs($user)->put(route('orders.update', $order), [
        'purchase_order_number' => 'PO-1001',
    ]);

    expect($updates())->toHaveCount(1);

    //The same value again
    $this->put(route('orders.update', $order), ['purchase_order_number' => 'PO-1001']);

    expect($updates())->toHaveCount(1);
});

it('keeps what a deleted row held', function () {
    /*
     * The case the table earns its keep on. Once the row is gone this is the only place that says what
     * it said - "a certificate was deleted" is not an answer; the filename, the size and who attached it
     * is.
     */
    Storage::fake(MaterialCertificate::DISK);

    $user = createUser(1, createBusiness('biz'), false, true);
    $batch = Batch::factory()->forUser($user->id)->create();
    //Not sent, so the certificate can still be taken back off
    [, $order] = quoteAndOrder($user, $batch, orderSent: false);

    $this->actingAs($user)->post(route('material.certificates.store', $order), [
        'certificates' => [UploadedFile::fake()->create('heat-4471882.pdf', 10, 'application/pdf')],
    ]);

    $certificate = $order->materialCertificates()->sole();
    $certificateId = $certificate->id;

    $this->delete(route('material.certificates.destroy', $certificate))->assertRedirect();

    expect(MaterialCertificate::query()->whereKey($certificateId)->exists())->toBeFalse();

    $deletion = RecordChange::query()
        ->forRecord('material_certificate', $certificateId)
        ->where('event', RecordChange::DELETED)
        ->sole();

    expect($deletion->changes['original_filename'])->toBe('heat-4471882.pdf')
        ->and($deletion->changes['order_id'])->toBe($order->id)
        ->and($deletion->user_id)->toBe($user->id);
});

it('records the creation of a row as the row it created', function () {
    $user = createUser(1, createBusiness('biz'), false, true);

    $this->actingAs($user)->post(route('suppliers.store'), [
        'name' => 'Southern Steel',
        'supplier_categories' => ['STEEL_MERCHANT' => true],
    ])->assertRedirect();

    $supplier = Supplier::query()->where('name', 'Southern Steel')->sole();

    $creation = changesFor($supplier)->sole();

    expect($creation->event)->toBe(RecordChange::CREATED)
        ->and($creation->changes['name'])->toBe('Southern Steel')
        ->and($creation->user_id)->toBe($user->id);
});

it('names the admin behind a change made while impersonating', function () {
    /*
     * Everything done while impersonating is stored against the impersonated user - that is what makes
     * the feature useful and what made the records lie. An order placed by an admin inside a customer's
     * account read as the customer placing it, and the only trace was a Log::info line in a rotating
     * file.
     */
    $adminBusiness = createBusiness('admin');
    $admin = createUser(1, $adminBusiness, true, true);

    $business = createBusiness('customer');
    $customer = createUser(1, $business, false, true);

    $batch = Batch::factory()->forUser($customer->id)->create();
    [, $order] = quoteAndOrder($customer, $batch);

    /*
     * The session key, set directly, which is exactly what the trait reads and all it can read -
     * AdminImpersonationController puts it there and nothing else carries it, since once Auth::login()
     * has run the request says the impersonated user is doing this. That the controller writes the key
     * is AdminImpersonationTest's job; this is about what the log does with it.
     */
    $this->withSession(['impersonator_id' => $admin->id])
        ->actingAs($customer)
        ->put(route('orders.update', $order), ['purchase_order_number' => 'PO-ADMIN'])
        ->assertRedirect();

    $change = changesFor($order)->first();

    //The record still says the customer did it, because that is how the row was written...
    expect($change->user_id)->toBe($customer->id)
        //...and now it also says who was really driving
        ->and($change->impersonator_user_id)->toBe($admin->id)
        ->and($change->wasImpersonated())->toBeTrue();
});

it('files a master catalogue edit under no business at all', function () {
    /*
     * The catalogue is the platform's, not a customer's. Filing an edit under the admin's own business
     * would read as that business having changed a row every business is matched against.
     */
    $admin = createUser(1, createBusiness('admin'), true, true);
    seedMasterMaterials();

    $product = Product::query()->where('deprecated', false)->first();

    $this->actingAs($admin)
        ->patch(route('admin.materials.update', $product), array_merge(
            $product->only(App\Services\ProductRules::EDITABLE),
            ['kg_per_m' => 99.5],
        ))
        ->assertRedirect();

    $change = changesFor($product)->first();

    expect($change->event)->toBe(RecordChange::UPDATED)
        ->and($change->record_type)->toBe('product')
        ->and($change->business_id)->toBeNull()
        ->and($change->user_id)->toBe($admin->id)
        ->and($change->changes)->toHaveKey('kg_per_m');
});

it('keeps a template screenshot out of the log', function () {
    /*
     * It is a data URI of a whole spreadsheet in a longText column. Recording it would put a megabyte of
     * base64 in every row of a table whose job is to be readable - twice over on an update, since each
     * entry holds the before and the after.
     */
    $business = createBusiness('biz');

    $template = Template::factory()->create([
        'business_id' => $business->id,
        'screenshot' => 'data:image/png;base64,'.str_repeat('A', 2048),
        'name' => 'First name',
    ]);

    $template->update([
        'name' => 'Second name',
        'screenshot' => 'data:image/png;base64,'.str_repeat('B', 2048),
    ]);

    $changes = changesFor($template);

    //Neither the create nor the update carries it
    foreach ($changes as $change) {
        expect($change->changes)->not->toHaveKey('screenshot');
    }

    //The name did move, so the update is still recorded
    expect($changes->first()->changes['name'])->toBe(['First name', 'Second name'])
        ->and($changes->first()->business_id)->toBe($business->id);
});

it('would be a disaster if a change log row could be edited or deleted', function () {
    /*
     * Append-only, enforced rather than documented. The migration describes the whole arrangement - no
     * updated_at column, this guard, and a grant in production that leaves the application with INSERT
     * and SELECT. This is the half that holds where the grant does not exist, and the half that catches
     * the honest mistake: a future screen calling update() on a log row because every other model here
     * allows it.
     */
    $user = createUser(1, createBusiness('biz'), false, true);
    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch);

    $this->actingAs($user)->put(route('orders.update', $order), [
        'purchase_order_number' => 'PO-1001',
    ]);

    $change = changesFor($order)->where('event', RecordChange::UPDATED)->sole();

    expect(fn () => $change->update(['event' => RecordChange::CREATED]))
        ->toThrow(LogicException::class);

    expect(fn () => $change->delete())->toThrow(LogicException::class);

    //Still there, and still saying what it said
    expect(RecordChange::query()->whereKey($change->id)->sole()->event)->toBe(RecordChange::UPDATED);
});

it('records a change made with nobody logged in without falling over', function () {
    /*
     * The hourly notification job and the quarterly cleanout both save models with no authenticated user
     * and no session at all. Asking an unbooted session for the impersonator id throws, so the trait has
     * to cope - a log row with no user is correct for a change nobody made by hand.
     */
    $user = createUser(1, createBusiness('biz'), false, true);
    $batch = Batch::factory()->forUser($user->id)->create();
    [, $order] = quoteAndOrder($user, $batch);

    //No actingAs: straight at the model, the way a queued job reaches it
    $order->purchase_order_number = 'PO-FROM-A-JOB';
    $order->save();

    $change = changesFor($order)->where('event', RecordChange::UPDATED)->sole();

    expect($change->user_id)->toBeNull()
        ->and($change->impersonator_user_id)->toBeNull()
        ->and($change->changes['purchase_order_number'])->toBe([null, 'PO-FROM-A-JOB']);
});
