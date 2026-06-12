<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteThemeReplicationVersion extends Model
{
    protected $fillable = [
        'replication_id',
        'version',
        'prompt_hash',
        'feedback',
        'blueprint_json',
        'files_json',
        'compliance_report_json',
        'draft_views_path',
        'draft_assets_path',
    ];

    protected function casts(): array
    {
        return [
            'replication_id' => 'integer',
            'version' => 'integer',
            'blueprint_json' => 'array',
            'files_json' => 'array',
            'compliance_report_json' => 'array',
        ];
    }

    public function replication(): BelongsTo
    {
        return $this->belongsTo(SiteThemeReplication::class, 'replication_id');
    }
}
