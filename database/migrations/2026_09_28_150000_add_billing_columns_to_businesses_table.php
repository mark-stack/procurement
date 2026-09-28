<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billing state on the business, because a subscription belongs to the company rather than to
     * whoever signed up first - the pricing page sells unlimited staff against one licence.
     *
     * Three groups of columns:
     *
     *   trial_ends_at        ours, and the free trial in its entirety. Set on creation, no card,
     *                        no provider involved. (Cashier reads this same column for its
     *                        "generic trial", which is a happy accident rather than the reason.)
     *   manual_*             an invoice settled by bank transfer, and the grandfather clause below.
     *   stripe_id, pm_*      Cashier's customer columns. The only provider-shaped thing here, and
     *                        the price of using Cashier at all; a Paddle driver would add its own
     *                        alongside and leave these alone.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable();

            //The plan key they were invoiced for, and the date that grant runs out. A null date
            //means indefinite, which is what the grandfathered accounts below get.
            $table->string('manual_plan')->nullable();
            $table->timestamp('manual_access_until')->nullable();

            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
        });

        /*
         * Every business that already exists is a customer who has been using the product for free,
         * by arrangement, and never agreed to a subscription. Giving them a trial would start a
         * clock they never signed up to and lock them out of their own cutting lists a month later,
         * so they are granted indefinite access instead. Revoking one is a matter of clearing
         * manual_plan, or setting manual_access_until to the date the arrangement ends.
         */
        DB::table('businesses')->update(['manual_plan' => 'grandfathered']);
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex(['stripe_id']);

            $table->dropColumn([
                'trial_ends_at',
                'manual_plan',
                'manual_access_until',
                'stripe_id',
                'pm_type',
                'pm_last_four',
            ]);
        });
    }
};
