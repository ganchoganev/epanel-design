<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'name',
        'description',
        'client_name',
        'panel_config',
        'design_data',
        'current_version',
        'is_template',
    ];

    protected function casts(): array
    {
        return [
            'panel_config' => 'array',
            'design_data' => 'array',
            'is_template' => 'boolean',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProjectVersion::class);
    }
}
