<?php

use App\Enums\OffcutRemovalEnums;
use App\Models\Offcut;
use App\Models\Product;
use App\Notifications\OffcutCleanoutDue;
use App\Services\OffcutCleanout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * An offcut that has been sitting in the rack for a given number of days.
 *
 * batchWithDeliveredOrder() and offcutsIndexUser() are defined in OffcutsIndexTest, which Pest
 * loads alongside this file; create_offcut_200PFC() is in Pest.php.
 */
function agedOffcut(int $lengthMm, int $daysOld, int $batchId): Offcut
{
    $offcut = create_offcut_200PFC($lengthMm, $batchId);

    //Written straight to the column: Eloquent only stamps created_at on insert, so this sticks
    $offcut->created_at = now()->subDays($daysOld);
    $offcut->save();

    return $offcut->fresh();
}

/**
 * A second yard. offcutsIndexUser() always builds "biz", and a user's email is its id at the
 * business domain, so calling it twice collides on users.email.
 */
function secondYardUser(): App\Models\User
{
    return createUser(1, createBusiness('other-biz'), false, true);
}

/**
 * A catalogue entry for the 200PFC the test offcuts are cut from, at a chosen mass per metre.
 *
 * The mass is the whole point: it is what decides how long a remnant of this section has to be
 * before it pays for the labour of keeping it.
 */
function catalogue200PFC(float $kgPerM, string $nominalLength = '9000'): Product
{
    return Product::factory()->create([
        'kg_per_m' => $kgPerM,
        'nominal_length' => $nominalLength,
    ]);
}

it('proposes an offcut that is both past its shelf life and too short to pay for its keep', function () {
    $user = offcutsIndexUser();
    $batch = batchWithDeliveredOrder($user);

    //Light angle at 5.7kg/m pays for its keep from about 2,070mm against a 9m bar
    catalogue200PFC(5.7);

    $deadStock = agedOffcut(1200, 400, $batch->id);

    $candidates = (new OffcutCleanout)->candidates($user->business);

    expect($candidates)->toHaveCount(1)
        ->and($candidates->first()['offcut']->id)->toBe($deadStock->id)
        ->and($candidates->first()['floor_mm'])->toBeGreaterThan(1200)
        //The two figures that put it on the list: it costs more to keep than it is worth
        ->and($candidates->first()['keep_cost'])->toBeGreaterThan($candidates->first()['worth'])
        ->and($candidates->first()['net_drain'])->toBeGreaterThan(0);
});

it('leaves a short offcut alone until it has actually sat there', function () {
    /*
     * Age is half the rule and it is the half that carries the judgement. A 1.2m stub that a nest
     * draws on next month cost nobody anything - it is the identical stub still there a year later
     * that has been paid for several times over in handling.
     */
    $user = offcutsIndexUser();
    $batch = batchWithDeliveredOrder($user);

    catalogue200PFC(5.7);

    agedOffcut(1200, 30, $batch->id);

    expect((new OffcutCleanout)->candidates($user->business))->toHaveCount(0);
});

it('leaves an old offcut alone when it is long enough to pay for its keep', function () {
    $user = offcutsIndexUser();
    $batch = batchWithDeliveredOrder($user);

    catalogue200PFC(5.7);

    //Well past the ~2,070mm floor for this section, however long it has sat
    agedOffcut(4000, 800, $batch->id);

    expect((new OffcutCleanout)->candidates($user->business))->toHaveCount(0);
});

it('would be a disaster if the same length were dead stock on a beam as on light angle', function () {
    /*
     * The whole reason this is not a single millimetre threshold. At the default coefficients a
     * remnant pays for its keep from about 2,070mm of 5.7kg/m angle and from about 1,030mm of
     * 90kg/m beam, because a metre of beam is worth far more than the quarter hour it takes to deal
     * with. A flat rule scraps beam that was worth hundreds, or hoards angle worth nine dollars.
     */
    $lightUser = offcutsIndexUser();
    $lightBatch = batchWithDeliveredOrder($lightUser);
    catalogue200PFC(5.7);
    agedOffcut(1500, 400, $lightBatch->id);

    expect((new OffcutCleanout)->candidates($lightUser->business))->toHaveCount(1);

    //Same spec, same length, same age - a heavier section
    Product::query()->delete();
    $heavyUser = secondYardUser();
    $heavyBatch = batchWithDeliveredOrder($heavyUser);
    catalogue200PFC(90.0);
    agedOffcut(1500, 400, $heavyBatch->id);

    expect((new OffcutCleanout)->candidates($heavyUser->business))->toHaveCount(0);
});

