<?php

namespace App\Providers;

use App\Billing\Billing;
use App\Billing\Contracts\BillingProvider;
use App\Billing\Plans;
use App\Models\Business;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Cashier\Cashier;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Plans::class);

        /*
         * The live driver, chosen by config rather than by anything importing it. Bound as a
         * singleton so a driver may hold state for the request, and so tests can swap the whole
         * provider with one $this->app->instance() call.
         */
        $this->app->singleton(BillingProvider::class, function ($app): BillingProvider {
            $driver = (string) config('billing.driver');
            $drivers = (array) config('billing.drivers', []);

            if (! isset($drivers[$driver])) {
                throw new InvalidArgumentException("Billing driver [{$driver}] is not configured.");
            }

            return $app->make($drivers[$driver]);
        });

        $this->app->singleton(Billing::class);
    }

    public function boot(): void
    {
        /*
         * A subscription belongs to the company, not to whoever happened to sign up first: a
         * business has many users, the pricing page sells unlimited staff, and a fabricator whose
         * estimator leaves should not lose their subscription with them.
         *
         * Cashier defaults its customer model to App\Models\User, so it has to be told. Its
         * subscriptions() relation keys off the model's own foreign key, which is why the
         * subscriptions table carries business_id rather than Cashier's stock user_id.
         */
        Cashier::useCustomerModel(Business::class);

        /*
         * Cashier's currency only affects one-off charges and the money it formats for its own
         * invoice PDFs; plan amounts are ours. Kept in step anyway so the two cannot disagree.
         */
        config(['cashier.currency' => config('billing.currency')]);
    }
}
