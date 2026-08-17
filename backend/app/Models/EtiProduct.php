<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EtiProduct extends Model
{
    protected $fillable = [
        'catalog_number',
        'eti_code',
        'name',
        'series',
        'category',
        'poles',
        'rated_current_a',
        'rated_voltage_v',
        'residual_current_a',
        'rcd_type',
        'busbar_modules',
        'product_url',
        'trip_curve',
        'breaking_capacity_ka',
        'width_modules',
        'width_mm',
        'height_mm',
        'depth_mm',
        'heat_dissipation_w',
        'mounting_type',
        'price',
        'currency',
        'data_source',
        'verified',
        'raw_attributes',
        'compatible_accessories',
    ];

    protected function casts(): array
    {
        return [
            'rated_current_a' => 'decimal:2',
            'rated_voltage_v' => 'decimal:1',
            'residual_current_a' => 'decimal:3',
            'breaking_capacity_ka' => 'decimal:2',
            'width_mm' => 'decimal:2',
            'height_mm' => 'decimal:2',
            'depth_mm' => 'decimal:2',
            'heat_dissipation_w' => 'decimal:2',
            'price' => 'decimal:4',
            'verified' => 'boolean',
            'raw_attributes' => 'array',
            'compatible_accessories' => 'array',
        ];
    }
}
