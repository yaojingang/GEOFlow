<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadSubmission extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_CONVERTED = 'converted';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CONTACTED,
        self::STATUS_QUALIFIED,
        self::STATUS_INVALID,
        self::STATUS_CONVERTED,
    ];

    protected $fillable = [
        'lead_form_id',
        'status',
        'payload',
        'source_url',
        'ip_address',
        'user_agent',
        'note',
        'handled_by',
        'handled_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_NEW,
        'ip_address' => '',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'handled_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(LeadForm::class, 'lead_form_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'handled_by');
    }
}
