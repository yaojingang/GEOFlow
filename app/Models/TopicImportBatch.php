<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TopicImportBatch extends Model
{
    protected $fillable = ['request_key', 'owner_admin_id', 'site_key', 'settings', 'rows', 'status', 'generation'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'rows' => 'array', 'generation' => 'integer'];
    }
}
