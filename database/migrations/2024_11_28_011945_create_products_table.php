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

            $table->text("description");            //200PFC
            $table->text("product");                //PFC
            $table->text("material");               //STEEL
            $table->text("grade");                  //GR250
            $table->text("surface");                //NONE
            $table->string("measurement_unit");     //METERS
            $table->text("size");                   //200
            $table->text("length");                 //9
            $table->text("width");                  //1
            $table->text("kg_per_m");               //17.5
            $table->text("baseline_unit_rate");     //$13.54
            $table->string('domain')->nullable();
            $table->boolean("deprecated")->default(false);
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
