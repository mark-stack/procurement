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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->timestamps();

            $table->text('name');
            $table->foreignId('user_id')->constrained();
            $table->string('reference')->nullable();
            $table->date('date_materials_required')->nullable();
            $table->boolean('tentative')->default(true);
            $table->boolean('archive')->default(false);
            $table->longText("items_not_found")->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
