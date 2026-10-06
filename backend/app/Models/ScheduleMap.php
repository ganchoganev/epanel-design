<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleMap extends Model
{
    protected $fillable = [
        'signature',
        'catalog_number',
        'name',
        'unit_price',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4',
        ];
    }

    public static function signature(string $text): string
    {
        $text = str_replace(['А', 'С', 'В', ','], ['A', 'C', 'B', '.'], trim($text));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strtoupper($text);
    }
}
