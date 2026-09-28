<?php

namespace App\Billing\Drivers;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Plan;
use App\Billing\Plans;
use App\Billing\SubscriptionState;
use App\Enums\BillingStatusEnums;
use App\Models\Business;
use Laravel\Cashier\Subscription;
use RuntimeException;

/**
 * Stripe, through Laravel Cashier.
 *
 * The only file in the application that imports Laravel\Cashier. Everything Stripe knows how to say
 * is translated here into a BillingStatusEnums case, so that swapping in cashier-paddle is a matter
 * of writing a sibling of this class rather than finding every place the word "stripe" appears.
 */
class StripeBillingProvider implements BillingProvider
{
    /**
     * Cashier's name for a subscription when a customer only ever has one of them.
     */
    public const string SUBSCRIPTION_TYPE = 'default';

    public function __construct(private readonly Plans $plans) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function canSell(Plan $plan): bool
    {
        return $plan->priceFor($this->name()) !== null;
    }

    public function hostedCheckout(): bool
    {
        return true;
    }

    public function subscriptionFor(Business $business): ?SubscriptionState
    {
        //Never been to a checkout, so Cashier has nothing and there is no point querying for it
        if ($business->stripe_id === null) {
            return null;
        }

        $subscription = $business->subscription(self::SUBSCRIPTION_TYPE);

        if ($subscription === null) {
            return null;
        }

        return new SubscriptionState(
            status: $this->statusOf($subscription),
            source: SubscriptionState::SOURCE_PROVIDER,
            plan: $this->planOf($subscription),
            /*
             * Only set once cancellation has fixed a last day. A live subscription's next invoice
             * date is not stored locally by Cashier - reading it costs a Stripe API call, and this
             * method runs on every authenticated response - so the renewal schedule is left to
             * Stripe's own portal, which is a click away and always right.
             */
            endsAt: $subscription->ends_at,
            manageable: true,
        );
    }

    public function checkoutUrl(Business $business, Plan $plan, string $successUrl, string $cancelUrl): string
    {
        $price = $plan->priceFor($this->name());

        if ($price === null) {
            throw new RuntimeException("Billing plan [{$plan->key}] has no Stripe price configured.");
        }

        $checkout = $business
            ->newSubscription(self::SUBSCRIPTION_TYPE, $price)
            /*
             * The trial is ours and it has already run by the time anyone reaches a checkout, so
             * Stripe must not add one of its own on top and bill nothing for a month.
             */
            ->skipTrial()
            ->checkout(array_filter([
                /*
                 * Both passed explicitly because Cashier's defaults call route('home'), and this
                 * application has no route by that name - the landing page is unnamed.
                 */
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                /*
                 * Prices are quoted ex GST, so Stripe Tax has to be the thing that adds it. Off by
                 * default: with it off customers are charged the bare ex-GST figure, which is wrong
                 * but visible, rather than silently double-taxed.
                 */
                'automatic_tax' => config('billing.collect_tax') ? ['enabled' => true] : null,
                'customer_update' => config('billing.collect_tax') ? ['address' => 'auto'] : null,
            ]));

        return $checkout->url;
    }

    public function manageUrl(Business $business, string $returnUrl): ?string
    {
        //billingPortalUrl() creates the Stripe customer if there is not one yet, so this is safe
        //to offer before a first subscription - but there is nothing there to manage, so don't
        if ($business->stripe_id === null) {
            return null;
        }

        return $business->billingPortalUrl($returnUrl);
    }

    /**
     * Stripe's five ways of saying "not paying", plus its two ways of saying "paying", flattened.
     *
     * Order matters. A past-due subscription also reports active() under some Cashier settings, and
     * a cancelled-but-not-yet-ended one reports both canceled() and active(), so the more specific
     * questions are asked first.
     */
    private function statusOf(Subscription $subscription): BillingStatusEnums
    {
        if ($subscription->pastDue() || $subscription->incomplete()) {
            return BillingStatusEnums::PAST_DUE;
        }

        if ($subscription->onGracePeriod()) {
            return BillingStatusEnums::CANCELLING;
        }

        if ($subscription->onTrial()) {
            return BillingStatusEnums::TRIALING;
        }

        if ($subscription->active()) {
            return BillingStatusEnums::ACTIVE;
        }

        return BillingStatusEnums::LAPSED;
    }

    /**
     * Which of our plans the Stripe price on the subscription corresponds to.
     *
     * Null where it corresponds to none of them, which happens legitimately: a price retired from
     * config, or a legacy deal set up directly in the Stripe dashboard. The status still holds, so
     * the account keeps working and the billing page simply cannot name the plan.
     */
    private function planOf(Subscription $subscription): ?Plan
    {
        $price = $subscription->stripe_price;

        if ($price === null) {
            return null;
        }

        foreach ($this->plans->all() as $plan) {
            if ($plan->priceFor($this->name()) === $price) {
                return $plan;
            }
        }

        return null;
    }
}
