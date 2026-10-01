<?php

use App\Billing\SubscriptionState;
use App\Enums\BillingStatusEnums;
use App\Models\Business;
use App\Notifications\TrialEndingEmail;
use Illuminate\Support\Facades\Notification;

/**
 * The free trial, and what it becomes.
 *
 * None of this touches Stripe. The trial is the application's own - no card, no provider - and the
 * few tests below that need a subscription use Tests\Fakes\FakeBillingProvider, which is the whole
 * reason App\Billing\Contracts\BillingProvider exists.
 */
it('would be a disaster if a new business got no free trial', function () {
    /*
     * A business with no trial is read-only from its first minute: a signup that lands on the
     * dashboard, clicks upload and is told the account is read-only. The trial is set by a creating
     * hook on the model rather than in the registration controller precisely because businesses are
     * created from several places and every one of them has to get it.
     */
    $business = createBusiness('New Fabricator');

    expect($business->trial_ends_at)->not->toBeNull();
    expect($business->trial_ends_at->isFuture())->toBeTrue();
    expect($business->billingState()->status)->toBe(BillingStatusEnums::TRIALING);
    expect($business->allowsWrites())->toBeTrue();
});

it('would be a disaster if a business registering got a different trial than the one advertised', function () {
    /*
     * The landing page used to hardcode "1 Month FREE TRIAL" beside a signup button that granted
     * nothing at all. It now reads the same config the model grants from, so the promise and the
     * grant cannot drift apart.
     */
    $this->get('/')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page->where('trialDays', (int) config('billing.trial_days')));

    $business = createBusiness('Advertised');

    expect($business->trial_ends_at->startOfDay()->toDateString())
        ->toBe(now()->addDays((int) config('billing.trial_days'))->startOfDay()->toDateString());
});

it('would be a disaster if an expired trial did not make the account read-only', function () {
    $business = lapsedTrialBusiness();

    $state = $business->fresh()->billingState();

    expect($state->status)->toBe(BillingStatusEnums::TRIAL_EXPIRED);
    expect($state->allowsWrites())->toBeFalse();
    //The copy in the banner and the email turns on this: they were never a customer
    expect($state->everPaid())->toBeFalse();
});

it('would be a disaster if a business already using the product was locked out by billing arriving', function () {
    /*
     * Every business that existed when billing was introduced had been using the product for free
     * by arrangement and had never agreed to a subscription. The migration grants them indefinite
     * access - manual_plan set, manual_access_until null - and this pins down that a null date means
     * indefinite rather than expired, which is the reading that would have locked all of them out.
     */
    $business = createBusiness('Grandfathered');

    $business->trial_ends_at = now()->subYear();
    $business->manual_plan = 'grandfathered';
    $business->manual_access_until = null;
    $business->save();

    $state = $business->fresh()->billingState();

    expect($state->status)->toBe(BillingStatusEnums::ACTIVE);
    expect($state->source)->toBe(SubscriptionState::SOURCE_MANUAL);
    expect($state->allowsWrites())->toBeTrue();
});

it('would be a disaster if a manual grant that had run out still allowed writes', function () {
    $business = createBusiness('Invoiced');

    $business->trial_ends_at = now()->subYear();
    $business->manual_plan = 'annual';
    $business->manual_access_until = now()->subDay();
    $business->save();

    expect($business->fresh()->allowsWrites())->toBeFalse();
});

it('would be a disaster if a manual grant were overruled by the payment provider', function () {
    /*
     * An invoice customer must keep working after a provider is switched on. The provider knows
     * nothing about them, and if it were asked first they would read as lapsed.
     */
    $fake = fakeBillingProvider();
    $fake->subscription = new SubscriptionState(BillingStatusEnums::LAPSED, SubscriptionState::SOURCE_PROVIDER);

    $business = createBusiness('Invoiced');
    $business->trial_ends_at = now()->subYear();
    $business->manual_plan = 'annual';
    $business->manual_access_until = now()->addYear();
    $business->save();

    expect($business->fresh()->billingState()->allowsWrites())->toBeTrue();
});

it('would be a disaster if a paying subscriber were treated as an expired trial', function () {
    /*
     * Everyone who subscribes has an expired trial behind them, so the order these are checked in is
     * the difference between a paying customer working and a paying customer locked out.
     */
    $fake = fakeBillingProvider();
    $fake->subscription = new SubscriptionState(BillingStatusEnums::ACTIVE, SubscriptionState::SOURCE_PROVIDER);

    $business = lapsedTrialBusiness();

    $state = $business->fresh()->billingState();

    expect($state->status)->toBe(BillingStatusEnums::ACTIVE);
    expect($state->allowsWrites())->toBeTrue();
});

it('would be a disaster if a lapsed subscriber were told their trial had ended', function () {
    /*
     * Someone who paid for a year and then stopped should not be shown a message about a free trial
     * that finished twelve months ago. Anything the provider knows about outranks the trial, whether
     * or not it still entitles them.
     */
    $fake = fakeBillingProvider();
    $fake->subscription = new SubscriptionState(BillingStatusEnums::LAPSED, SubscriptionState::SOURCE_PROVIDER);

    $business = lapsedTrialBusiness();

    $state = $business->fresh()->billingState();

    expect($state->status)->toBe(BillingStatusEnums::LAPSED);
    expect($state->everPaid())->toBeTrue();
    expect($state->allowsWrites())->toBeFalse();
});

