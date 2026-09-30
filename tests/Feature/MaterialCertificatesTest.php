<?php

use App\Formatters\KanbanFormatter;
use App\Models\Batch;
use App\Models\MaterialCertificate;
use App\Models\Order;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * A delivered steel merchant order with nothing recorded against it either way.
 *
 * @return array{0: Batch, 1: Order}
 */
function deliveredSteelOrder(\App\Models\User $user): array
{
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);

    [, $order] = quoteAndOrder($user, $batch, quoteSent: true, orderSent: true);

    $order->update(['is_delivered' => true, 'material_cert_numbers' => null]);

    return [$batch, $order->fresh()];
}

it('attaches a certificate file to an order', function () {
    Storage::fake(MaterialCertificate::DISK);

    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    [, $order] = deliveredSteelOrder($user);

    $this->actingAs($user)
        ->post(route('material.certificates.store', $order), [
            'certificates' => [UploadedFile::fake()->create('heat-214887.pdf', 40, 'application/pdf')],
        ])
        ->assertRedirect();

    $certificate = $order->materialCertificates()->sole();

    //Kept under the name it arrived with, stored under a generated one
    expect($certificate->original_filename)->toBe('heat-214887.pdf')
        ->and($certificate->path)->not->toBe('heat-214887.pdf')
        ->and($certificate->user_id)->toBe($user->id);

    Storage::disk(MaterialCertificate::DISK)->assertExists($certificate->path);
});

it('would be a disaster if an attached certificate still counted as missing certs', function () {
    /*
     * The whole point of the file half. "Material Certs" was one text box, so a merchant who emails
     * the PDF and quotes no number left the board warning that this delivery was untraceable and
     * "Move to done" hidden behind that warning - with the certificate sitting right there.
     */
    Storage::fake(MaterialCertificate::DISK);

    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    [$batch, $order] = deliveredSteelOrder($user);
    $project = createProject($user);
    $piece = pieceOnBatch($project, $batch);
    $piece->order_id = $order->id;
    $piece->save();

    $this->actingAs($user);

    //Nothing recorded yet, so the warning is up
    $row = collect((new KanbanFormatter)->deliveredColumn($business))
        ->firstWhere('info.batch.id', $batch->id);
    expect($row['info']['steelMerchantDeliveredButNoCertsYet'])->toBeTrue();

    $this->post(route('material.certificates.store', $order), [
        'certificates' => [UploadedFile::fake()->create('cert.pdf', 10, 'application/pdf')],
    ])->assertRedirect();

    $row = collect((new KanbanFormatter)->deliveredColumn($business))
        ->firstWhere('info.batch.id', $batch->id);

    expect($row['info']['steelMerchantDeliveredButNoCertsYet'])->toBeFalse()
        //and it never needed a written reference to get there
        ->and($order->fresh()->material_cert_numbers)->toBeNull();
});

it('would be a disaster if an emptied reference box read as certified', function () {
    /*
     * scopeHasMaterialCerts asks whether the column is null. A "" saved through the order update
     * would satisfy that, and the board would drop its warning for a delivery with nothing recorded
     * against it at all.
     */
    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    [, $order] = deliveredSteelOrder($user);
    $order->update(['material_cert_numbers' => 'HEAT-1']);

    $this->actingAs($user)
        ->put(route('orders.update', $order), ['material_cert_numbers' => '   '])
        ->assertRedirect();

    expect($order->fresh()->material_cert_numbers)->toBeNull()
        ->and(Order::query()->whereKey($order->id)->hasMaterialCerts()->exists())->toBeFalse();
});

it('carries a file-only certificate into the traceability trail', function () {
    /*
     * The print spec and the offcuts table both read the trail off the batch. An order certified
     * only by an attached file has no cert numbers, so reading the numbers alone reported a
     * supplier against nothing - which on the spec sheet is indistinguishable from untraceable.
     */
    Storage::fake(MaterialCertificate::DISK);

    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    [$batch, $order] = deliveredSteelOrder($user);

    $this->actingAs($user)
        ->post(route('material.certificates.store', $order), [
            'certificates' => [UploadedFile::fake()->create('mill-cert.pdf', 10, 'application/pdf')],
        ])
        ->assertRedirect();

    $trail = $batch->fresh()->newStockOrdersWithCertificates();

    expect($trail)->toHaveCount(1)
        ->and($trail[0]['material_cert_numbers'])->toBeNull()
        ->and($trail[0]['material_cert_files'])->toHaveCount(1)
        ->and($trail[0]['material_cert_files'][0]['filename'])->toBe('mill-cert.pdf');
});

