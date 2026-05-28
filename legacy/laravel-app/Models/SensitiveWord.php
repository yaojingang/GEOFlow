<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SensitiveWord extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'sensitive_words';

    protected $fillable = [
        'word',
    ];
}