it('values a section the catalogue no longer carries against a full stock length, not against itself', function () {
    /*
     * A regression. The reference length is what "as good as stock" means on the retention curve,
     * and falling back to the offcut's OWN length puts every row at the top of the curve - a 1.2m
     * stub valued as highly as a 12m bar, retention pinned at the cap, and nothing ever proposed.
     *
     * No product matches this spec here (the products table is empty), which is what happens for
     * real when a product's spec columns are edited after the steel was cut - see ProductSpec.
     */
    $user = offcutsIndexUser();
    $batch = batchWithDeliveredOrder($user);

    agedOffcut(1200, 400, $batch->id);

    $candidates = (new OffcutCleanout)->candidates($user->business);

    expect($candidates)->toHaveCount(1)
        //Costed at the business default mass, and the page has to be able to say so
        ->and($candidates->first()['kg_per_m_resolved'])->toBeFalse();
});

it('never proposes steel a nest has already eaten or somebody has already removed', function () {
    $user = offcutsIndexUser();
    $batch = batchWithDeliveredOrder($user);

    catalogue200PFC(5.7);

    $consumed = agedOffcut(1200, 400, $batch->id);
    $removed = agedOffcut(1200, 400, $batch->id);
    $stillThere = agedOffcut(1200, 400, $batch->id);

    $consumed->batch_to_id = batchWithDeliveredOrder($user)->id;
    $consumed->save();

    $removed->removeFromInventory($user, OffcutRemovalEnums::MISSING);

    expect((new OffcutCleanout)->candidates($user->business)->pluck('offcut.id')->all())
        ->toBe([$stillThere->id]);
});

//Quarterly notice
it('raises one cleanout notice per business per quarter', function () {
    $user = offcutsIndexUser();
    $batch = batchWithDeliveredOrder($user);

    catalogue200PFC(5.7);
    agedOffcut(1200, 400, $batch->id);

    $this->artisan('offcuts:cleanout')->assertSuccessful();

    //And again, the way a re-run after a failed night would
    $this->artisan('offcuts:cleanout')->assertSuccessful();

    $notifications = $user->fresh()->notifications()
        ->where('type', OffcutCleanoutDue::class)
        ->get();

    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->data['count'])->toBe(1)
        ->and($notifications->first()->data['net_drain'])->toBeGreaterThan(0);
});

it('says nothing when the rack has nothing on it worth clearing', function () {
    $user = offcutsIndexUser();
    $batch = batchWithDeliveredOrder($user);

    catalogue200PFC(5.7);

    //Old, but long enough to earn its place
    agedOffcut(4000, 800, $batch->id);

    $this->artisan('offcuts:cleanout')->assertSuccessful();

    expect($user->fresh()->notifications()->where('type', OffcutCleanoutDue::class)->count())->toBe(0);
});

it('renders the cleanout notice in the bell rather than leaving it unread and invisible', function () {
    /*
     * A notification type that is not in NotificationService::implementations() has its rows sit
     * there with no wording and no way to clear them - see that list's own note.
     */
    $user = offcutsIndexUser();
    $batch = batchWithDeliveredOrder($user);

    catalogue200PFC(5.7);
    agedOffcut(1200, 400, $batch->id);

    $this->artisan('offcuts:cleanout')->assertSuccessful();

    $rendered = (new App\Services\NotificationService)->getUnreadNotifications($user->fresh());

    expect($rendered)->toHaveCount(1)
        ->and($rendered[0]['message'])->toContain('offcut has sat unused')
        ->and($rendered[0]['trafficLights']['green'][0])->toBe('Review the rack');
});

