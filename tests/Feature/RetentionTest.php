<?php

use App\Models\Batch;
use App\Models\MaterialCertificate;
use App\Models\RecordChange;
use App\Models\RecordDisposition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * A certificate with a file behind it, attached on a given day.
 */
function certificateAttachedOn(\App\Models\User $user, string $when): MaterialCertificate
{
    $batch = Batch::factory()->forUser($user->id)->create(['done' => false]);
    [, $order] = quoteAndOrder($user, $batch, quoteSent: true, orderSent: true);

    $certificate = MaterialCertificate::factory()->create([
        'order_id' => $order->id,
        'user_id' => $user->id,
        'created_at' => $when,
        'updated_at' => $when,
    ]);

    Storage::disk(MaterialCertificate::DISK)->put($certificate->path, 'a pdf from the mill');

    return $certificate;
}

it('reviews what is past its retention period and disposes of nothing', function () {
    Storage::fake(MaterialCertificate::DISK);

    $user = createUser(1, createBusiness('biz'), false, true);
    $expired = certificateAttachedOn($user, now()->subDays(2600)->toDateTimeString());

    /*
     * The normal use of this command, and the reason it is a command rather than a schedule: it
     * reads the policy, counts what is eligible and stops.
     */
    $this->artisan('records:dispose')
        ->expectsOutputToContain('change-log')
        ->expectsOutputToContain('certificates')
        ->expectsOutputToContain('telescope')
        ->expectsOutputToContain('done-projects')
        ->assertSuccessful();

    expect($expired->fresh()->file_disposed_at)->toBeNull()
        ->and(RecordDisposition::count())->toBe(0);

    Storage::disk(MaterialCertificate::DISK)->assertExists($expired->path);
});

it('refuses to dispose of anything without a named person and a reason', function () {
    Storage::fake(MaterialCertificate::DISK);

    $user = createUser(1, createBusiness('biz'), false, true);
    $expired = certificateAttachedOn($user, now()->subDays(2600)->toDateTimeString());

    $this->artisan('records:dispose', ['class' => 'certificates', '--force' => true])
        ->assertFailed();

    //And neither half on its own is enough
    $this->artisan('records:dispose', [
        'class' => 'certificates',
        '--authorised-by' => 'A. Quality Manager',
        '--force' => true,
    ])->assertFailed();

    expect($expired->fresh()->file_disposed_at)->toBeNull()
        ->and(RecordDisposition::count())->toBe(0);
});

it('disposes of an expired certificate file, keeps the row, and records the act', function () {
    Storage::fake(MaterialCertificate::DISK);

    $business = createBusiness('biz');
    $user = createUser(1, $business, false, true);

    $expired = certificateAttachedOn($user, now()->subDays(2600)->toDateTimeString());
    $recent = certificateAttachedOn($user, now()->subDays(30)->toDateTimeString());

    $this->artisan('records:dispose', [
        'class' => 'certificates',
        '--authorised-by' => $user->email,
        '--reason' => 'Seven-year period expired; reviewed against the 2019 job files',
        '--force' => true,
    ])->assertSuccessful();

    //The file is gone and the account of it is not: the row still names the heat, the merchant and
    //who attached it, which is the half that is kept for ever
    Storage::disk(MaterialCertificate::DISK)->assertMissing($expired->path);

    $expired->refresh();

    expect($expired->exists)->toBeTrue()
        ->and($expired->original_filename)->not->toBeNull()
        ->and($expired->fileWasDisposedOf())->toBeTrue();

    //Everything still inside its period is untouched
    Storage::disk(MaterialCertificate::DISK)->assertExists($recent->path);
    expect($recent->fresh()->file_disposed_at)->toBeNull();

    $disposition = RecordDisposition::sole();

    expect($disposition->record_class)->toBe('certificates')
        ->and($disposition->method)->toBe(RecordDisposition::BY_APPLICATION)
        ->and($disposition->eligible)->toBe(1)
        ->and($disposition->disposed)->toBe(1)
        //The rule as it stood on the day, copied rather than referenced
        ->and($disposition->retain_days)->toBe((int) config('retention.classes.certificates.retain_days'))
        //Named twice: the account, and the string that survives the account being deleted
        ->and($disposition->authorised_by_user_id)->toBe($user->id)
        ->and($disposition->authorised_by)->toBe($user->email)
        ->and($disposition->reason)->toContain('Seven-year period expired');

    //And the disposal is an event against the certificate too, not only against the policy
    $change = RecordChange::query()
        ->forRecord($expired->getMorphClass(), $expired->getKey())
        ->first();

    expect($change->event)->toBe(RecordChange::UPDATED)
        ->and($change->changes)->toHaveKey('file_disposed_at');
});

it('names a person who has no account here', function () {
    Storage::fake(MaterialCertificate::DISK);

    $user = createUser(1, createBusiness('biz'), false, true);
    certificateAttachedOn($user, now()->subDays(2600)->toDateTimeString());

    $this->artisan('records:dispose', [
        'class' => 'certificates',
        '--authorised-by' => 'A. Quality Manager',
        '--reason' => 'Period expired',
        '--force' => true,
    ])->assertSuccessful();

    $disposition = RecordDisposition::sole();

    //A quality manager with no login here is a perfectly good authoriser
    expect($disposition->authorised_by)->toBe('A. Quality Manager')
        ->and($disposition->authorised_by_user_id)->toBeNull();
});

