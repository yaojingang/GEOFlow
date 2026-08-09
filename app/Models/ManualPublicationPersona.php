<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManualPublicationPersona extends Model
{
    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'bio',
        'tone',
        'domain',
        'disclosure_text',
        'is_active',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'created_by_admin_id' => 'integer',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(ManualPublicationAccount::class, 'persona_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(ManualPublication::class, 'persona_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
