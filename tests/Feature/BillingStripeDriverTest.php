<?php

use App\Billing\Contracts\BillingProvider;
use App\Billing\Drivers\StripeBillingProvider;
use App\Billing\Plans;
use App\Enums\BillingStatusEnums;
use App\Models\Business;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Subscription;

/**
 * The translation layer between Stripe's vocabulary and the application's.
 *
 * Stripe has five ways of saying "not paying" and two of saying "paying", and getting the order of
 * those questions wrong is the difference between a paying customer being locked out and a lapsed
 * one keeping the product for free. Worth pinning down case by case.
 *
 * Nothing here reaches the network, and that is itself part of the test: there are no Stripe keys in
 * the test environment, so any call over the wire would fail rather than pass quietly. This is the
 * property the read path depends on - subscriptionFor() runs on every authenticated response.
 */
function stripeDriver(): StripeBillingProvider
{
    return new StripeBillingProvider(app(Plans::class));
}

/**
 * Make Stripe the live driver for this test, rather than the manual one the test environment runs
 * on, so that assertions about Business::allowsWrites() go through the same path production would.
 *
 * Both singletons have to be forgotten: App\Billing\Billing holds the provider it was built with.
 */
function useStripeDriver(): void
{
    config(['billing.driver' => 'stripe']);

    /*
     * Production cannot select this driver without one - BillingServiceProvider refuses, because
     * Cashier leaves POST /stripe/webhook unsigned when the secret is empty - so a test that means
     * "the way production would" has to carry one too.
     */
    config(['cashier.webhook.secret' => 'whsec_test']);

    app()->forgetInstance(BillingProvider::class);
    app()->forgetInstance(App\Billing\Billing::class);
}

/**
 * A Cashier subscription row for the business, written straight to the table the way Cashier's
 * webhook handler would.
 */
function cashierSubscription(Business $business, array $attributes = []): Subscription
{
    $business->stripe_id = 'cus_'.$business->id;
    $business->save();

    return Subscription::create(array_merge([
        'business_id' => $business->id,
        'type' => StripeBillingProvider::SUBSCRIPTION_TYPE,
        'stripe_id' => 'sub_'.uniqid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_weekly_test',
        'quantity' => 1,
    ], $attributes));
}

it('would be a disaster if Stripe was asked about a business that has never been to a checkout', function () {
    /*
     * No customer id means Cashier has nothing, and asking anyway would be a query on every page
     * load of every trialling account for a row that cannot exist.
     */
    $business = createBusiness('Never paid', true);

    expect(stripeDriver()->subscriptionFor($business))->toBeNull();
});

it('would be a disaster if a Stripe status were read as the wrong entitlement', function (
    string $stripeStatus,
    ?string $endsAt,
    ?string $trialEndsAt,
    BillingStatusEnums $expected,
) {
    $business = createBusiness('Subscriber '.uniqid(), true);

    cashierSubscription($business, [
        'stripe_status' => $stripeStatus,
        'ends_at' => $endsAt === null ? null : now()->modify($endsAt),
        'trial_ends_at' => $trialEndsAt === null ? null : now()->modify($trialEndsAt),
    ]);

    expect(stripeDriver()->subscriptionFor($business->fresh())->status)->toBe($expected);
})->with([
    'paying' => ['active', null, null, BillingStatusEnums::ACTIVE],

    //Retried by Stripe for about a fortnight. Deliberately still allowed to work.
    'card declined on renewal' => ['past_due', null, null, BillingStatusEnums::PAST_DUE],
    'first payment never completed' => ['incomplete', null, null, BillingStatusEnums::PAST_DUE],

    //Cancelled, but paid up to a date still ahead of them
    'cancelled, still in the paid period' => ['active', '+9 days', null, BillingStatusEnums::CANCELLING],

    //Cancelled and the paid period has passed. Stripe leaves stripe_status alone here, so reading
    //the status column alone would have this one still paying.
    'cancelled and expired' => ['active', '-1 day', null, BillingStatusEnums::LAPSED],

    'cancelled at Stripe' => ['canceled', '-1 day', null, BillingStatusEnums::LAPSED],
    'abandoned unpaid' => ['unpaid', null, null, BillingStatusEnums::LAPSED],
    'incomplete and expired' => ['incomplete_expired', null, null, BillingStatusEnums::LAPSED],

    //Only reachable if a trial is ever configured at Stripe rather than kept ours
    'trialling at Stripe' => ['trialing', null, '+5 days', BillingStatusEnums::TRIALING],
]);

it('would be a disaster if a lapsed Stripe subscription still allowed writes', function () {
    /*
     * The whole stack on the real Stripe driver, not just the status mapping: a cancelled and expired
     * subscription has to reach the read-only gate as read-only, and it has to do so without a single
     * call to Stripe, which is what makes it safe to ask on every page load.
     */
    useStripeDriver();

    $business = createBusiness('Lapsed subscriber', true);

    cashierSubscription($business, ['stripe_status' => 'canceled', 'ends_at' => now()->subDay()]);

    $fresh = $business->fresh();

    expect($fresh->allowsWrites())->toBeFalse();
    //Told they were a customer, not that a trial ran out - they were paying until last week
    expect($fresh->billingState()->everPaid())->toBeTrue();
});

it('would be a disaster if the plan a subscriber is on could not be named', function () {
    config(['billing.plans.weekly.prices.stripe' => 'price_weekly_test']);
    //Rebuilt because Plans reads config once and is a singleton
    app()->forgetInstance(Plans::class);

    $business = createBusiness('Named plan', true);
    cashierSubscription($business, ['stripe_price' => 'price_weekly_test']);

    $state = stripeDriver()->subscriptionFor($business->fresh());

    expect($state->plan)->not->toBeNull();
    expect($state->plan->key)->toBe('weekly');
});

it('would be a disaster if a legacy price set up in the Stripe dashboard locked a customer out', function () {
    /*
     * A price retired from config, or a one-off deal arranged directly in Stripe, corresponds to none
     * of our plans. That is a plan the billing page cannot name - not a reason to stop a paying
     * customer working.
     */
    $business = createBusiness('Legacy deal', true);
    cashierSubscription($business, ['stripe_price' => 'price_some_handshake_deal']);

    $state = stripeDriver()->subscriptionFor($business->fresh());

    expect($state->plan)->toBeNull();
    expect($state->status)->toBe(BillingStatusEnums::ACTIVE);
    expect($state->allowsWrites())->toBeTrue();
});

it('would be a disaster if a subscription were attached to a business Cashier could not find', function () {
    /*
     * Cashier builds its subscriptions() relation from the billable model's own foreign key, so with
     * Business as the customer model the column has to be business_id. Published unchanged from the
     * package it would be user_id, the relation would match nothing, and every paying customer would
     * read as lapsed - silently, with the rows sitting right there in the table.
     */
    $business = createBusiness('Related', true);
    cashierSubscription($business);

    expect($business->fresh()->subscription('default'))->not->toBeNull();
    expect(Schema::hasColumn('subscriptions', 'business_id'))->toBeTrue();
});
