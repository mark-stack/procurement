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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->text("description");            //e.g 200PFC
            $table->text("material")->nullable();   //e.g Mild Steel
            $table->string("measurement_unit");     //e.g meters
            $table->string('domain')->nullable();

            //$table->float("purchasable_qty"); //todo separate this
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
