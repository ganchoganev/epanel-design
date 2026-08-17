<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eti_products', function (Blueprint $table) {
            $table->id();
            $table->string('catalog_number')->unique();
            $table->string('name');
            $table->string('series')->nullable()->index();
            $table->string('category')->nullable()->index();
            $table->unsignedTinyInteger('poles')->nullable();
            $table->decimal('rated_current_a', 8, 2)->nullable();
            $table->string('trip_curve', 8)->nullable();
            $table->decimal('breaking_capacity_ka', 8, 2)->nullable();
            $table->unsignedSmallInteger('width_modules')->default(1);
            $table->decimal('width_mm', 8, 2)->nullable();
            $table->decimal('height_mm', 8, 2)->nullable();
            $table->decimal('depth_mm', 8, 2)->nullable();
            $table->decimal('heat_dissipation_w', 8, 2)->nullable();
            $table->string('mounting_type')->nullable();
            $table->decimal('price', 12, 4)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->string('data_source')->default('seed');
            $table->boolean('verified')->default(false);
            $table->json('raw_attributes')->nullable();
            $table->json('compatible_accessories')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eti_products');
    }
};
