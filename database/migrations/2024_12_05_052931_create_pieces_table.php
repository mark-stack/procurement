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

            $table->text("product");                        //PFC
            $table->text("material");                       //STEEL
            $table->text("grade");                          //GR250
            $table->text("surface");                        //NONE
            $table->string("measurement_unit")->nullable(); //METERS
            $table->string("nesting_type")->nullable();     //NEST_METERAGE
            $table->text("size");                           //200
            $table->text("actual_length");                  //9
            $table->text("actual_width")->nullable();       //1
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
