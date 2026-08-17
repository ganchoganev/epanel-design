<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eti_products', function (Blueprint $table) {
            $table->string('currency', 3)->default('EUR')->change();
            $table->string('eti_code')->nullable()->after('catalog_number');
            $table->decimal('rated_voltage_v', 8, 1)->nullable()->after('rated_current_a');
            $table->decimal('residual_current_a', 8, 3)->nullable()->after('rated_voltage_v');
            $table->string('rcd_type', 8)->nullable()->after('residual_current_a');
            $table->unsignedTinyInteger('busbar_modules')->nullable()->after('width_modules');
            $table->string('product_url')->nullable();
        });

        DB::table('eti_products')->where('currency', 'BGN')->update(['currency' => 'EUR']);
    }

    public function down(): void
    {
        Schema::table('eti_products', function (Blueprint $table) {
            $table->string('currency', 3)->default('BGN')->change();
            $table->dropColumn([
                'eti_code',
                'rated_voltage_v',
                'residual_current_a',
                'rcd_type',
                'busbar_modules',
                'product_url',
            ]);
        });
    }
};
