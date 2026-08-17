<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectVersion extends Model
{
    protected $fillable = [
        'project_id',
        'version_number',
        'design_data',
        'panel_config',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'design_data' => 'array',
            'panel_config' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
