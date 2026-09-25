<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quotes and orders are provisioned lazily while the quote/order management page renders, so two
     * concurrent loads (double click, browser prefetch, a retry) could both miss the lookup and both
     * insert. These constraints are what make the firstOrCreate calls in QuoteFormatter actually
     * race-safe - without them Laravel has no unique violation to catch and retry on.
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->unique(
                ['batch_id', 'supplier_id', 'supplier_category'],
                'quotes_batch_supplier_category_unique'
            );
        });

        Schema::table('orders', function (Blueprint $table) {
            //One order per quote - Order::firstOrCreate(['quote_id' => ...]) already assumes this
            $table->unique('quote_id', 'orders_quote_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropUnique('quotes_batch_supplier_category_unique');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_quote_id_unique');
        });
    }
};
