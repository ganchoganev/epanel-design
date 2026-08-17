<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_import_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('column_mapping');
            $table->unsignedTinyInteger('header_row')->default(1);
            $table->timestamps();
        });

        Schema::create('catalog_import_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source_file');
            $table->string('status');
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_logs');
        Schema::dropIfExists('price_import_profiles');
    }
};
