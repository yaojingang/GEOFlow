<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KeywordLibrary extends Model
{
    protected $table = 'keyword_libraries';

    protected $fillable = [
        'name',
        'description',
        'keyword_count',
    ];

    protected function casts(): array
    {
        return [
            'keyword_count' => 'integer',
        ];
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class, 'library_id');
    }

    public function titleLibraries(): HasMany
    {
        return $this->hasMany(TitleLibrary::class, 'keyword_library_id');
    }
}
