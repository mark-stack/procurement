<?php

namespace App\Enums;

/**
 * What an account is entitled to, said in the application's own words rather than any provider's.
 *
 * Stripe calls a lapsed subscription five different things (canceled, unpaid, incomplete,
 * incomplete_expired, paused) and Paddle calls them something else again. Everything in the app
 * that cares - the read-only gate, the banner, the billing page - reads these cases, so a change
 * of provider cannot change what the app considers a paying customer.
 */
enum BillingStatusEnums: string
{
    //On the free trial that every new business gets, no card involved
    case TRIALING = 'TRIALING';

    //Paying, nothing outstanding
    case ACTIVE = 'ACTIVE';

    /*
     * A renewal payment failed and the provider is still retrying. Deliberately allowed to keep
     * working: a card that expired on a Friday should not stop a fabricator ordering steel, and
     * the provider will take the subscription off us soon enough if it never clears.
     */
    case PAST_DUE = 'PAST_DUE';

    //Cancelled, but paid up to a date that has not arrived yet
    case CANCELLING = 'CANCELLING';

    //The free trial ran out and nothing was ever bought
    case TRIAL_EXPIRED = 'TRIAL_EXPIRED';

    //Was paying, no longer is, and any grace period is behind them
    case LAPSED = 'LAPSED';

    //No trial, no subscription, nothing on record at all
    case NONE = 'NONE';

    /**
     * Whether the account may still change anything.
     *
     * A "no" here is read-only, not locked out: BillingWriteAccessMiddleware refuses writes while
     * every page, download and cutting list stays reachable. Someone who let a trial lapse
     * mid-fabrication keeps their nesting plans.
     */
    public function allowsWrites(): bool
    {
        return match ($this) {
            self::TRIALING, self::ACTIVE, self::PAST_DUE, self::CANCELLING => true,
            self::TRIAL_EXPIRED, self::LAPSED, self::NONE => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TRIALING => 'Free trial',
            self::ACTIVE => 'Active',
            self::PAST_DUE => 'Payment failed',
            self::CANCELLING => 'Cancelling',
            self::TRIAL_EXPIRED => 'Trial ended',
            self::LAPSED => 'Subscription ended',
            self::NONE => 'No subscription',
        };
    }
}
