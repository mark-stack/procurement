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
            $table->foreignId('batch_id')->constrained();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('project_id')->nullable()->constrained(); //optional
            $table->foreignId('quote_id')->nullable()->constrained(); //optional
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
