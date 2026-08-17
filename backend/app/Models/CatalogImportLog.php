<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogImportLog extends Model
{
    protected $fillable = [
        'source_file',
        'status',
        'imported_count',
        'updated_count',
        'skipped_count',
        'message',
    ];
}
