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
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('name')->nullable();
            $table->string('domain');
            $table->boolean('admin_setup_complete')->default(false);
            $table->integer("scrap_threshold_mm")->default(1000);
            $table->boolean("cap_12m_stock")->default(true);

            $table->boolean('meterage_only')->default(true);
            $table->boolean('allow_custom_products')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
