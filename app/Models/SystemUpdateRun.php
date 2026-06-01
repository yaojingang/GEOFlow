<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SystemUpdateRun extends Model
{
    protected $fillable = [
        'run_uuid',
        'action',
        'status',
        'current_version',
        'target_version',
        'current_commit',
        'target_commit',
        'deployment_mode',
        'risk_level',
        'plan_json',
        'plan_path',
        'backup_path',
        'log_path',
        'error_message',
        'started_by_admin_id',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'plan_json' => 'array',
            'started_by_admin_id' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'started_by_admin_id');
    }

    public function backups(): HasMany
    {
        return $this->hasMany(SystemUpdateBackup::class, 'run_id');
    }
}
