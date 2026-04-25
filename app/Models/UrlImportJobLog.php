<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UrlImportJobLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'url_import_job_logs';

    protected $fillable = [
        'job_id',
        'level',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'job_id' => 'integer',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(UrlImportJob::class, 'job_id');
    }
}