//The page
it('carries the cleanout list onto the offcuts page', function () {
    $user = offcutsIndexUser();
    $batch = batchWithDeliveredOrder($user);

    catalogue200PFC(5.7);

    $deadStock = agedOffcut(1200, 400, $batch->id);
    agedOffcut(4000, 800, $batch->id);

    $this->actingAs($user)
        ->get(route('offcuts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('OffcutsIndex')
            ->has('cleanout', 1)
            ->where('cleanout.0.offcut.id', $deadStock->id)
            ->where('cleanoutShelfLifeDays', 365)
            //Both rows are still ordinary inventory until somebody says otherwise
            ->has('offcuts.data', 2)
        );
});

it('opens on the cleanout tab when the bell sent them there', function () {
    $user = offcutsIndexUser();

    $this->actingAs($user)
        ->get(route('offcuts.index', ['tab' => 'cleanout']))
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'cleanout'));
});

//Scrapping
it('scraps the offcuts somebody ticked, recording who and why', function () {
    $user = offcutsIndexUser();
    $batch = batchWithDeliveredOrder($user);

    catalogue200PFC(5.7);

    $first = agedOffcut(1200, 400, $batch->id);
    $second = agedOffcut(1400, 500, $batch->id);

    $this->actingAs($user)
        ->post(route('offcuts.scrap'), [
            'offcut_ids' => [$first->id, $second->id],
            'note' => 'Q1 rack clearout',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    foreach ([$first, $second] as $offcut) {
        $offcut->refresh();

        expect($offcut->isRemoved())->toBeTrue()
            ->and($offcut->removed_reason)->toBe(OffcutRemovalEnums::SCRAPPED->value)
            ->and($offcut->removed_by_user_id)->toBe($user->id)
            ->and($offcut->removed_note)->toBe('Q1 rack clearout');
    }

    //And the nest stops offering steel that is on its way to the merchant
    expect($user->business->availableOffcuts()->count())->toBe(0);
});

it('refuses to scrap an offcut that is not on the cleanout list', function () {
    /*
     * The endpoint re-derives the list rather than trusting the page. A cleanout page sits open for
     * a while, and "scrap these ids" arriving from a stale one must not reach steel that was never
     * proposed - here, a piece cut last week.
     */
    $user = offcutsIndexUser();
    $batch = batchWithDeliveredOrder($user);

    catalogue200PFC(5.7);

    $young = agedOffcut(1200, 10, $batch->id);

    $this->actingAs($user)
        ->post(route('offcuts.scrap'), ['offcut_ids' => [$young->id]])
        ->assertRedirect()
        ->assertSessionHas('warning');

    expect($young->fresh()->isRemoved())->toBeFalse();
});

it('would be a disaster if one business could scrap another business steel', function () {
    $mine = offcutsIndexUser();
    $theirs = secondYardUser();

    catalogue200PFC(5.7);

    $theirOffcut = agedOffcut(1200, 400, batchWithDeliveredOrder($theirs)->id);

    $this->actingAs($mine)
        ->post(route('offcuts.scrap'), ['offcut_ids' => [$theirOffcut->id]])
        ->assertRedirect()
        ->assertSessionHas('warning');

    expect($theirOffcut->fresh()->isRemoved())->toBeFalse();
});

it('puts a scrapped offcut back, for the rack that was cleared too enthusiastically', function () {
    $user = offcutsIndexUser();
    $batch = batchWithDeliveredOrder($user);

    catalogue200PFC(5.7);

    $offcut = agedOffcut(1200, 400, $batch->id);

    $this->actingAs($user)->post(route('offcuts.scrap'), ['offcut_ids' => [$offcut->id]]);

    expect($offcut->fresh()->isRemoved())->toBeTrue();

    $this->actingAs($user)->post(route('offcuts.restore', $offcut->id))->assertRedirect();

    expect($offcut->fresh()->isRemoved())->toBeFalse()
        ->and($user->business->availableOffcuts()->pluck('id')->all())->toBe([$offcut->id]);
});
