<?php

namespace App\Providers;

use App\Listeners\AnnounceVerifiedColleague;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        /*
         * Registered by hand rather than left to listener discovery: this application has no
         * EventServiceProvider and no other listener, so nothing here would make discovery's
         * absence obvious if it were off. See App\Listeners\AnnounceVerifiedColleague for why the
         * announcement waits for verification.
         */
        Event::listen(Verified::class, AnnounceVerifiedColleague::class);
    }
}