it('records nothing where nothing has expired', function () {
    Storage::fake(MaterialCertificate::DISK);

    $user = createUser(1, createBusiness('biz'), false, true);
    certificateAttachedOn($user, now()->subDays(30)->toDateTimeString());

    $this->artisan('records:dispose', [
        'class' => 'certificates',
        '--authorised-by' => 'A. Quality Manager',
        '--reason' => 'Nothing to do',
        '--force' => true,
    ])->assertSuccessful();

    //A disposal of nothing is not an act, and a log of them would bury the ones that happened
    expect(RecordDisposition::count())->toBe(0);
});

it('authorises a change log purge rather than carrying one out', function () {
    $user = createUser(1, createBusiness('biz'), false, true);

    RecordChange::create([
        'record_type' => 'order',
        'record_id' => 1,
        'event' => RecordChange::UPDATED,
        'changes' => ['purchase_order_number' => [null, 'PO-1001']],
        'user_id' => $user->id,
        'created_at' => now()->subDays(2600),
    ]);

    $this->artisan('records:dispose', [
        'class' => 'change-log',
        '--authorised-by' => 'A. Quality Manager',
        '--reason' => 'Seven-year period expired',
        '--force' => true,
    ])
        //The statement is handed over rather than run
        ->expectsOutputToContain('DELETE FROM `record_changes`')
        ->assertSuccessful();

    $disposition = RecordDisposition::sole();

    expect($disposition->method)->toBe(RecordDisposition::BY_DBA)
        ->and($disposition->eligible)->toBe(1)
        //This row is the authorisation, not the deed
        ->and($disposition->disposed)->toBe(0)
        ->and($disposition->wasCarriedOutHere())->toBeFalse();

    //Nothing was deleted - the model refuses, and the command is given no way around it
    expect(RecordChange::count())->toBe(1);
});

it('refuses the two classes it does not dispose of, and says why', function () {
    //Telescope entries are not records - theirs is the one automatic disposal, so there is nothing
    //to authorise
    $this->artisan('records:dispose', [
        'class' => 'telescope',
        '--authorised-by' => 'A. Quality Manager',
        '--reason' => 'Tidy up',
        '--force' => true,
    ])->assertFailed();

    //And a bulk delete of customers' projects is not a button worth having
    $this->artisan('records:dispose', [
        'class' => 'done-projects',
        '--authorised-by' => 'A. Quality Manager',
        '--reason' => 'Tidy up',
        '--force' => true,
    ])->assertFailed();

    $this->artisan('records:dispose', ['class' => 'nonsense'])->assertFailed();

    expect(RecordDisposition::count())->toBe(0);
});

it('will not let a disposal be edited or unrecorded', function () {
    $disposition = RecordDisposition::create([
        'record_class' => 'certificates',
        'retain_days' => 2557,
        'cutoff' => now()->subDays(2557),
        'eligible' => 4,
        'disposed' => 4,
        'method' => RecordDisposition::BY_APPLICATION,
        'authorised_by' => 'A. Quality Manager',
        'reason' => 'Period expired',
        'created_at' => now(),
    ]);

    //A record of a disposal that can be rewritten afterwards is not evidence of anything
    expect(fn () => $disposition->update(['disposed' => 0]))->toThrow(LogicException::class)
        ->and(fn () => $disposition->delete())->toThrow(LogicException::class);

    expect(RecordDisposition::sole()->disposed)->toBe(4);
});

it('answers a download of a disposed certificate with 410 rather than 404', function () {
    Storage::fake(MaterialCertificate::DISK);

    $user = createUser(1, createBusiness('biz'), false, true);
    $certificate = certificateAttachedOn($user, now()->subDays(2600)->toDateTimeString());

    $certificate->disposeOfFile();

    /*
     * The distinction is the whole reason the column exists. 404 reads as "this was never here" and
     * sends somebody looking for a bug; 410 with the date reads as what it is.
     */
    $this->actingAs($user)
        ->get(route('material.certificates.download', $certificate))
        ->assertStatus(410);
});

it('has a retention period and a named disposer for every class it covers', function () {
    $classes = config('retention.classes');

    //The four the policy covers. A class added here without a period is the gap the whole document
    //exists to close
    expect(array_keys($classes))
        ->toEqualCanonicalizing(['change-log', 'certificates', 'telescope', 'done-projects']);

    foreach ($classes as $key => $rule) {
        expect($rule['retain_days'])->toBeInt()->toBeGreaterThan(0)
            ->and($rule['basis'])->toBeString()->not->toBeEmpty()
            ->and($rule['disposed_by'])->toBeIn(['application', 'dba', 'automatic', 'owner'], "{$key} has no disposer");
    }

    //Seven days, and the nightly prune is wired to this number rather than to a literal
    expect($classes['telescope']['retain_days'])->toBe(7);
});
