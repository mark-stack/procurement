<?php

namespace App\Providers;

use App\Listeners\AnnounceVerifiedColleague;
use App\Models\MaterialCertificate;
use App\Models\Order;
use App\Models\Piece;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Template;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\Relations\Relation;
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

        /*
         * Short names for the models the change log records, so record_changes.record_type holds
         * "order" rather than "App\Models\Order".
         *
         * The log outlives the code. A class that moves namespace takes every row pointing at it with
         * it, and the one table that must survive a refactor is the one an auditor reads.
         *
         * morphMap, NOT enforceMorphMap, and User is deliberately absent from it. Laravel's own
         * notifications table is polymorphic on the same mechanism, and a dozen places in this
         * application compare notifiable_type to the literal string "App\Models\User" - enforcing a
         * map would reject that column outright, and mapping User would change what new notification
         * rows store while those comparisons went on looking for the class name.
         */
        Relation::morphMap([
            'order' => Order::class,
            'piece' => Piece::class,
            'material_certificate' => MaterialCertificate::class,
            'product' => Product::class,
            'template' => Template::class,
            'supplier' => Supplier::class,
        ]);
    }
}
