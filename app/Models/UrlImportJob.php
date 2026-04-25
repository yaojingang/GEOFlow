<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UrlImportJob extends Model
{
    protected $table = 'url_import_jobs';

    protected $fillable = [
        'url',
        'normalized_url',
        'source_domain',
        'page_title',
        'status',
        'current_step',
        'progress_percent',
        'options_json',
        'result_json',
        'error_message',
        'created_by',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(UrlImportJobLog::class, 'job_id');
    }
}
