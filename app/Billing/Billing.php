<?php

namespace App\Billing;

use App\Billing\Contracts\BillingProvider;
use App\Enums\BillingStatusEnums;
use App\Models\Business;

/**
 * The one place that decides what a business is entitled to.
 *
 * Three things can entitle an account, and they are checked in this order:
 *
 *   1. A manual grant on the business - an invoice paid by bank transfer, or an account
 *      grandfathered in when billing was introduced. Outranks everything, including the live
 *      provider, so that turning Stripe on cannot lock out a customer who has already paid.
 *   2. Whatever the live provider has on record.
 *   3. The free trial every business gets on creation, which needs no provider to exist.
 *
 * Read through Business::billingState(), which memoises the answer for the request.
 */
class Billing
{
    public function __construct(
        private readonly BillingProvider $provider,
        private readonly Plans $plans,
    ) {}

    public function provider(): BillingProvider
    {
        return $this->provider;
    }

    public function plans(): Plans
    {
        return $this->plans;
    }

    /**
     * The plans this installation can actually take money for, in config order.
     *
     * @return array<int, Plan>
     */
    public function sellablePlans(): array
    {
        return array_values(array_filter(
            $this->plans->published(),
            fn (Plan $plan): bool => $this->provider->canSell($plan),
        ));
    }

    public function state(Business $business): SubscriptionState
    {
        if ($grant = $this->manualGrant($business)) {
            return $grant;
        }

        $fromProvider = $this->provider->subscriptionFor($business);

        /*
         * Anything the provider knows about wins over the trial, whether or not it still entitles
         * them. Someone who subscribed and then let it lapse must be told their subscription ended,
         * not sent back to a trial that finished months ago.
         */
        if ($fromProvider !== null) {
            return $fromProvider;
        }

        return $this->trial($business);
    }

    /**
     * An invoice paid by hand, or an account grandfathered in.
     *
     * A null manual_access_until means indefinite rather than expired - that is what the businesses
     * already using the product were given when billing was added, since none of them signed up to
     * a subscription and none of them should have been locked out by a deployment.
     */
    private function manualGrant(Business $business): ?SubscriptionState
    {
        if ($business->manual_plan === null) {
            return null;
        }

        if ($business->manual_access_until !== null && $business->manual_access_until->isPast()) {
            return null;
        }

        return new SubscriptionState(
            status: BillingStatusEnums::ACTIVE,
            source: SubscriptionState::SOURCE_MANUAL,
            plan: $this->plans->find($business->manual_plan),
            endsAt: $business->manual_access_until,
            //Nothing self-serve to manage: these are settled over email
            manageable: false,
        );
    }

    private function trial(Business $business): SubscriptionState
    {
        if ($business->trial_ends_at === null) {
            return SubscriptionState::none();
        }

        return new SubscriptionState(
            status: $business->trial_ends_at->isFuture()
                ? BillingStatusEnums::TRIALING
                : BillingStatusEnums::TRIAL_EXPIRED,
            source: SubscriptionState::SOURCE_TRIAL,
            plan: null,
            endsAt: $business->trial_ends_at,
            manageable: false,
        );
    }
}
