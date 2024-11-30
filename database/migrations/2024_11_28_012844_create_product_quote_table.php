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
        Schema::create('product_quote', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('product_id')->constrained();
            $table->foreignId('quote_id')->constrained();
            $table->integer("quantity");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_quote');
    }
};