it('would be a disaster if a failed payment locked a customer out on the spot', function () {
    /*
     * A card that expired on a Friday must not stop a fabricator ordering steel. The provider will
     * retry for a fortnight and take the subscription off us if it never clears, at which point the
     * status changes on its own.
     */
    $fake = fakeBillingProvider();
    $fake->subscription = new SubscriptionState(BillingStatusEnums::PAST_DUE, SubscriptionState::SOURCE_PROVIDER);

    expect(createBusiness('Past due')->billingState()->allowsWrites())->toBeTrue();
});

it('would be a disaster if a cancelled subscriber lost access before the date they had paid to', function () {
    $fake = fakeBillingProvider();
    $fake->subscription = new SubscriptionState(
        status: BillingStatusEnums::CANCELLING,
        source: SubscriptionState::SOURCE_PROVIDER,
        endsAt: now()->addDays(9),
    );

    $state = createBusiness('Cancelling')->billingState();

    expect($state->allowsWrites())->toBeTrue();
    expect($state->daysRemaining())->toBe(9);
});

it('would be a disaster if a trial ran out with no warning', function () {
    /*
     * A trial that expires silently is not a trial, it is a trap - the first the customer knows of
     * it is a refused save halfway through a job.
     */
    Notification::fake();

    $business = createBusiness('Warned');
    $business->trial_ends_at = now()->addDays(5);
    $business->save();

    $owner = createUser(1, $business, false, true);

    $this->artisan('billing:trial-reminders')->assertSuccessful();

    Notification::assertSentTo($owner, TrialEndingEmail::class);
});

it('would be a disaster if the same trial warning were sent every time the scheduler ran', function () {
    /*
     * The command is scheduled hourly, so without the notification_logs check a customer with a week
     * left would get 168 emails about it.
     */
    Notification::fake();

    $business = createBusiness('Warned once');
    $business->trial_ends_at = now()->addDays(5);
    $business->save();

    $owner = createUser(1, $business, false, true);

    $this->artisan('billing:trial-reminders');
    $this->artisan('billing:trial-reminders');
    $this->artisan('billing:trial-reminders');

    Notification::assertSentToTimes($owner, TrialEndingEmail::class, 1);
});

it('would be a disaster if trial warnings went to every user in the business', function () {
    /*
     * The trial belongs to the company, and a ten-person shop does not need ten copies of an email
     * about a subscription one person arranges. It goes to whoever signed the company up.
     */
    Notification::fake();

    $business = createBusiness('Crowded');
    $business->trial_ends_at = now()->addDays(2);
    $business->save();

    $owner = createUser(1, $business, false, true);
    $colleague = createUser(2, $business, false, true);
    $otherColleague = createUser(3, $business, false, true);

    $this->artisan('billing:trial-reminders');

    Notification::assertSentTo($owner, TrialEndingEmail::class);
    Notification::assertNotSentTo($colleague, TrialEndingEmail::class);
    Notification::assertNotSentTo($otherColleague, TrialEndingEmail::class);
});

it('would be a disaster if a trial warning went to someone who had already subscribed', function () {
    Notification::fake();

    $fake = fakeBillingProvider();
    $fake->subscription = new SubscriptionState(BillingStatusEnums::ACTIVE, SubscriptionState::SOURCE_PROVIDER);

    $business = createBusiness('Already paying');
    $business->trial_ends_at = now()->addDays(3);
    $business->save();

    $owner = createUser(1, $business, false, true);

    $this->artisan('billing:trial-reminders');

    Notification::assertNothingSent();
});

it('would be a disaster if nobody were told their trial had actually ended', function () {
    /*
     * The moment the account goes read-only is the one moment the email matters most, and it is a
     * different message from the countdown - nothing has been deleted, and here is how to get moving
     * again.
     */
    Notification::fake();

    $business = lapsedTrialBusiness();
    $owner = createUser(1, $business, false, true);

    $this->artisan('billing:trial-reminders');

    Notification::assertSentTo(
        $owner,
        TrialEndingEmail::class,
        fn (TrialEndingEmail $notification): bool => $notification->daysRemaining === null,
    );
});

it('would be a disaster if the trial-ended email went to someone who subscribed in time', function () {
    Notification::fake();

    $fake = fakeBillingProvider();
    $fake->subscription = new SubscriptionState(BillingStatusEnums::ACTIVE, SubscriptionState::SOURCE_PROVIDER);

    $business = lapsedTrialBusiness();
    createUser(1, $business, false, true);

    $this->artisan('billing:trial-reminders');

    Notification::assertNothingSent();
});

it('would be a disaster if a seeded or fixtured business had its dates overwritten', function () {
    /*
     * The creating hook fills a trial in only where one was not asked for, so a seeder or a fixture
     * can still dictate its own dates - which is how the read-only cases above are set up at all.
     */
    $business = Business::create([
        'name' => 'Fixture',
        'domain' => 'fixture.com',
        'trial_ends_at' => now()->addDays(3),
    ]);

    expect($business->trial_ends_at->startOfDay()->toDateString())
        ->toBe(now()->addDays(3)->startOfDay()->toDateString());
});
