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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('user_id')->constrained();
            $table->foreignId('batch_id')->nullable()->constrained();
            //Which merchant this is for is read off the quote's supplier_category - see the quotes table
            $table->foreignId('quote_id')->nullable()->constrained(); //optional

            //Status
            $table->boolean('order_sent')->default(false);
            $table->boolean('order_confirmation_received')->default(false);
            $table->string('purchase_order_number')->nullable();
            $table->boolean('is_delivered')->default(false);
            $table->text("material_cert_numbers")->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
