<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteThemeBinding extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'site_key';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array', 'lock_version' => 'integer'];
    }
}
