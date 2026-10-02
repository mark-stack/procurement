<?php

namespace App\Providers;

use App\Models\MaterialCertificate;
use App\Models\Order;
use App\Models\Piece;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Template;
use Illuminate\Database\Eloquent\Relations\Relation;
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
         * No listener is registered here, and that is the point of the comment.
         *
         * Event::listen(Verified::class, AnnounceVerifiedColleague::class) stood here, described as
         * registered by hand because discovery might be off. Discovery is on - it is the framework
         * default for app/Listeners, and `artisan event:list` showed Verified resolving to
         * AnnounceVerifiedColleague twice - so the line was a second registration of a listener that
         * was already registered, and handle() ran twice per verification. Nothing came of it only
         * because NotificationNewColleagueImplementation::hasBeenNotified() keys on the new user's id
         * and refused the second announcement.
         *
         * It cost something the day RecordNotificationDelivery was added: a log of what was sent,
         * registered the same careful way, wrote every row twice, and "we emailed them twice" is a
         * claim that log exists to make truthfully.
         *
         * So discovery is the one mechanism now. Both listeners are found by their handle()
         * type-hint - App\Listeners\AnnounceVerifiedColleague on Verified,
         * App\Listeners\RecordNotificationDelivery on NotificationSent - and a listener added to
         * app/Listeners needs nothing here. Check with `artisan event:list`, which is what would have
         * answered this question in the first place.
         */

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
