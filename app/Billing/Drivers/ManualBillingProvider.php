<?php

namespace App\Billing\Drivers;

use App\Billing\BillingCheck;
use App\Billing\Contracts\BillingProvider;
use App\Billing\Plan;
use App\Billing\Plans;
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
    public function __construct(private readonly Plans $plans) {}

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

    /**
     * There is no provider to check, so what is left is the address the invoices are asked for at -
     * and the one thing about this driver that surprises people.
     *
     * A plan marked as paid by card is still sold here, quietly, as an invoice. That is correct: a
     * driver that refused to sell the weekly plan would leave an account with nowhere to go. But it
     * means the billing page can promise a card form that nobody is going to see, so it is said out
     * loud rather than left for a customer to discover.
     *
     * @return array<int, BillingCheck>
     */
    public function preflight(): array
    {
        $checks = [BillingCheck::ok('driver', 'manual - invoices raised by hand and paid by bank transfer. No card is ever charged.')];

        $email = (string) config('billing.invoice_email');

        $checks[] = filter_var($email, FILTER_VALIDATE_EMAIL)
            ? BillingCheck::ok('invoice email', $email)
            : BillingCheck::fail('invoice email', 'BILLING_INVOICE_EMAIL is '.($email === '' ? 'empty' : "[{$email}], which is not an address").', so a request for an invoice has nowhere to go.');

        foreach ($this->plans->published() as $plan) {
            $checks[] = $plan->invoiceOnly()
                ? BillingCheck::ok("plan: {$plan->key}", "{$plan->amountFormatted()} per {$plan->interval}, invoiced - which is how this plan is sold whichever driver is live.")
                : BillingCheck::warn("plan: {$plan->key}", "{$plan->amountFormatted()} per {$plan->interval}, configured to be paid by card - but on this driver Subscribe leads to the invoice page, not a card form.");
        }

        return $checks;
    }
}
