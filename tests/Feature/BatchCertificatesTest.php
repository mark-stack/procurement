<?php

use App\Models\Batch;
use App\Models\MaterialCertificate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Mill certificates attached to a batch rather than to one of its orders.
 *
 * The shop these are for has no orders: it rings the merchant, buys the steel, and marks the batch
 * delivered from the Nesting card. The merchant still emails a certificate, and until now there was
 * nowhere for it to go - which made "Delivered" a date with nothing behind it, on a job whose offcuts
 * are supposed to be traceable back to a heat of steel.
 *
 * A merchant at a time, and only the merchants whose material comes with a certificate at all: steel
 * does, timber does not. The files land in the same table, on the same private disk, behind the same
 * download route as the order-side ones, and show on the same block of the order list.
 */
/**
 * A nested batch with the certificate disk faked under it.
 *
 * The fake goes on after the nest, not before: nestedBatch seeds the product catalogue from
 * master_materials.csv on that same disk, and faking it first empties the file out from under the
 * seeder - see MasterMaterialsSeeder, which refuses to seed an empty catalogue.
 *
 * @return array{0: App\Models\Business, 1: App\Models\User, 2: Batch}
 */
function batchWithFakedDisk(): array
{
    $nested = nestedBatch([[2500, 5], [1500, 2]]);

    Storage::fake(MaterialCertificate::DISK);

    return $nested;
}

it('takes a certificate for one merchant on the batch and lists it back', function () {
    [$business, $user, $batch] = batchWithFakedDisk();

    $this->actingAs($user);

    $this->withoutExceptionHandling();

    /*
     * The steel merchant is offered and starts empty. A business whose plan covers timber as well
     * gets no timber block at all - those products carry no certificate, and a box asking for one
     * would be asking for paperwork that is never coming.
     */
    $answer = $this->getJson(route('batch.certificates.index', $batch))->assertOk()->json();

    $groups = collect($answer['groups']);

    expect($answer['batch_id'])->toBe($batch->id)
        ->and($groups->pluck('supplierGroup')->all())->toContain('STEEL_MERCHANT')
        ->and($groups->firstWhere('supplierGroup', 'STEEL_MERCHANT')['certificates'])->toBe([]);

    $this->post(route('batch.certificates.store', $batch), [
        'supplier_group' => 'STEEL_MERCHANT',
        'certificates' => [UploadedFile::fake()->create('heat-74412.pdf', 120, 'application/pdf')],
    ])->assertRedirect();

    $certificate = MaterialCertificate::query()->sole();

    expect($certificate->batch_id)->toBe($batch->id)
        //One parent or the other, never both: this one never had an order to belong to
        ->and($certificate->order_id)->toBeNull()
        ->and($certificate->supplier_group)->toBe('STEEL_MERCHANT')
        ->and($certificate->user_id)->toBe($user->id)
        ->and($certificate->original_filename)->toBe('heat-74412.pdf');

    //On the private disk, under a generated name - the uploader's filename decides nothing
    Storage::disk(MaterialCertificate::DISK)->assertExists($certificate->path);
    expect($certificate->path)->not->toContain('heat-74412.pdf');

    $steel = collect($this->getJson(route('batch.certificates.index', $batch))->json('groups'))
        ->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect($steel['certificates'])->toHaveCount(1)
        ->and($steel['certificates'][0]['filename'])->toBe('heat-74412.pdf')
        ->and($steel['certificates'][0]['uploaded_by'])->toBe($user->name)
        //Removable while the batch is live: this is somebody tidying up their own open job
        ->and($steel['certificates'][0]['deletable'])->toBeTrue();

    //And it comes back down, under the name it arrived with rather than the one it is stored as
    $this->get(route('material.certificates.download', $certificate))
        ->assertOk()
        ->assertDownload('heat-74412.pdf');
});

it('shows a batch certificate on the same order list block as the merchant it belongs to', function () {
    /*
     * The point of filing them by merchant. A shop that buys through the quotes screen gets the PDF
     * against the order, a shop that rings the merchant gets it against the batch, and whoever opens
     * the order list afterwards is looking for a heat number rather than an account of which screen
     * the file went in through - so the block shows both.
     */
    [$business, $user, $batch] = batchWithFakedDisk();

    [, $order] = quoteAndOrder($user, $batch, orderSent: true);

    MaterialCertificate::factory()->forOrder($order->id)->create([
        'user_id' => $user->id,
        'original_filename' => 'from-the-order.pdf',
    ]);

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->post(route('batch.certificates.store', $batch), [
        'supplier_group' => 'STEEL_MERCHANT',
        'certificates' => [UploadedFile::fake()->create('rung-the-merchant.pdf', 80, 'application/pdf')],
    ])->assertRedirect();

    $steel = collect($this->getJson(route('batch.order.list', $batch))->assertOk()->json('orderList.groups'))
        ->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect(collect($steel['certificates'])->pluck('filename')->all())
        ->toBe(['from-the-order.pdf', 'rung-the-merchant.pdf']);
});

