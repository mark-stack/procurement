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
        Schema::create('bars', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            //Product attributes
            $table->text('product_category');               //PFC
            $table->text('material');                       //PLAIN CARBON STEEL
            $table->text('grade');                          //GR250
            $table->text('surface');                        //NONE
            $table->string('nominal_length')->nullable();   //9000
            $table->string('precise_length')->nullable();   //
            $table->string('nominal_width')->nullable();    //
            $table->string('precise_width')->nullable();    //
            $table->string('nominal_height')->nullable();   //200
            $table->string('precise_height')->nullable();   //
            $table->float('wall')->nullable();              //

            //Other
            $table->string("product_derived_label");
            $table->float('length');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bars');
    }
};
