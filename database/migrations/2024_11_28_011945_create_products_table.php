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
            $table->text("description");                    //200PFC
            $table->text("product");                        //PFC
            $table->text("material");                       //PLAIN CARBON STEEL
            $table->text("grade");                          //GR250
            $table->text("surface");                        //NONE
            $table->string("nesting_algo");                 //METERAGE
            $table->string("certificates")->nullable();     //TRUE
            $table->string("nominal_units")->nullable();    //MILLIMETERS
            $table->string("nominal_length")->nullable();   //9000
            $table->string("nominal_width")->nullable();    //
            $table->string("nominal_height")->nullable();   //200
            $table->string("pack_size_1")->nullable();      //1
            $table->string("pack_size_2")->nullable();
            $table->string("pack_size_3")->nullable();
            $table->text("kg_per_m")->nullable();           //17.5
            $table->text("baseline_unit_rate")->nullable(); //$13.54
            $table->foreignId('business_id')->nullable()->constrained(); //QSW (null means platform created)
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
