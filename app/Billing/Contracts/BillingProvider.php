<?php

namespace App\Billing\Contracts;

use App\Billing\BillingCheck;
use App\Billing\Plan;
use App\Billing\SubscriptionState;
use App\Models\Business;

/**
 * Everything the application is allowed to ask of whoever takes the money.
 *
 * Deliberately small. Six methods is what it takes to sell a subscription, know whether one is live,
 * and say whether this provider is set up to do either, and anything richer - proration, coupons,
 * usage metering, invoice PDFs - would be a Stripe idea leaking into an interface that Paddle also
 * has to satisfy.
 *
 * Implementations are the only place in the codebase allowed to import a provider's SDK.
 */
interface BillingProvider
{
    /**
     * The driver's key in config('billing.drivers'), which is also the key a plan's price id is
     * stored under in config('billing.plans.*.prices').
     */
    public function name(): string;

    /**
     * Whether this provider is in a position to sell the plan at all - for a hosted checkout, that
     * it has a price identifier for it.
     */
    public function canSell(Plan $plan): bool;

    /**
     * Whether checkoutUrl() leads to a payment page at the provider, or to instructions inside this
     * application.
     *
     * Only the wording of a button turns on this, but it is the difference between promising someone
     * a card form and handing them an invoice request, so the page has to be able to ask.
     */
    public function hostedCheckout(): bool;

    /**
     * What this provider has on record for the business, or null if it has nothing.
     *
     * Runs on every authenticated response, so implementations must answer from local state and
     * must not call the provider's API.
     */
    public function subscriptionFor(Business $business): ?SubscriptionState;

    /**
     * Where to send someone to start paying for $plan.
     *
     * May call the provider's API - it happens on a click, not on a page render.
     */
    public function checkoutUrl(Business $business, Plan $plan, string $successUrl, string $cancelUrl): string;

    /**
     * Where the customer manages their own card, invoices and cancellation, or null where this
     * provider has no such portal. Cancelling lives there rather than in this app so there is one
     * account of what was cancelled and when.
     */
    public function manageUrl(Business $business, string $returnUrl): ?string;

    /**
     * Everything that has to be true before this provider can take money, checked against the
     * provider itself rather than against config.
     *
     * canSell() answers the same question from config alone, which is all a page render can afford
     * and is why a mistyped price id reads as "this plan is not for sale" and withdraws it from the
     * billing page in silence. This is the expensive version: it may call the provider's API, it is
     * allowed to be slow, and nothing but php artisan billing:check calls it.
     *
     * @return array<int, BillingCheck>
     */
    public function preflight(): array;
}
