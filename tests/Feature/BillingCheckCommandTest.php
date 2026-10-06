<?php

use App\Billing\Contracts\BillingProvider;
use App\Billing\Drivers\StripeBillingProvider;
use App\Billing\Plans;
use Stripe\Exception\InvalidRequestException;
use Stripe\Price;

/**
 * The go-live preflight, and the silence it exists to break.
 *
 * Every other way of getting billing wrong announces itself. A wrong price id does not: canSell()
 * asks only whether an id is set, so a typo takes the plan off the billing page and leaves the rest
 * of the site working perfectly. These tests are mostly about that one failure, written from the
 * question "would anybody find out" rather than "does the method return false".
 *
 * Nothing here reaches the network. The one call Stripe would answer is behind
 * StripeBillingProvider::retrievePrice(), and the double below is what answers it instead.
 */
class StubbedStripeProvider extends StripeBillingProvider
{
    /** @var array<string, Price|Throwable> price id => what Stripe says about it */
    public array $prices = [];

    protected function retrievePrice(string $id): Price
    {
        $answer = $this->prices[$id] ?? InvalidRequestException::factory("No such price: '{$id}'", 404);

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }
}

/**
 * A Stripe price object as the API returns one, correct in every field unless told otherwise.
 */
function stripePrice(array $attributes = []): Price
{
    return Price::constructFrom(array_merge([
        'id' => 'price_weekly_live',
        'object' => 'price',
        'active' => true,
        'billing_scheme' => 'per_unit',
        'currency' => 'aud',
        'livemode' => false,
        'type' => 'recurring',
        'recurring' => ['interval' => 'week', 'interval_count' => 1],
        'tax_behavior' => 'exclusive',
        'unit_amount' => 3900,
    ], $attributes));
}

/**
 * What the preflight said, as one block of text.
 *
 * Asserted against instead of the command's output because the command wraps each detail to fit a
 * terminal, which puts newlines in the middle of the sentences these tests care about. The command
 * gets the assertions that are actually its own - the exit code, and that it survives a driver that
 * refuses to build.
 */
function preflightText(?BillingProvider $provider = null): string
{
    $provider ??= app(BillingProvider::class);

    return collect($provider->preflight())
        ->map(fn (App\Billing\BillingCheck $check): string => strtoupper($check->level)." {$check->subject}: {$check->detail}")
        ->implode(PHP_EOL);
}

/**
 * Make Stripe the live driver, with the stub standing in for the real one, and point the weekly
 * plan at $priceId.
 */
function stubbedStripe(array $prices = [], ?string $priceId = 'price_weekly_live'): StubbedStripeProvider
{
    config([
        'billing.driver' => 'stripe',
        'billing.collect_tax' => true,
        'billing.plans.weekly.prices.stripe' => $priceId,
        'cashier.key' => 'pk_test_x',
        'cashier.secret' => 'sk_test_x',
        'cashier.webhook.secret' => 'whsec_test',
    ]);

    $stub = new StubbedStripeProvider(app(Plans::class));
    $stub->prices = $prices;

    app()->instance(BillingProvider::class, $stub);
    app()->forgetInstance(App\Billing\Billing::class);

    return $stub;
}

it('would be a disaster if a mistyped price id passed the check that exists to catch it', function () {
    //Nothing in $prices, so the stub answers the way Stripe answers for an id that is not there
    $stub = stubbedStripe(priceId: 'price_wekly_live');

    expect(preflightText($stub))->toContain('is not a price in this Stripe account');

    $this->artisan('billing:check')->assertExitCode(1);
});

it('would be a disaster if a plan with no price id were called configured', function () {
    /*
     * The quietest failure of the lot. An empty STRIPE_PRICE_WEEKLY is not an error anywhere: the
     * billing page simply stops offering the weekly plan and goes on selling the annual one.
     */
    $stub = stubbedStripe(priceId: null);

    expect(preflightText($stub))
        ->toContain('STRIPE_PRICE_WEEKLY')
        ->toContain('silently withheld from the billing page');

    $this->artisan('billing:check')->assertExitCode(1);
});

it('would be a disaster if a price that charges the wrong amount were sold as the right one', function () {
    $stub = stubbedStripe(['price_weekly_live' => stripePrice(['unit_amount' => 3500])]);

    expect(preflightText($stub))->toContain('charges 35.00, and the billing page says A$39');

    $this->artisan('billing:check')->assertExitCode(1);
});

