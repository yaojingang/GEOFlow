<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualPublicationTransition extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'manual_publication_id',
        'changed_by_admin_id',
        'from_status',
        'to_status',
        'completion_url',
        'result_note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'manual_publication_id' => 'integer',
            'changed_by_admin_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(ManualPublication::class, 'manual_publication_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'changed_by_admin_id');
    }
}
