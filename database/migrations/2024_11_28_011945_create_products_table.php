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
            $table->text("spreadsheet_id")->nullable();     //200PFCSTEELGRADE250NONE2009
            $table->text("description");                    //200PFC
            $table->text("product");                        //PFC
            $table->text("material");                       //STEEL
            $table->text("grade");                          //GR250
            $table->text("surface");                        //NONE
            $table->string("measurement_unit")->nullable(); //METERS
            $table->string("nesting_algo");                 //METERAGE
            $table->string("certificates")->nullable();     //TRUE
            $table->text("size");                           //200
            $table->text("length")->nullable();             //9
            $table->text("width")->nullable();              //1
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
