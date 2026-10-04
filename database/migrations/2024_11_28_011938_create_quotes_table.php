<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('user_id')->constrained();
            $table->foreignId('batch_id')->nullable()->constrained();
            //The supplier GROUP this quote is for - e.g STEEL_MERCHANT. There is no suppliers table:
            //the group is the whole of who a quote is addressed to.
            $table->string('supplier_category')->nullable();
            $table->string('supplier_quote_reference')->nullable();
            $table->boolean('quote_sent')->default(false);
            $table->float('quoted_price')->nullable();
            $table->integer('quoted_lead_time')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
