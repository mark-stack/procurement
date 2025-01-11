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
        Schema::create('pieces', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->foreignId('project_id')->constrained();
            $table->foreignId('raw_material_quote_id')->constrained();
            $table->foreignId('quote_id')->nullable()->constrained();
            $table->foreignId('batch_id')->nullable()->constrained();

            $table->text("product_category");               //PFC
            $table->text("material");                       //STEEL
            $table->text("grade");                          //GR250
            $table->text("surface");                        //NONE
            $table->string("nominal_units")->nullable();    //MILLIMETERS
            $table->string("nesting_algo")->nullable();     //METERAGE
            $table->string("nominal_length")->nullable();   //9000
            $table->string("precise_length")->nullable();   //
            $table->string("nominal_width")->nullable();    //
            $table->string("precise_width")->nullable();    //
            $table->string("nominal_height")->nullable();   //200
            $table->string("precise_height")->nullable();   //
            $table->string("wall")->nullable();             //
            $table->float("kg_per_m")->nullable();          //17.5
            $table->string("actual_length");                //9
            $table->string("actual_width")->nullable();     //1
            $table->string("actual_qty")->nullable();       //1
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pieces');
    }
};
