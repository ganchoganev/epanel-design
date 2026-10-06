<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_maps', function (Blueprint $table) {
            $table->id();
            $table->string('signature')->unique();
            $table->string('catalog_number');
            $table->string('name');
            $table->decimal('unit_price', 12, 4)->nullable();
            $table->string('source')->default('offer');
            $table->timestamps();
        });

        $now = now();
        DB::table('schedule_maps')->insert([
            [
                'signature' => '40A/2P/30MA AC',
                'catalog_number' => '002062123',
                'name' => 'Дефектнотокова защита EFI-2 AC 40/003',
                'unit_price' => 33.57,
                'source' => 'Оферта Зелено дърво',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'signature' => '25A/2P/30MA AC',
                'catalog_number' => '002062122',
                'name' => 'Дефектнотокова защита EFI-2 AC 25/003',
                'unit_price' => 31.22,
                'source' => 'Оферта Зелено дърво',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'signature' => 'C16A/1+N',
                'catalog_number' => '002173124',
                'name' => 'Комбиниран МАП с ДТЗ KZS-2M AC C16/003',
                'unit_price' => 44.36,
                'source' => 'Оферта Зелено дърво',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'signature' => '2.5-4A',
                'catalog_number' => '004600080',
                'name' => 'Моторна защита MS25-4',
                'unit_price' => 32.23,
                'source' => 'Оферта Зелено дърво',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_maps');
    }
};
