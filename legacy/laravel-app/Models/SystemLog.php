<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'system_logs';

    protected $fillable = [
        'type',
        'message',
        'data',
    ];
}