it('downloads a certificate under the name it arrived with', function () {
    Storage::fake(MaterialCertificate::DISK);

    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    [, $order] = deliveredSteelOrder($user);

    $this->actingAs($user)
        ->post(route('material.certificates.store', $order), [
            'certificates' => [UploadedFile::fake()->create('docket-scan.pdf', 10, 'application/pdf')],
        ]);

    $certificate = $order->materialCertificates()->sole();

    $this->get(route('material.certificates.download', $certificate))
        ->assertOk()
        ->assertDownload('docket-scan.pdf');
});

it('would be a disaster if another business could read a certificate', function () {
    /*
     * A mill certificate names the customer, the project and the heat of steel. They live on the
     * private disk so that holding the id is not holding the file - the only way in is this route,
     * and it has to answer to the order's owner.
     */
    Storage::fake(MaterialCertificate::DISK);

    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    $otherBusiness = createBusiness('rival', true);
    $outsider = createUser(2, $otherBusiness, false, true);

    [, $order] = deliveredSteelOrder($user);

    $this->actingAs($user)
        ->post(route('material.certificates.store', $order), [
            'certificates' => [UploadedFile::fake()->create('private.pdf', 10, 'application/pdf')],
        ]);

    $certificate = $order->materialCertificates()->sole();

    $this->actingAs($outsider)
        ->get(route('material.certificates.download', $certificate))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->delete(route('material.certificates.destroy', $certificate))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->post(route('material.certificates.store', $order), [
            'certificates' => [UploadedFile::fake()->create('theirs.pdf', 10, 'application/pdf')],
        ])
        ->assertForbidden();

    expect($order->materialCertificates()->count())->toBe(1);
});

it('takes the file off the disk when a certificate is removed', function () {
    /*
     * A row with no file behind it is a download that 404s. Removing one is the correction for a
     * cert attached to the wrong order, so it has to leave nothing behind.
     */
    Storage::fake(MaterialCertificate::DISK);

    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    [, $order] = deliveredSteelOrder($user);

    $this->actingAs($user)
        ->post(route('material.certificates.store', $order), [
            'certificates' => [UploadedFile::fake()->create('wrong-order.pdf', 10, 'application/pdf')],
        ]);

    $certificate = $order->materialCertificates()->sole();
    $path = $certificate->path;

    $this->delete(route('material.certificates.destroy', $certificate))->assertRedirect();

    Storage::disk(MaterialCertificate::DISK)->assertMissing($path);
    expect(MaterialCertificate::query()->whereKey($certificate->id)->exists())->toBeFalse();
});

it('refuses a file that is not a certificate', function () {
    Storage::fake(MaterialCertificate::DISK);

    $business = createBusiness('biz', true);
    $user = createUser(1, $business, false, true);

    [, $order] = deliveredSteelOrder($user);

    $this->actingAs($user)
        ->post(route('material.certificates.store', $order), [
            'certificates' => [UploadedFile::fake()->create('macros.exe', 10, 'application/octet-stream')],
        ])
        ->assertSessionHasErrors('certificates.0');

    expect($order->materialCertificates()->count())->toBe(0);
});

it('would be a disaster if one merchant\'s two file-only certificates collapsed into one', function () {
    /*
     * The offcut trail is keyed on the supplier/certificate PAIR, so a merchant that supplied two of
     * the source batches keeps both. That key was "name\0numbers" - and two orders certified only by
     * a PDF have no numbers at all, so both key on "name\0null" and one of the two certificates
     * silently leaves the traceability trail.
     */
    Storage::fake(MaterialCertificate::DISK);

    $user = offcutsIndexUser();
    $this->actingAs($user);

    $supplier = Supplier::factory()->create(['name' => 'One Steel']);

    $batch = batchWithDeliveredOrder($user, 'CERT-NEW');

    //Two older batches from the same merchant, each certified by a file and nothing else
    foreach (['first.pdf', 'second.pdf'] as $filename) {
        $olderBatch = batchWithDeliveredOrder($user, null, $supplier);

        $this->post(route('material.certificates.store', $olderBatch->orders()->sole()), [
            'certificates' => [UploadedFile::fake()->create($filename, 10, 'application/pdf')],
        ])->assertRedirect();

        $consumed = create_offcut_200PFC(900, $olderBatch->id);
        $consumed->batch_to_id = $batch->id;
        $consumed->save();
    }

    create_offcut_200PFC(1500, $batch->id);

    $trail = $batch->fresh()->offcutOrdersWithCertificates($user->business);

    expect($trail['used_offcuts'])->toBeTrue()
        ->and($trail['certificates'])->toHaveCount(2)
        ->and(collect($trail['certificates'])->pluck('material_cert_files.0.filename')->sort()->values()->all())
        ->toBe(['first.pdf', 'second.pdf']);
});
