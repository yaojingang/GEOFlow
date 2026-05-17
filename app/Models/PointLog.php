<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointLog extends Model
{
    protected $fillable = [
        'organization_id',
        'admin_id',
        'action',
        'points_delta',
        'ref_type',
        'ref_id',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'admin_id' => 'integer',
            'points_delta' => 'integer',
            'ref_id' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
