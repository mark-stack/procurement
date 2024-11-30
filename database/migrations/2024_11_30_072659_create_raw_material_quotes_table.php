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
            $table->text("material")->nullable();
            $table->string("measurement_unit");
            $table->string("purchasable_qty");
            $table->string("sub_qty");
            $table->string("unit_rate");
            $table->foreignId('project_id')->constrained();
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