it('would be a disaster if a certificate could be filed under a merchant the batch does not buy from', function () {
    /*
     * The group name arrives from the page. A file filed under a group this batch has nothing in is a
     * file nobody ever sees again - no block in the order list asks for it - so the name is checked
     * against the same groups the modal was drawn from.
     */
    [$business, $user, $batch] = batchWithFakedDisk();

    $this->actingAs($user);

    $this->post(route('batch.certificates.store', $batch), [
        'supplier_group' => 'NOT_A_MERCHANT',
        'certificates' => [UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf')],
    ])->assertStatus(422);

    expect(MaterialCertificate::query()->count())->toBe(0);
});

it('refuses anything that is not a certificate a merchant would send', function () {
    [$business, $user, $batch] = batchWithFakedDisk();

    $this->actingAs($user);

    //A spreadsheet is not evidence of a heat of steel, whatever it has been renamed to
    $this->post(route('batch.certificates.store', $batch), [
        'supplier_group' => 'STEEL_MERCHANT',
        'certificates' => [UploadedFile::fake()->create('cert.xlsx', 10, 'application/vnd.ms-excel')],
    ])->assertSessionHasErrors('certificates.0');

    expect(MaterialCertificate::query()->count())->toBe(0);
});

it('would be a disaster if another business could read or add this batch\'s certificates', function () {
    [$business, $user, $batch] = batchWithFakedDisk();

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->post(route('batch.certificates.store', $batch), [
        'supplier_group' => 'STEEL_MERCHANT',
        'certificates' => [UploadedFile::fake()->create('heat-74412.pdf', 40, 'application/pdf')],
    ])->assertRedirect();

    $certificate = MaterialCertificate::query()->sole();

    //The refusals below are the assertion, so the handler goes back on
    $this->withExceptionHandling();
    $this->actingAs(createUser(1, createBusiness('somebody else'), false, true));

    $this->getJson(route('batch.certificates.index', $batch))->assertForbidden();
    $this->post(route('batch.certificates.store', $batch), [
        'supplier_group' => 'STEEL_MERCHANT',
        'certificates' => [UploadedFile::fake()->create('theirs.pdf', 10, 'application/pdf')],
    ])->assertForbidden();

    //Nor read the file itself, the id being no kind of key - these live on the private disk
    $this->get(route('material.certificates.download', $certificate))->assertForbidden();
    $this->delete(route('material.certificates.destroy', $certificate))->assertForbidden();

    expect(MaterialCertificate::query()->count())->toBe(1);
});

it('keeps the certificates of a batch that has been closed', function () {
    /*
     * The same rule the order side draws at the moment an order is placed, drawn here where it starts
     * applying: a live batch is somebody's open job and a wrong file on it is tidying up, while a past
     * project is the record. ISO 9001 7.5.3.2 is explicit that documented information is protected
     * from unintended alteration - and the way to fix a wrong certificate is to attach the right one.
     */
    [$business, $user, $batch] = batchWithFakedDisk();

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->post(route('batch.certificates.store', $batch), [
        'supplier_group' => 'STEEL_MERCHANT',
        'certificates' => [UploadedFile::fake()->create('heat-74412.pdf', 40, 'application/pdf')],
    ])->assertRedirect();

    $certificate = MaterialCertificate::query()->sole();

    $batch->update(['done' => true]);

    //The refusals below are the assertion, so the handler goes back on
    $this->withExceptionHandling();

    expect($certificate->fresh()->isDeletable())->toBeFalse();

    //Answered rather than aborted: the refusal is a sentence about what to do instead
    $this->delete(route('material.certificates.destroy', $certificate))
        ->assertSessionHasErrors('certificate');

    //And nothing more goes on a closed batch either
    $this->post(route('batch.certificates.store', $batch), [
        'supplier_group' => 'STEEL_MERCHANT',
        'certificates' => [UploadedFile::fake()->create('late.pdf', 10, 'application/pdf')],
    ])->assertForbidden();

    expect(MaterialCertificate::query()->count())->toBe(1);
    Storage::disk(MaterialCertificate::DISK)->assertExists($certificate->path);
});

it('marks one merchant on the order list ordered, without placing anything or touching the others', function () {
    /*
     * The order list's own press, for the shop that is half on the application and half on the phone:
     * the steel goes through the quotes screen and the timber does not, so the batch is neither all
     * ordered nor not ordered and the batch-wide mark is the wrong size of answer.
     *
     * It writes a name onto the batch and nothing else - no order row, no quote, nobody contacted.
     */
    [$business, $user, $batch] = batchWithFakedDisk();

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $before = collect($this->getJson(route('batch.order.list', $batch))->json('orderList.groups'))
        ->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect($before['ordered'])->toBeFalse()
        ->and($before['canMarkOrdered'])->toBeTrue();

    $this->post(route('batch.group.ordered', $batch), ['supplier_group' => 'STEEL_MERCHANT'])
        ->assertRedirect();

    $after = collect($this->getJson(route('batch.order.list', $batch))->json('orderList.groups'))
        ->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect($after['ordered'])->toBeTrue()
        //Ordered, with nothing to show for it on the application - which is the honest reading
        ->and($after['orderedByMark'])->toBeTrue()
        ->and($after['purchaseOrderNumber'])->toBeNull()
        ->and($batch->refresh()->ordered_supplier_groups)->toBe(['STEEL_MERCHANT'])
        ->and($batch->orders()->count())->toBe(0)
        ->and($batch->quotes()->count())->toBe(0)
        //And the batch itself is untouched: one merchant is not the whole job
        ->and($batch->ordered_at)->toBeNull();
});

it('calls the batch ordered once every merchant on it has been marked', function () {
    /*
     * The card and the order list have to agree. A batch whose only block says "Ordered" has been
     * bought, however that was recorded - a pill still reading Quoting over it is the page
     * contradicting itself, and the first thing somebody would do about it is mark it again.
     *
     * Measured against the merchants this batch actually buys from, which is the card's own Material
     * order count, so a job with two merchants stays Quoting until both have been answered.
     */
    [$business, $user, $batch] = batchWithFakedDisk();

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $groups = collect($this->getJson(route('batch.order.list', $batch))->json('orderList.groups'))
        ->pluck('supplierGroup');

    //The fixture is steel alone - the test below it is the one that proves "every" means every
    expect($groups->all())->toBe(['STEEL_MERCHANT']);

    $this->post(route('batch.group.ordered', $batch), ['supplier_group' => 'STEEL_MERCHANT'])
        ->assertRedirect();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Illuminate\Testing\Fluent\AssertableJson $page) => $page
            ->where('batches.1.stage', 'ORDERED')
            /*
             * And the way back has closed with it, the way it closes on the batch-wide mark: the
             * steel is being cut at a merchant this application never saw.
             */
            ->where('batches.1.prerequisiteUndoStartQuoting', null)
            ->etc()
        );

    //The batch-wide mark is untouched - one merchant at a time is a different record of the same fact
    expect($batch->refresh()->ordered_at)->toBeNull()
        ->and($batch->ordered_supplier_groups)->toBe(['STEEL_MERCHANT']);

    //And the press itself is refused now, not merely undrawn
    $this->withExceptionHandling();
    $this->delete(route('batches.destroy', $batch))->assertForbidden();
});

