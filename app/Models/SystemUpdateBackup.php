<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemUpdateBackup extends Model
{
    protected $fillable = [
        'backup_uuid',
        'run_id',
        'from_version',
        'to_version',
        'from_commit',
        'to_commit',
        'backup_path',
        'manifest_path',
        'files_archive_path',
        'database_dump_path',
        'file_count',
        'total_bytes',
        'status',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'file_count' => 'integer',
            'total_bytes' => 'integer',
            'created_by_admin_id' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SystemUpdateRun::class, 'run_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
