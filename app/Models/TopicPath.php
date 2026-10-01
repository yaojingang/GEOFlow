<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TopicPath extends Model
{
    protected $fillable = ['site_key', 'slug', 'topic_id', 'generation'];

    protected function casts(): array
    {
        return ['topic_id' => 'integer', 'generation' => 'integer'];
    }
}
