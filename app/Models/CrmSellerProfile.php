<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmSellerProfile extends Model
{
    protected $table = 'crm_seller_profiles';

    protected $fillable = [
        'type',
        'name',
        'payload',
        'is_default',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'is_default' => 'boolean',
            'created_by_admin_id' => 'integer',
        ];
    }
}
