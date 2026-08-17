<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceImportProfile extends Model
{
    protected $fillable = [
        'name',
        'column_mapping',
        'header_row',
    ];

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
        ];
    }
}
