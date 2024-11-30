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
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string("name");
            $table->string("domain");
            $table->string("first_description_cell");
            $table->string("first_material_cell")->nullable();
            $table->string("first_measurement_unit_cell")->nullable();
            $table->string("first_purchasable_qty_cell")->nullable();
            $table->string("first_sub_qty_cell");
            $table->string("first_unit_rate_cell");
            $table->string("random_cell_1");
            $table->text("random_cell_text_1");
            $table->string("random_cell_2");
            $table->text("random_cell_text_2");
            $table->longText("screenshot");
            $table->boolean("active")->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
