<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            return $isLocal ||
                   $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        /*
         * _token was the whole list, which is Telescope's own default and not enough here. A 500 in
         * the login, registration, password-reset or confirm-password path is exactly the kind of
         * entry the filter below keeps, and it carried the submitted credentials into the
         * telescope_entries table in plain text.
         */
        Telescope::hideRequestParameters([
            '_token',
            'password',
            'password_confirmation',
            'current_password',
        ]);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        /*
         * isAdmin(), not a comparison against config('env.admin_email'). That comparison was a
         * second way to become the platform admin by editing your own profile email - see
         * User::isAdmin() and the 2026_09_30 migration - and it would have survived the fix to the
         * first one.
         *
         * Nullable, so a guest is answered rather than fatal.
         */
        Gate::define('viewTelescope', function (?User $user) {
            return $user?->isAdmin() === true;
        });
    }
}
