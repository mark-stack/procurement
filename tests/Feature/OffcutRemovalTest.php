<?php

use App\Enums\OffcutRemovalEnums;
use App\Enums\ProductEnums;
use App\Formatters\UniqueLetterIDGenerator;
use App\Models\Batch;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * A user whose business is set up, with one delivered batch behind them - which is what puts an
 * offcut in the yard in the first place. batchWithDeliveredOrder() and offcutsIndexUser() are
 * defined in OffcutsIndexTest, which Pest loads alongside this file.
 *
 * @return array{0: User, 1: Batch}
 */
function userWithDeliveredBatch(?string $cert = null, ?Supplier $supplier = null): array
{
    $user = offcutsIndexUser();

    return [$user, batchWithDeliveredOrder($user, $cert, $supplier)];
}

it('takes an offcut out of inventory, recording who said so and why', function () {
    [$user, $batch] = userWithDeliveredBatch();
    $offcut = create_offcut_200PFC(2400, $batch->id);

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->post(route('offcuts.remove', $offcut->id), [
        'reason' => OffcutRemovalEnums::TAKEN->value,
        'note' => 'Dave cut it up for the Jarrah St handrails',
    ])->assertRedirect();

    $offcut->refresh();

    expect($offcut->isRemoved())->toBeTrue()
        ->and($offcut->removed_by_user_id)->toBe($user->id)
        ->and($offcut->removed_reason)->toBe(OffcutRemovalEnums::TAKEN->value)
        ->and($offcut->removed_note)->toBe('Dave cut it up for the Jarrah St handrails');
});

it('keeps a removed offcut out of the inventory a nest is built from', function () {
    /*
     * The whole point. Business::availableOffcuts is the single list both the offcuts page and
     * NestingFormatter draw on, so a nest that still offered removed steel would cost the next job a
     * bar it had been told it already owned.
     */
    [$user, $batch] = userWithDeliveredBatch();
    $stolen = create_offcut_200PFC(2400, $batch->id);
    $stillThere = create_offcut_200PFC(1800, $batch->id);

    $business = $user->business;

    expect($business->availableOffcuts()->pluck('id')->all())
        ->toEqualCanonicalizing([$stolen->id, $stillThere->id]);

    $stolen->removeFromInventory($user, OffcutRemovalEnums::TAKEN);

    expect($business->availableOffcuts()->pluck('id')->all())->toBe([$stillThere->id])
        ->and($business->removedOffcuts()->pluck('id')->all())->toBe([$stolen->id]);
});

it('does not count a removed offcut as consumed by a batch', function () {
    /*
     * batch_to_id means "this batch cut it up", and the nesting totals are balanced against it -
     * offcuts used, scrap, kerf and cuts have to add up to what was bought. Steel that walked out of
     * the yard was not cut here, so writing a batch onto it would put a bar's worth of material into
     * a batch that never received it.
     */
    [$user, $batch] = userWithDeliveredBatch();
    $offcut = create_offcut_200PFC(2400, $batch->id);

    $offcut->removeFromInventory($user, OffcutRemovalEnums::DAMAGED);

    expect($offcut->refresh()->batch_to_id)->toBeNull()
        ->and($offcut->piece_to_id)->toBeNull();
});

it('leaves the offcuts cut from a removed one exactly where they are', function () {
    /*
     * A removal says nothing about steel that was cut off this piece before it went missing - that
     * steel is in its own rack, and the removed row is what carries its certificate trail.
     */
    [$user, $rootBatch] = userWithDeliveredBatch('CERT-ROOT', Supplier::factory()->create(['name' => 'Root Steel']));
    $chain = offcutGenerations($user, $rootBatch, 3);
    $deepest = end($chain);

    //The first generation has already been consumed by the batch that cut the second out of it
    $consumedAncestor = $chain[0];
    $consumedAncestor->removeFromInventory($user, OffcutRemovalEnums::MISSING);

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('offcuts.data', 1)
            ->where('offcuts.data.0.id', $deepest->id)
            ->where('offcuts.data.0.generation', 3)
            ->where('offcuts.data.0.offcutOrdersWithCertificates.certificates', [
                ['supplier_name' => 'Root Steel', 'material_cert_numbers' => 'CERT-ROOT'],
            ])
        );
});

it('lists a removed offcut with what happened to it and who said so', function () {
    [$user, $batch] = userWithDeliveredBatch();
    $offcut = create_offcut_200PFC(2400, $batch->id);

    $offcut->removeFromInventory($user, OffcutRemovalEnums::TAKEN, 'Taken for the awning job');

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('offcuts.data', 0)
            ->has('removedOffcuts.data', 1)
            ->where('removedOffcuts.data.0.id', $offcut->id)
            ->where('removedOffcuts.data.0.removed_reason', OffcutRemovalEnums::TAKEN->value)
            ->where('removedOffcuts.data.0.removed_reason_label', 'Taken by someone else')
            ->where('removedOffcuts.data.0.removed_note', 'Taken for the awning job')
            ->where('removedOffcuts.data.0.removed_by', $user->name)
            ->where('removedTotal', 1)
        );
});

