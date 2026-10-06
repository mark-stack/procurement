<?php

namespace App\Billing\Drivers;

use App\Billing\BillingCheck;
use App\Billing\Contracts\BillingProvider;
use App\Billing\Plan;
use App\Billing\Plans;
use App\Billing\SubscriptionState;
use App\Enums\BillingStatusEnums;
use App\Models\Business;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Price;

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
     * What the Stripe dashboard actually holds, against what config/billing.php says it should.
     *
     * The failure this exists for is a quiet one. canSell() asks only whether a price id is set, so
     * an id that is mistyped, retired, or copied out of the test dashboard into a live key's
     * environment reads as a plan that is simply not for sale: the billing page drops the card, the
     * annual invoice plan is still there, and the site goes on taking money for the wrong thing with
     * nothing in the log. Nobody finds out from the application. They find out from the revenue.
     *
     * So every field that has to line up is compared here, one call per card plan, before the
     * switch is thrown rather than after.
     *
     * @return array<int, BillingCheck>
     */
    public function preflight(): array
    {
        $checks = [BillingCheck::ok('driver', 'stripe - card checkouts hosted by Stripe, cancellation in their portal.')];

        //Reached at all only because BillingServiceProvider refused to build this driver without one
        $checks[] = BillingCheck::ok('webhook secret', 'set, so POST /stripe/webhook verifies its signatures.');

        $checks[] = config('cashier.key')
            ? BillingCheck::ok('STRIPE_KEY', 'set.')
            : BillingCheck::fail('STRIPE_KEY', 'empty. Cashier needs the publishable key.');

        if (! config('cashier.secret')) {
            $checks[] = BillingCheck::fail('STRIPE_SECRET', 'empty, so nothing below could be checked against Stripe.');

            return $checks;
        }

        $checks[] = BillingCheck::ok('STRIPE_SECRET', 'set.');

        /*
         * GST. Prices are quoted ex GST and we are the merchant of record, so with this off every
         * customer is charged the bare $39 and the GST on it comes out of the fee. A warning rather
         * than a failure: it is a commercial decision, and a wrong one is recoverable.
         */
        $checks[] = config('billing.collect_tax')
            ? BillingCheck::ok('GST', 'BILLING_COLLECT_TAX is on, so Stripe Tax adds GST to the ex-GST prices at the checkout.')
            : BillingCheck::warn('GST', 'BILLING_COLLECT_TAX is off, so customers are charged the bare ex-GST figure and the GST on it comes out of the fee.');

        foreach ($this->plans->published() as $plan) {
            $checks[] = $this->checkPlan($plan);
        }

        return $checks;
    }

    /**
     * One plan against the price object Stripe holds for it.
     */
    private function checkPlan(Plan $plan): BillingCheck
    {
        $subject = "plan: {$plan->key}";

        if ($plan->invoiceOnly()) {
            return BillingCheck::ok($subject, "{$plan->amountFormatted()} per {$plan->interval}, invoiced - Stripe never prices this one, so there is nothing to check.");
        }

        $id = $plan->priceFor($this->name());

        if ($id === null) {
            return BillingCheck::fail($subject, 'no price id. '.strtoupper("stripe_price_{$plan->key}").' is empty, so this plan is silently withheld from the billing page.');
        }

        try {
            $price = $this->retrievePrice($id);
        } catch (AuthenticationException) {
            return BillingCheck::fail($subject, 'STRIPE_SECRET was refused by Stripe.');
        } catch (InvalidRequestException) {
            return BillingCheck::fail($subject, "[{$id}] is not a price in this Stripe account - mistyped, deleted, or from the other mode's dashboard.");
        } catch (ApiErrorException $exception) {
            return BillingCheck::fail($subject, "Stripe could not be asked about [{$id}]: {$exception->getMessage()}");
        }

        $wrong = $this->mismatches($plan, $price);

        if ($wrong !== []) {
            return BillingCheck::fail($subject, "[{$id}] ".implode('; ', $wrong).'.');
        }

        /*
         * Mode, which is not a mismatch with config - config cannot know - but is the one that ends
         * with a month of real subscriptions sitting in the test dashboard.
         */
        if (app()->isProduction() && $price->livemode !== true) {
            return BillingCheck::fail($subject, "[{$id}] is a test-mode price, and this is production.");
        }

        if (! app()->isProduction() && $price->livemode === true) {
            return BillingCheck::warn($subject, "[{$id}] matches, but it is a live-mode price and this is not production - a checkout here charges a real card.");
        }

        $tax = $this->taxMismatch($price);

        if ($tax !== null) {
            return BillingCheck::warn($subject, "[{$id}] matches, but {$tax}.");
        }

        return BillingCheck::ok($subject, "[{$id}] matches: {$plan->amountFormatted()} per {$plan->interval}, ".strtoupper($plan->currency).', tax-exclusive.');
    }

    /**
     * Every way the Stripe price can disagree with the plan, collected rather than returned one at a
     * time - whoever is reading this is about to go and edit the price, and should see all of it.
     *
     * @return array<int, string>
     */
    private function mismatches(Plan $plan, Price $price): array
    {
        $wrong = [];

        if ($price->active !== true) {
            $wrong[] = 'is archived in Stripe, so a checkout with it is refused';
        }

        if ($price->type !== 'recurring' || $price->recurring === null) {
            $wrong[] = 'is a one-off price, not a subscription';

            //Nothing below applies to a one-off price, and reporting its missing interval is noise
            return $wrong;
        }

        if ($price->recurring->interval !== $plan->interval) {
            $wrong[] = "renews every {$price->recurring->interval}, not every {$plan->interval}";
        }

        if (($price->recurring->interval_count ?? 1) !== 1) {
            $wrong[] = "renews every {$price->recurring->interval_count} {$price->recurring->interval}s, and the plan is quoted per {$plan->interval}";
        }

        if (strtolower((string) $price->currency) !== strtolower($plan->currency)) {
            $wrong[] = 'is in '.strtoupper((string) $price->currency).', not '.strtoupper($plan->currency);
        }

        /*
         * Tiered and usage-based prices have no unit_amount at all, which would otherwise read as a
         * price of nothing and compare unequal with a confusing message.
         */
        if ($price->unit_amount === null) {
            $wrong[] = "is {$price->billing_scheme}-priced, and the billing page quotes one fixed amount";
        } elseif ((int) $price->unit_amount !== $plan->amount) {
            $wrong[] = 'charges '.number_format($price->unit_amount / 100, 2).', and the billing page says '.$plan->amountFormatted();
        }

        return $wrong;
    }

    /**
     * Whether the price's tax behaviour matches what is being done about GST, in words.
     *
     * Null when they agree. Both disagreements are worth saying and neither is a failure here: with
     * automatic_tax on, an unspecified behaviour is Stripe's own error at the checkout and an
     * inclusive one quietly treats the $39 as GST-inclusive, which undercharges by an eleventh.
     */
    private function taxMismatch(Price $price): ?string
    {
        if (! config('billing.collect_tax')) {
            return $price->tax_behavior === 'inclusive'
                ? 'it is tax-inclusive while GST collection is off, so the amount charged includes a GST nobody is remitting'
                : null;
        }

        return match ($price->tax_behavior) {
            'exclusive' => null,
            'inclusive' => 'it is tax-inclusive, so Stripe Tax takes GST out of the $39 instead of adding it',
            default => 'its tax behaviour is unspecified, which Stripe Tax refuses at the checkout unless the account has a default set',
        };
    }

    /**
     * The one call to Stripe, kept behind a method so a test can answer it without a key.
     */
    protected function retrievePrice(string $id): Price
    {
        return Cashier::stripe()->prices->retrieve($id);
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
