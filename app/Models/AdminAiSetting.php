<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAiSetting extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'default_chat_model_id' => 'integer',
            'default_embedding_model_id' => 'integer',
            'updated_by_admin_id' => 'integer',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function defaultChatModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'default_chat_model_id');
    }

    public function defaultEmbeddingModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'default_embedding_model_id');
    }

    public function updatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }
}