it('calls every merchant on the batch ordered when the whole job is marked bought', function () {
    /*
     * The other direction of the same agreement. "All ordered" on the Nesting card is a claim about
     * the whole job, which is a claim about every merchant on it - so the order list cannot go on
     * showing blocks that say "Not ordered" with a Mark as ordered button beside them.
     *
     * Read off the batch rather than written across the groups when that press lands: it is one fact,
     * and a copy of it in a list of names would drift the moment the batch's material changed.
     */
    [$business, $user, $batch] = batchWithFakedDisk();

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->post(route('batch.all.ordered', $batch->id))->assertRedirect();

    $groups = collect($this->getJson(route('batch.order.list', $batch))->json('orderList.groups'));

    expect($groups)->not->toBeEmpty();

    foreach ($groups as $group) {
        expect($group['ordered'])->toBeTrue()
            //Bought, with nothing on the application to show for it - which is the honest reading
            ->and($group['orderedByMark'])->toBeTrue()
            ->and($group['purchaseOrderNumber'])->toBeNull();
    }

    //And nothing was written into the per-merchant list to say so
    expect($batch->refresh()->ordered_supplier_groups)->toBeNull();
});

it('would be a disaster if the open batch could have a merchant marked ordered', function () {
    /*
     * There is no batch to mark. That card is still taking uploads, its nest changes with the next
     * one, and anything bought off it would be bought twice - which is why the modal draws the list
     * under a "Do not order" watermark and offers neither Copy nor the mark.
     */
    [$business, $user] = batchWithFakedDisk();

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $orderList = $this->getJson(route('batch.order.list'))->assertOk()->json('orderList');

    expect($orderList['batch_id'])->toBeNull();

    foreach ($orderList['groups'] as $group) {
        expect($group['canMarkOrdered'])->toBeNull();
    }
});

