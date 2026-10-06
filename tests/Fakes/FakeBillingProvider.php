<?php

namespace Tests\Fakes;

use App\Billing\BillingCheck;
use App\Billing\Contracts\BillingProvider;
use App\Billing\Plan;
use App\Billing\SubscriptionState;
use App\Models\Business;
use RuntimeException;

/**
 * A payment provider that never touches the network.
 *
 * The existence of this class is the point of the whole abstraction: everything the application does
 * about subscriptions can be tested against a provider that Stripe had no hand in. If a test needed
 * Stripe's test mode to prove that a lapsed account goes read-only, the seam would be in the wrong
 * place.
 */
class FakeBillingProvider implements BillingProvider
{
    public string $checkoutUrl = 'https://checkout.test/session';

    public ?string $manageUrl = 'https://portal.test/session';

    public bool $sells = true;

    public bool $hosted = true;

    //Thrown from checkoutUrl() to stand in for an outage, a bad key or a retired price
    public ?RuntimeException $failWith = null;

    /**
     * What this provider claims to have on record. Null is "no subscription here", which is the
     * state every business starts in.
     */
    public ?SubscriptionState $subscription = null;

    /** @var array<int, string> plan keys a checkout was started for, in order */
    public array $checkoutsStarted = [];

    /** @var array<int, BillingCheck> what preflight() reports, for billing:check */
    public array $checks = [];

    public function name(): string
    {
        return 'fake';
    }

    public function canSell(Plan $plan): bool
    {
        return $this->sells;
    }

    public function hostedCheckout(): bool
    {
        return $this->hosted;
    }

    public function subscriptionFor(Business $business): ?SubscriptionState
    {
        return $this->subscription;
    }

    public function checkoutUrl(Business $business, Plan $plan, string $successUrl, string $cancelUrl): string
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->checkoutsStarted[] = $plan->key;

        return $this->checkoutUrl;
    }

    public function manageUrl(Business $business, string $returnUrl): ?string
    {
        return $this->manageUrl;
    }

    /**
     * @return array<int, BillingCheck>
     */
    public function preflight(): array
    {
        return $this->checks;
    }
}
