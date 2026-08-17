<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComponentGroup extends Model
{
    protected $fillable = [
        'name',
        'description',
        'is_system',
        'items',
        'connections',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'items' => 'array',
            'connections' => 'array',
        ];
    }
}
