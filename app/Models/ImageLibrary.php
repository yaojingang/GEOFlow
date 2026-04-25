<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImageLibrary extends Model
{
    protected $table = 'image_libraries';

    protected $fillable = [
        'name',
        'description',
        'image_count',
        'used_task_count',
    ];

    protected function casts(): array
    {
        return [
            'image_count' => 'integer',
            'used_task_count' => 'integer',
        ];
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class, 'library_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'image_library_id');
    }
}