it('puts a removed offcut back, forgetting why it was ever out', function () {
    [$user, $batch] = userWithDeliveredBatch();
    $offcut = create_offcut_200PFC(2400, $batch->id);
    $offcut->removeFromInventory($user, OffcutRemovalEnums::MISSING, 'Nowhere in the yard');

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->post(route('offcuts.restore', $offcut->id))->assertRedirect();

    $offcut->refresh();

    expect($offcut->isRemoved())->toBeFalse()
        ->and($offcut->removed_by_user_id)->toBeNull()
        ->and($offcut->removed_reason)->toBeNull()
        //A row back in inventory carrying an explanation of why it is not there
        ->and($offcut->removed_note)->toBeNull()
        ->and($user->business->availableOffcuts()->pluck('id')->all())->toBe([$offcut->id]);
});

it('never hands a removed offcut\'s mark to another piece of steel', function () {
    /*
     * The mark is written on the steel by hand, and the steel is still somewhere - in a boilermaker's
     * rack, or cut into a handrail with its paperwork still naming that mark. Recycling it would put
     * two different pieces behind one set of certificates.
     */
    [$user, $batch] = userWithDeliveredBatch();
    $offcut = create_offcut_200PFC(2400, $batch->id);
    $takenMark = $offcut->unique_mark;

    $offcut->removeFromInventory($user, OffcutRemovalEnums::TAKEN);

    $generator = new UniqueLetterIDGenerator;

    foreach (range(1, 25) as $ignored) {
        expect($generator->generate(ProductEnums::PFC->value, $user->business_id))->not->toBe($takenMark);
    }
});

it('refuses a reason it does not recognise', function () {
    [$user, $batch] = userWithDeliveredBatch();
    $offcut = create_offcut_200PFC(2400, $batch->id);

    $this->actingAs($user);

    $this->post(route('offcuts.remove', $offcut->id), ['reason' => 'BECAUSE'])
        ->assertSessionHasErrors('reason');

    expect($offcut->refresh()->isRemoved())->toBeFalse();
});

it('insists on a note when the reason is "Other"', function () {
    /*
     * "Other" with nothing beside it records that something happened and nothing about what, which is
     * the one removal nobody can make sense of later. Every other reason says enough on its own.
     */
    [$user, $batch] = userWithDeliveredBatch();
    $offcut = create_offcut_200PFC(2400, $batch->id);

    $this->actingAs($user);

    $this->post(route('offcuts.remove', $offcut->id), [
        'reason' => OffcutRemovalEnums::OTHER->value,
        'note' => '   ',
    ])->assertSessionHasErrors('note');

    expect($offcut->refresh()->isRemoved())->toBeFalse();

    $this->post(route('offcuts.remove', $offcut->id), [
        'reason' => OffcutRemovalEnums::OTHER->value,
        'note' => 'Went back to the merchant on a credit',
    ])->assertRedirect();

    expect($offcut->refresh()->isRemoved())->toBeTrue();
});

it('takes a note on any other reason without demanding one', function () {
    [$user, $batch] = userWithDeliveredBatch();
    $offcut = create_offcut_200PFC(2400, $batch->id);

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->post(route('offcuts.remove', $offcut->id), [
        'reason' => OffcutRemovalEnums::MISSING->value,
    ])->assertRedirect();

    expect($offcut->refresh()->isRemoved())->toBeTrue()
        ->and($offcut->removed_note)->toBeNull();
});

it('will not let one business remove another business\'s steel', function () {
    [$user] = userWithDeliveredBatch();

    $otherBusiness = createBusiness('other', true);
    $otherUser = createUser(2, $otherBusiness, false, true);
    $theirOffcut = create_offcut_200PFC(2400, batchWithDeliveredOrder($otherUser)->id);

    $this->actingAs($user);

    $this->post(route('offcuts.remove', $theirOffcut->id), [
        'reason' => OffcutRemovalEnums::TAKEN->value,
    ])->assertNotFound();

    expect($theirOffcut->refresh()->isRemoved())->toBeFalse();
});

it('will not remove an offcut a nest has already cut up', function () {
    //It is not in inventory to be taken out of - the batch that consumed it turned it into pieces
    [$user, $batch] = userWithDeliveredBatch();
    $offcut = create_offcut_200PFC(2400, $batch->id);

    $offcut->batch_to_id = Batch::factory()->forUser($user->id)->create()->id;
    $offcut->save();

    $this->actingAs($user);

    $this->post(route('offcuts.remove', $offcut->id), [
        'reason' => OffcutRemovalEnums::TAKEN->value,
    ])->assertNotFound();
});

