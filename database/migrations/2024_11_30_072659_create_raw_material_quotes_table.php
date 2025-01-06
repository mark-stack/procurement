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
        Schema::create('raw_material_quotes', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->integer("csv_index");
            $table->text("description");
            $table->text("product_category")->nullable();
            $table->text("material")->nullable();
            $table->text("grade")->nullable();
            $table->text("surface")->nullable();
            $table->string("nominal_units");
            $table->string("length_required");
            $table->string("width_required")->nullable();
            $table->string("sub_qty");
            $table->string("unit_rate")->nullable();
            $table->foreignId('project_id')->constrained();
            $table->text("general_product_matches")->nullable();
            $table->text("custom_product_matches")->nullable();
            $table->boolean("custom_confirmed")->default(false);
            $table->text("assembly_mark");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('raw_material_quotes');
    }
};
