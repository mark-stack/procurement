<?php

namespace App\Console\Commands;

use App\Billing\BillingCheck;
use App\Billing\Contracts\BillingProvider;
use Illuminate\Console\Command;
use InvalidArgumentException;

class CheckBillingConfiguration extends Command
{
    protected $signature = 'billing:check
        {--strict : treat warnings as failures, for a deploy step that should stop}';

    protected $description = 'Check that the live billing driver is configured to sell what the billing page offers';

    /**
     * The preflight for the day BILLING_DRIVER changes.
     *
     * Everything about selling a subscription fails loudly except the part that matters. A bad key
     * is an exception on a click; a bad webhook secret refuses to boot; a plan whose price id is
     * wrong is nothing at all - BillingProvider::canSell() asks only whether an id is set, so a
     * typo, a price archived in the dashboard, or a test-mode id deployed with a live key all read
     * as "this plan is not for sale". The billing page drops the card without comment and sells
     * whatever is left, which here means the annual invoice plan: the site keeps working, keeps
     * taking money, and takes it for the wrong thing.
     *
     * That failure has no symptom inside the application, so it needs a question asked on purpose.
     * This asks it - against Stripe, not against config - and is meant to be run twice: once before
     * the driver is switched, and once on the server afterwards.
     *
     * php artisan billing:check
     */
    public function handle(): int
    {
        $driver = (string) config('billing.driver');

        try {
            $provider = app(BillingProvider::class);
        } catch (InvalidArgumentException $exception) {
            /*
             * The two refusals BillingServiceProvider makes when it builds the driver - an unknown
             * driver name, and stripe without a webhook secret - are checks in their own right, and
             * the whole point of this command is to report them instead of dying on them. Without
             * this, a server set up as far as BILLING_DRIVER=stripe and no further answers every
             * request with a 500 and this command with a stack trace.
             */
            $this->render([BillingCheck::fail("driver: {$driver}", $exception->getMessage())]);

            return self::FAILURE;
        }

        $checks = $provider->preflight();

        $this->render($checks);

        $failed = array_filter($checks, fn (BillingCheck $check): bool => $check->failed());
        $warned = array_filter($checks, fn (BillingCheck $check): bool => $check->warned());

        if ($failed !== []) {
            $this->components->error(count($failed).' of '.count($checks).' checks failed. Nothing should be sold on this configuration.');

            return self::FAILURE;
        }

        if ($warned !== [] && $this->option('strict')) {
            $this->components->error(count($warned).' warnings, and --strict was given.');

            return self::FAILURE;
        }

        $this->components->info($warned === []
            ? "The {$provider->name()} driver is configured to sell everything the billing page offers."
            : "The {$provider->name()} driver can sell everything the billing page offers, with ".count($warned).(count($warned) === 1 ? ' warning' : ' warnings').' worth a look above.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, BillingCheck>  $checks
     */
    private function render(array $checks): void
    {
        $this->table(
            ['', 'Checked', 'Result'],
            array_map(fn (BillingCheck $check): array => [
                match ($check->level) {
                    BillingCheck::OK => 'ok',
                    BillingCheck::WARN => 'WARN',
                    BillingCheck::FAIL => 'FAIL',
                    default => $check->level,
                },
                $check->subject,
                //Wrapped by hand: a Stripe mismatch runs long, and the table would otherwise widen
                //past the terminal and take the column that says which plan it is with it
                wordwrap($check->detail, 72, PHP_EOL),
            ], $checks),
        );
    }
}
