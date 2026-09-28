<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cashier's two tables, with one change: business_id where Cashier ships user_id.
     *
     * Cashier builds its subscriptions() relation from the billable model's own foreign key, and the
     * billable model here is Business (see BillingServiceProvider), so the column has to match or
     * the relation silently returns nothing. Written out by hand rather than published from the
     * package for that reason - and because the package's own copies would want editing after every
     * Cashier upgrade.
     *
     * The application never reads these tables. Only App\Billing\Drivers\StripeBillingProvider
     * does, through Cashier, and only to answer what the subscription's status is.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'stripe_status']);
        });

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id');
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            //Usage billing. Nothing here is metered, but Cashier's model writes these columns.
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'stripe_price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
    }
};