it('will not remove the same offcut twice', function () {
    //Two people on the page at once, both pressing Remove on the row nobody can find
    [$user, $batch] = userWithDeliveredBatch();
    $offcut = create_offcut_200PFC(2400, $batch->id);

    $offcut->removeFromInventory($user, OffcutRemovalEnums::MISSING);
    $removedAt = $offcut->removed_at;

    $this->actingAs($user);

    $this->post(route('offcuts.remove', $offcut->id), [
        'reason' => OffcutRemovalEnums::TAKEN->value,
    ])->assertNotFound();

    //The first person's reason stands, rather than being overwritten by the second's
    expect($offcut->refresh()->removed_reason)->toBe(OffcutRemovalEnums::MISSING->value)
        ->and($offcut->removed_at->toIso8601String())->toBe($removedAt->toIso8601String());
});

it('will not put back an offcut that was never removed', function () {
    [$user, $batch] = userWithDeliveredBatch();
    $offcut = create_offcut_200PFC(2400, $batch->id);

    $this->actingAs($user);

    $this->post(route('offcuts.restore', $offcut->id))->assertNotFound();
});

it('will not let one business put back another business\'s steel', function () {
    [$user] = userWithDeliveredBatch();

    $otherBusiness = createBusiness('other', true);
    $otherUser = createUser(2, $otherBusiness, false, true);
    $theirOffcut = create_offcut_200PFC(2400, batchWithDeliveredOrder($otherUser)->id);
    $theirOffcut->removeFromInventory($otherUser, OffcutRemovalEnums::TAKEN);

    $this->actingAs($user);

    $this->post(route('offcuts.restore', $theirOffcut->id))->assertNotFound();

    expect($theirOffcut->refresh()->isRemoved())->toBeTrue();
});

it('lets any colleague remove an offcut, not only whoever nested the batch', function () {
    /*
     * The steel is the business's, and the person who nested the batch that produced an offcut is rarely
     * the person who walks past its empty rack. An inventory only its original project manager can
     * correct is an inventory that stays wrong - so the row records who, rather than the route
     * refusing them.
     */
    [$owner, $batch] = userWithDeliveredBatch();
    $offcut = create_offcut_200PFC(2400, $batch->id);

    $colleague = createUser(2, $owner->business, false, true);

    $this->actingAs($colleague);
    $this->withoutExceptionHandling();

    $this->post(route('offcuts.remove', $offcut->id), [
        'reason' => OffcutRemovalEnums::TAKEN->value,
    ])->assertRedirect();

    expect($offcut->refresh()->removed_by_user_id)->toBe($colleague->id);
});

it('caps the removed list but says how many there are', function () {
    [$user, $batch] = userWithDeliveredBatch();

    //Removals are never deleted, so this list only ever grows
    foreach (range(1, 52) as $n) {
        create_offcut_200PFC(1000 + $n, $batch->id)
            ->removeFromInventory($user, OffcutRemovalEnums::TAKEN);
    }

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('removedOffcuts.data', 50)
            ->where('removedTotal', 52)
        );
});

it('ships the reasons the picker offers, worded once, server side', function () {
    [$user] = userWithDeliveredBatch();

    $this->actingAs($user);
    $this->withoutExceptionHandling();

    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('removalReasons', count(OffcutRemovalEnums::cases()))
            ->where('removalReasons.0.value', OffcutRemovalEnums::TAKEN->value)
            ->where('removalReasons.0.label', 'Taken by someone else')
            ->where('removalReasons.0.requiresNote', false)
        );
});

it('does not grow the offcuts page query count with the number of removals', function () {
    //The removed list is a second pass over the same page - it must not cost a query per row either
    [$user, $batch] = userWithDeliveredBatch('CERT-NEW');
    create_offcut_200PFC(2400, $batch->id)->removeFromInventory($user, OffcutRemovalEnums::TAKEN);

    $this->actingAs($user);

    //The first request of a process resolves shared props that later ones reuse, so warm up first
    $this->get(route('offcuts.index'))->assertOk();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $this->get(route('offcuts.index'))->assertOk();
    $queriesForOne = $queries;

    foreach (range(1, 19) as $n) {
        create_offcut_200PFC(1000 + $n, $batch->id)
            ->removeFromInventory($user, OffcutRemovalEnums::MISSING);
    }

    $queries = 0;
    $this->get(route('offcuts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('removedOffcuts.data', 20));

    expect($queries)->toBe($queriesForOne);
});
