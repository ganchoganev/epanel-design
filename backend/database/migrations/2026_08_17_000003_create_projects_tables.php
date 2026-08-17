<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('client_name')->nullable();
            $table->json('panel_config')->nullable();
            $table->json('design_data');
            $table->unsignedInteger('current_version')->default(1);
            $table->boolean('is_template')->default(false);
            $table->timestamps();
        });

        Schema::create('project_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('design_data');
            $table->json('panel_config')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'version_number']);
        });

        Schema::create('component_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->json('items');
            $table->json('connections')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('component_groups');
        Schema::dropIfExists('project_versions');
        Schema::dropIfExists('projects');
    }
};
