<?php

namespace App\Http\Requests\Admin\KnowledgeFacts;

use App\Models\Admin;
use App\Models\KnowledgeBase;
use Illuminate\Foundation\Http\FormRequest;

class KnowledgeFactGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');
        $knowledgeBase = KnowledgeBase::query()->with('systemBinding')->find($this->route('knowledgeBaseId'));

        return $admin instanceof Admin && $knowledgeBase instanceof KnowledgeBase && (! $knowledgeBase->isSystemManaged() || $admin->canManageProtectedWorkflows());
    }

    public function rules(): array
    {
        return match ((string) $this->route()?->getName()) {
            'admin.knowledge-bases.fact-generation.store' => ['mode' => ['required', 'in:initial,supplement,refresh_stale'], 'target_count' => ['required', 'integer', 'min:1', 'max:'.config('geoflow.knowledge_fact_generation_max_per_run', 200)], 'ai_model_id' => ['required', 'integer', 'exists:ai_models,id']],
            'admin.knowledge-bases.fact-generation.resolve' => ['action' => ['required', 'in:discard,merge_as_value,create_with_new_key'], 'candidate_index' => ['required', 'integer', 'min:0'], 'stable_key' => ['nullable', 'required_if:action,create_with_new_key', 'string', 'max:160', 'regex:/\A[a-z0-9][a-z0-9._-]*\z/']],
            default => [],
        };
    }
}
