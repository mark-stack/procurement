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
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            /**
             * Product
             *  - name
             *  - measurement_unit
             *  - material
             */
            $table->foreignId('product_id')->constrained();

            /**
             * Order
             *   - user_id
             *   - project_id
             *   - quote_id
             */
            $table->foreignId('order_id')->constrained();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
