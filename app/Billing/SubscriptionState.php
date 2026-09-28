<?php

namespace App\Billing;

use App\Enums\BillingStatusEnums;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What one business is entitled to right now, settled into a shape no provider had a say in.
 *
 * Built by App\Billing\Billing from whichever of the three sources applies - a manual grant, the
 * live provider, or the free trial - and then read by the middleware, the banner and the billing
 * page. Nothing downstream of here can tell which source it came from except by looking at
 * $source, and nothing downstream needs to.
 */
final class SubscriptionState
{
    //Where the entitlement came from. Displayed nowhere; useful in tests and when reading logs.
    public const string SOURCE_TRIAL = 'trial';

    public const string SOURCE_MANUAL = 'manual';

    public const string SOURCE_PROVIDER = 'provider';

    public const string SOURCE_NONE = 'none';

    /**
     * @param  Plan|null  $plan  what they are on, where that is known - a manual grant may name a
     *                           plan that no longer exists in config, and a trial names none
     * @param  CarbonInterface|null  $endsAt  when this entitlement stops. The trial end date, the
     *                                        last day of a cancelled subscription, or null for
     *                                        something open-ended (an active subscription renews,
     *                                        so it has no end date, and a grandfathered account has
     *                                        none by design). Typed to the interface because a date
     *                                        read off a provider's own model is not necessarily
     *                                        Laravel's Carbon subclass.
     * @param  bool  $manageable  whether the provider offers a self-serve portal for this account
     */
    public function __construct(
        public readonly BillingStatusEnums $status,
        public readonly string $source,
        public readonly ?Plan $plan = null,
        public readonly ?CarbonInterface $endsAt = null,
        public readonly bool $manageable = false,
    ) {}

    public static function none(): self
    {
        return new self(BillingStatusEnums::NONE, self::SOURCE_NONE);
    }

    public function allowsWrites(): bool
    {
        return $this->status->allowsWrites();
    }

    public function readOnly(): bool
    {
        return ! $this->allowsWrites();
    }

    public function onTrial(): bool
    {
        return $this->status === BillingStatusEnums::TRIALING;
    }

    /**
     * Whether money has ever changed hands for this account, which is the difference between "your
     * trial has ended" and "your subscription has ended" - two very different things to be told.
     */
    public function everPaid(): bool
    {
        return $this->source === self::SOURCE_PROVIDER || $this->source === self::SOURCE_MANUAL;
    }

    /**
     * Whole days until access stops, or null where nothing is counting down.
     *
     * Compared at day boundaries rather than to the minute, because that is how a countdown reads:
     * a trial expiring tonight says 0 days left, not "0.4".
     */
    public function daysRemaining(): ?int
    {
        if ($this->endsAt === null) {
            return null;
        }

        return (int) Carbon::now()->startOfDay()->diffInDays($this->endsAt->copy()->startOfDay(), false);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'label' => $this->status->label(),
            'source' => $this->source,
            'plan' => $this->plan?->toArray(),
            'endsAt' => $this->endsAt?->toIso8601String(),
            'daysRemaining' => $this->daysRemaining(),
            'onTrial' => $this->onTrial(),
            'everPaid' => $this->everPaid(),
            'readOnly' => $this->readOnly(),
            'manageable' => $this->manageable,
        ];
    }
}