it('would be a disaster if another business could mark a merchant on a batch ordered', function () {
    [$business, $user, $batch] = batchWithFakedDisk();

    $this->actingAs(createUser(1, createBusiness('somebody else'), false, true));

    $this->post(route('batch.group.ordered', $batch), ['supplier_group' => 'STEEL_MERCHANT'])
        ->assertForbidden();

    expect($batch->refresh()->ordered_supplier_groups)->toBeNull();
});

it('would be a disaster if a colleague with no job on the batch could mark a merchant ordered', function () {
    /*
     * The same line every press on a batch draws: you have to be a project manager on it. "Somebody
     * else's steel has been bought" is the worst thing a colleague could write onto a card, and it is
     * no less true said one merchant at a time.
     */
    [$business, $owner, $batch] = batchWithFakedDisk();

    $this->actingAs(createUser(2, $business, false, true));

    $this->post(route('batch.group.ordered', $batch), ['supplier_group' => 'STEEL_MERCHANT'])
        ->assertForbidden();

    expect($batch->refresh()->ordered_supplier_groups)->toBeNull();
});

it('marks one merchant on the order list quoted, without asking anybody for a price', function () {
    /*
     * The step the order list offers before the ordered one, and it exists for the same shop: the
     * steel goes out through the quotes screen and the timber is priced over the phone, so a batch
     * can be half priced and the batch-wide "All quoted" is the wrong size of answer.
     *
     * It writes a name onto the batch and nothing else - no quote row, no order, nobody contacted.
     */
    [$business, $user, $batch] = batchWithFakedDisk();

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $before = collect($this->getJson(route('batch.order.list', $batch))->json('orderList.groups'))
        ->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    //A nested batch nobody has a price for: the block offers the quoted mark, not the ordered one
    expect($before['quoted'])->toBeFalse()
        ->and($before['ordered'])->toBeFalse()
        ->and($before['canMarkQuoted'])->toBeTrue();

    $this->post(route('batch.group.quoted', $batch), ['supplier_group' => 'STEEL_MERCHANT'])
        ->assertRedirect();

    $after = collect($this->getJson(route('batch.order.list', $batch))->json('orderList.groups'))
        ->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect($after['quoted'])->toBeTrue()
        //Priced, and still unbought - which is the whole point of there being two marks
        ->and($after['ordered'])->toBeFalse()
        ->and($after['canMarkOrdered'])->toBeTrue()
        ->and($batch->refresh()->quoted_supplier_groups)->toBe(['STEEL_MERCHANT'])
        ->and($batch->quotes()->count())->toBe(0)
        ->and($batch->orders()->count())->toBe(0)
        //And the batch itself is untouched: one merchant is not the whole job
        ->and($batch->quoted_at)->toBeNull()
        ->and($batch->ordered_supplier_groups)->toBeNull();
});

it('calls the batch quoted once every merchant on it has been marked', function () {
    /*
     * The card and the order list have to agree about the prices the way they agree about the
     * buying: a batch whose only block says "Quoted" has its prices in, and a pill still reading
     * Quoting over it is the page contradicting itself.
     *
     * And the way back stays open, unlike the ordered mark's. Prices coming in changes nothing about
     * whether the nest can be thrown away - see PrerequisiteConditions, condition 9.
     */
    [$business, $user, $batch] = batchWithFakedDisk();

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->post(route('batch.group.quoted', $batch), ['supplier_group' => 'STEEL_MERCHANT'])
        ->assertRedirect();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Illuminate\Testing\Fluent\AssertableJson $page) => $page
            ->where('batches.1.stage', 'QUOTED')
            ->where('batches.1.prerequisiteUndoStartQuoting', true)
            ->etc()
        );

    //The batch-wide mark is untouched - one merchant at a time is a different record of the same fact
    expect($batch->refresh()->quoted_at)->toBeNull()
        ->and($batch->quoted_supplier_groups)->toBe(['STEEL_MERCHANT']);
});

