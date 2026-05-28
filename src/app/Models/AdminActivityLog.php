<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'admin_activity_logs';

    protected $fillable = [
        'admin_id',
        'admin_username',
        'admin_role',
        'action',
        'request_method',
        'page',
        'target_type',
        'target_id',
        'ip_address',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'target_id' => 'integer',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
