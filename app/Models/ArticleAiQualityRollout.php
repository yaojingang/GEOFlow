<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArticleAiQualityRollout extends Model
{
    protected $fillable = [
        'id',
        'principle_percent',
        'execution_percent',
        'scoring_percent',
        'shadow_percent',
        'sampled_auto_release_enabled',
        'frozen',
        'incident_code',
        'latest_evaluation_path',
        'latest_evaluation_at',
    ];

    protected $attributes = [
        'id' => 1,
        'principle_percent' => 0,
        'execution_percent' => 0,
        'scoring_percent' => 0,
        'shadow_percent' => 0,
        'sampled_auto_release_enabled' => true,
        'frozen' => false,
    ];

    public $incrementing = false;

    protected $keyType = 'int';

    protected function casts(): array
    {
        return [
            'principle_percent' => 'integer',
            'execution_percent' => 'integer',
            'scoring_percent' => 'integer',
            'shadow_percent' => 'integer',
            'sampled_auto_release_enabled' => 'boolean',
            'frozen' => 'boolean',
            'latest_evaluation_at' => 'datetime',
        ];
    }
}
