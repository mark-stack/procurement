<?php

use App\Billing\Drivers\ManualBillingProvider;
use App\Billing\Drivers\StripeBillingProvider;

/*
 * Subscription billing, kept deliberately at arm's length from whoever takes the money.
 *
 * Laravel Cashier is not one package - laravel/cashier is Stripe and laravel/cashier-paddle is
 * Paddle, and they share no interface: different Billable traits with colliding method names, a
 * different subscriptions schema, a different checkout model, and a different tax posture (Paddle
 * is merchant of record and invoices GST for you; with Stripe that is yours to handle). They cannot
 * even be installed side by side, because both ship a subscriptions migration.
 *
 * So the app never talks to a provider. It asks Business::billingState() what the account is
 * entitled to, and that answer comes from a driver below implementing App\Billing\Contracts
 * \BillingProvider. Nothing outside app/Billing/Drivers imports Laravel\Cashier. Switching to
 * Paddle means writing one more driver and swapping the Billable trait on Business - not touching
 * a controller, a middleware or a page.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | "manual" is the invoice-by-email arrangement that predates any provider, and it is also the
    | default in testing so no test can reach the network. "stripe" needs STRIPE_KEY,
    | STRIPE_SECRET, STRIPE_WEBHOOK_SECRET and a price id per plan below.
    |
    */

    'driver' => env('BILLING_DRIVER', 'manual'),

    'drivers' => [
        'manual' => ManualBillingProvider::class,
        'stripe' => StripeBillingProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Free trial
    |--------------------------------------------------------------------------
    |
    | Every business gets this trial the moment it is created, without a card. That is what the
    | landing page promises, and it is the reason the trial is ours rather than the provider's:
    | a trial nobody has paid for should not depend on a payment provider existing yet.
    |
    | reminder_days: how many days before expiry the billing:trial-reminders command writes. Each
    | mark is sent once per business, recorded in notification_logs.
    |
    */

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 30),

    'reminder_days' => [7, 1],

    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    |
    | Plan amounts below are in minor units (cents) and EXCLUDE GST, matching how the prices are
    | quoted on the landing page. With Stripe you are the merchant of record, so the price objects
    | in the Stripe dashboard must be created tax-exclusive and collect_tax turned on for Stripe
    | Tax to add GST at checkout. Left off, customers are charged the bare ex-GST figure.
    |
    */

    'currency' => env('BILLING_CURRENCY', 'aud'),

    'collect_tax' => (bool) env('BILLING_COLLECT_TAX', false),

    /*
    |--------------------------------------------------------------------------
    | Invoicing by hand
    |--------------------------------------------------------------------------
    |
    | Where a customer on the manual driver asks for an invoice. Some fabricators will not put a
    | card into a web form at all, so this stays reachable whichever driver is live.
    |
    */

    'invoice_email' => env('BILLING_INVOICE_EMAIL', 'mark@steelnesting.com.au'),

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | One entry per thing a business can buy. "prices" maps a driver name to that provider's own
    | identifier for the same plan, which is the whole trick: the app quotes plans by key
    | ("weekly"), and only the driver ever sees a price id. A plan with no id for the live driver
    | is not offered for sale, so a half-configured provider cannot take money for nothing.
    |
    | "public" is whether it appears on the billing page.
    |
    | "checkout" is how this plan is paid for, and it is per plan rather than per driver because
    | the two we sell are bought in different ways: the weekly one by card, and the annual one on
    | an invoice with a purchase order against it, which is how a fabricator's office pays for a
    | year of anything. An invoice plan needs no price id in any provider and is offered for sale
    | whichever driver is live - see App\Billing\Plan::CHECKOUT_INVOICE.
    |
    |   provider: a checkout at the live provider, if it has a price id for the plan
    |   invoice:  App\Http\Controllers\BillingInvoiceController, always
    |
    | Two plans, and the annual one is priced at a 16% discount to fifty-two weeks of the weekly
    | one ($2,028). The billing page derives that percentage rather than quoting it, so the two
    | figures below cannot drift apart from the badge that sells them.
    |
    */

    'plans' => [

        'weekly' => [
            'name' => 'Unlimited, weekly',
            'interval' => 'week',
            'amount' => 3900,
            'public' => true,
            'checkout' => 'provider',
            'prices' => [
                'stripe' => env('STRIPE_PRICE_WEEKLY'),
            ],
        ],

        'annual' => [
            'name' => 'Unlimited, annual',
            'interval' => 'year',
            'amount' => 170000,
            'public' => true,
            'checkout' => 'invoice',
            'prices' => [
                //Intentionally none: this plan is invoiced, so no provider ever prices it
            ],
        ],

    ],

];
