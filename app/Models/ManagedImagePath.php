<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedImagePath extends Model
{
    protected $fillable = [
        'path_hash',
        'file_path',
        'content_sha256',
        'state',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'lock_version' => 'integer',
        ];
    }
}