it('would be a disaster if a price that disagreed with the plan were accepted', function (array $attributes, string $expected) {
    $stub = stubbedStripe(['price_weekly_live' => stripePrice($attributes)]);

    expect(preflightText($stub))->toContain($expected);

    $this->artisan('billing:check')->assertExitCode(1);
})->with([
    'archived in the dashboard' => [['active' => false], 'is archived in Stripe'],
    'a one-off charge' => [['type' => 'one_time', 'recurring' => null], 'is a one-off price'],
    'the wrong interval' => [[
        'recurring' => ['interval' => 'month', 'interval_count' => 1],
    ], 'renews every month'],
    'four-weekly rather than weekly' => [[
        'recurring' => ['interval' => 'week', 'interval_count' => 4],
    ], 'renews every 4 weeks'],
    'the wrong currency' => [['currency' => 'usd'], 'is in USD'],
    'priced per unit of usage' => [[
        'billing_scheme' => 'tiered',
        'unit_amount' => null,
    ], 'tiered-priced'],
]);

it('would be a disaster if a test-mode price went live in production', function () {
    /*
     * A month of subscriptions in the test dashboard, every one of them free, and a customer list
     * that looks right until somebody opens Stripe. The ids differ by four characters.
     */
    app()->detectEnvironment(fn (): string => 'production');

    $stub = stubbedStripe(['price_weekly_live' => stripePrice(['livemode' => false])]);

    expect(preflightText($stub))->toContain('is a test-mode price, and this is production');

    $this->artisan('billing:check')->assertExitCode(1);
});

it('would be a disaster if stripe were selected without a webhook secret and this command died on it', function () {
    /*
     * BillingServiceProvider refuses to build the driver at all without one, which is right, but it
     * means the command that is supposed to diagnose a half-finished setup is the thing that
     * crashes on it. A server configured this far answers every request with a 500 - this has to
     * say so rather than add a stack trace to the pile.
     */
    config(['billing.driver' => 'stripe', 'cashier.webhook.secret' => null]);

    app()->forgetInstance(BillingProvider::class);
    app()->forgetInstance(App\Billing\Billing::class);

    $this->artisan('billing:check')
        ->expectsOutputToContain('STRIPE_WEBHOOK_SECRET')
        ->assertExitCode(1);
});

it('would be a disaster if GST were quietly not collected on prices quoted ex GST', function () {
    $stub = stubbedStripe(['price_weekly_live' => stripePrice()]);

    config(['billing.collect_tax' => false]);

    /*
     * A warning, not a failure: it is a commercial decision and the money still arrives. But the
     * decision on 2026-10-06 was to collect, so an environment where this is off is one somebody
     * should be told about before the first card is charged - and a deploy step that wants to stop
     * on it can say --strict.
     */
    expect(preflightText($stub))->toContain('charged the bare ex-GST figure');

    $this->artisan('billing:check')->assertExitCode(0);
    $this->artisan('billing:check --strict')->assertExitCode(1);
});

it('would be a disaster if a tax-inclusive price were used while Stripe Tax was adding GST', function () {
    //$39 treated as GST-inclusive is $35.45 of revenue, on every invoice, with nothing to show for it
    $stub = stubbedStripe(['price_weekly_live' => stripePrice(['tax_behavior' => 'inclusive'])]);

    expect(preflightText($stub))->toContain('Stripe Tax takes GST out of');

    $this->artisan('billing:check')->assertExitCode(0);
});

it('would be a disaster if a price Stripe had no tax behaviour for passed without comment', function () {
    //Stripe Tax refuses a checkout on one of these unless the account carries a default behaviour
    $stub = stubbedStripe(['price_weekly_live' => stripePrice(['tax_behavior' => 'unspecified'])]);

    expect(preflightText($stub))->toContain('tax behaviour is unspecified');
});

it('would be a disaster if a correctly configured Stripe account did not pass', function () {
    $stub = stubbedStripe(['price_weekly_live' => stripePrice()]);

    expect(preflightText($stub))
        ->toContain('[price_weekly_live] matches: A$39 per week, AUD, tax-exclusive.')
        //Sold on an invoice on purpose, so no price object exists for it in any provider
        ->toContain('Stripe never prices this one');

    $this->artisan('billing:check')->assertExitCode(0);
});

it('would be a disaster if the manual driver let anyone believe a card was being charged', function () {
    /*
     * The state the application is in today. The weekly plan is configured to be paid by card, and
     * on this driver its Subscribe button leads to the invoice page like everything else.
     */
    expect(preflightText())
        ->toContain('invoices raised by hand')
        ->toContain('not a card form');

    $this->artisan('billing:check')->assertExitCode(0);
});

it('would be a disaster if a request for an invoice had nowhere to go', function () {
    config(['billing.invoice_email' => '']);

    expect(preflightText())->toContain('BILLING_INVOICE_EMAIL is empty');

    $this->artisan('billing:check')->assertExitCode(1);
});