it('calls every merchant on the batch quoted when the whole job is marked quoted', function () {
    /*
     * The other direction of the same agreement, and the same shape as the ordered one: "All quoted"
     * on the Nesting card is a claim about every merchant on the job, so no block may go on offering
     * to mark a price in that the card says is already in.
     */
    [$business, $user, $batch] = batchWithFakedDisk();

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->post(route('batch.all.quoted', $batch->id))->assertRedirect();

    $groups = collect($this->getJson(route('batch.order.list', $batch))->json('orderList.groups'));

    expect($groups)->not->toBeEmpty();

    foreach ($groups as $group) {
        expect($group['quoted'])->toBeTrue()
            //Priced and not bought: the block moves on to offering the ordered mark
            ->and($group['ordered'])->toBeFalse();
    }

    //And nothing was written into the per-merchant list to say so
    expect($batch->refresh()->quoted_supplier_groups)->toBeNull();
});

it('reads a merchant that has been bought as priced too', function () {
    /*
     * Nobody orders steel without knowing what it costs. A block showing "Ordered" over a "Mark as
     * quoted" button would be asking for the step behind it - which is exactly what a batch bought
     * through "All ordered" without anybody pressing quoted first would otherwise look like.
     */
    [$business, $user, $batch] = batchWithFakedDisk();

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->post(route('batch.group.ordered', $batch), ['supplier_group' => 'STEEL_MERCHANT'])
        ->assertRedirect();

    $group = collect($this->getJson(route('batch.order.list', $batch))->json('orderList.groups'))
        ->firstWhere('supplierGroup', 'STEEL_MERCHANT');

    expect($group['ordered'])->toBeTrue()
        ->and($group['quoted'])->toBeTrue()
        //Read off the order rather than written down: nothing says this merchant was ever quoted
        ->and($batch->refresh()->quoted_supplier_groups)->toBeNull();
});

it('would be a disaster if the open batch could have a merchant marked quoted', function () {
    /*
     * There is no batch to mark, and nothing on that card should be being priced yet: its nest is a
     * suggestion the next upload changes.
     */
    [$business, $user] = batchWithFakedDisk();

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $orderList = $this->getJson(route('batch.order.list'))->assertOk()->json('orderList');

    expect($orderList['batch_id'])->toBeNull();

    foreach ($orderList['groups'] as $group) {
        expect($group['canMarkQuoted'])->toBeNull();
    }
});

it('would be a disaster if a colleague with no job on the batch could mark a merchant quoted', function () {
    /*
     * The same line every press on a batch draws: you have to be a project manager on it. A price
     * written onto somebody else's steel is the step their own card is waiting on.
     */
    [$business, $owner, $batch] = batchWithFakedDisk();

    $this->actingAs(createUser(2, $business, false, true));

    $this->post(route('batch.group.quoted', $batch), ['supplier_group' => 'STEEL_MERCHANT'])
        ->assertForbidden();

    //And another business cannot reach the batch at all
    $this->actingAs(createUser(1, createBusiness('somebody else'), false, true));

    $this->post(route('batch.group.quoted', $batch), ['supplier_group' => 'STEEL_MERCHANT'])
        ->assertForbidden();

    expect($batch->refresh()->quoted_supplier_groups)->toBeNull();
});

it('leaves the certificates behind when the batch they belong to is re-nested', function () {
    /*
     * Re-nesting deletes the batch, its quotes, its draft orders and the offcuts it cut. A
     * certificate for steel that was never delivered, against a nest that no longer exists, goes with
     * them - the column cascades for the same reason order_id does.
     */
    [$business, $user, $batch] = batchWithFakedDisk();

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->post(route('batch.certificates.store', $batch), [
        'supplier_group' => 'STEEL_MERCHANT',
        'certificates' => [UploadedFile::fake()->create('heat-74412.pdf', 40, 'application/pdf')],
    ])->assertRedirect();

    expect(MaterialCertificate::query()->count())->toBe(1);

    $this->delete(route('batches.destroy', $batch))->assertRedirect();

    expect(Batch::query()->whereKey($batch->id)->exists())->toBeFalse()
        ->and(MaterialCertificate::query()->count())->toBe(0);
});
