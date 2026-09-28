<?php

namespace App\Billing\Drivers;

use App\Billing\Contracts\BillingProvider;
use App\Billing\Plan;
use App\Billing\SubscriptionState;
use App\Models\Business;

/**
 * No provider at all: invoices raised by hand, paid by bank transfer.
 *
 * This is how the product was sold before any of this existed - the pricing page's "Request invoice
 * by email" - and it stays the driver in testing, so no test can reach Stripe.
 *
 * It reports no subscription of its own. A business that has paid an invoice is recorded as a
 * manual grant on the businesses table (manual_plan, manual_access_until), and App\Billing\Billing
 * honours that grant ahead of any driver, precisely so that an invoice customer keeps working after
 * Stripe is switched on.
 */
class ManualBillingProvider implements BillingProvider
{
    public function name(): string
    {
        return 'manual';
    }

    /**
     * Anything can be sold by invoice - there is no price object that has to exist first.
     */
    public function canSell(Plan $plan): bool
    {
        return true;
    }

    public function hostedCheckout(): bool
    {
        return false;
    }

    public function subscriptionFor(Business $business): ?SubscriptionState
    {
        return null;
    }

    /**
     * There is no checkout to send anyone to, so this is the page that explains how to ask for an
     * invoice. $successUrl and $cancelUrl are ignored: nothing comes back from a bank transfer.
     */
    public function checkoutUrl(Business $business, Plan $plan, string $successUrl, string $cancelUrl): string
    {
        return route('billing.invoice', ['plan' => $plan->key]);
    }

    public function manageUrl(Business $business, string $returnUrl): ?string
    {
        return null;
    }
}
