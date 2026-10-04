<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One quote per supplier group on a batch, and one order per quote.
     *
     * These were written for the quote/order management page, which provisioned both lazily as it
     * rendered and so could insert the same row twice from two concurrent loads. That page is gone
     * with the projects board, and the quotes key is narrower than it was: it named a supplier_id
     * too, back when a group held a list of merchants and each of them got a quote of their own.
     * There is no suppliers table now - the group is the whole of who a quote is addressed to - so
     * the pair that has to stay unique is the batch and the group.
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->unique(
                ['batch_id', 'supplier_category'],
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
