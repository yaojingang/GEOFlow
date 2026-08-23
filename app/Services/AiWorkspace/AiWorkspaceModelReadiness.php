<?php

namespace App\Services\AiWorkspace;

use App\Models\AiModel;
use Illuminate\Support\Facades\Schema;

final class AiWorkspaceModelReadiness
{
    /** @return array{ready:bool,reason:string|null,model_id:int|null} */
    public function status(): array
    {
        $conversationConnection = config('ai.conversations.connection');
        if (is_string($conversationConnection)
            && $conversationConnection !== ''
            && $conversationConnection !== (string) config('database.default')) {
            return ['ready' => false, 'reason' => 'AI 会话与工作台必须使用同一数据库连接。', 'model_id' => null];
        }

        if (! Schema::hasTable('ai_models')) {
            return ['ready' => false, 'reason' => 'AI 模型表尚未创建。', 'model_id' => null];
        }

        $query = AiModel::query()
            ->where('status', 'active')
            ->where(function ($builder): void {
                $builder->whereNull('model_type')->orWhereNotIn('model_type', ['embedding', 'image']);
            });

        if ((bool) config('ai-workspace.require_verified_model', true)) {
            if (! Schema::hasColumn('ai_models', 'ai_workspace_structured_output_status')) {
                return ['ready' => false, 'reason' => '请先执行数据库升级并完成结构化输出检测。', 'model_id' => null];
            }
            $query->where('ai_workspace_structured_output_status', 'ready')
                ->whereNotNull('ai_workspace_structured_output_verified_at');
        }

        $model = $query->orderBy('failover_priority')->orderBy('id')->first();

        return $model instanceof AiModel
            ? ['ready' => true, 'reason' => null, 'model_id' => (int) $model->id]
            : ['ready' => false, 'reason' => '至少需要一个已启用且通过结构化输出检测的对话模型。', 'model_id' => null];
    }
}
