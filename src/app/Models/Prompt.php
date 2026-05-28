<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prompt extends Model
{
    protected $table = 'prompts';

    protected $fillable = [
        'name',
        'type',
        'content',
        'variables',
    ];

    public function titleLibraries(): HasMany
    {
        return $this->hasMany(TitleLibrary::class, 'prompt_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'prompt_id');
    }
}
