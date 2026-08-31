<?php

namespace App\Http\Requests\Admin\KnowledgeFacts;

use App\Models\Admin;
use App\Models\KnowledgeBase;
use Illuminate\Foundation\Http\FormRequest;

class KnowledgeFactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');
        $knowledgeBase = KnowledgeBase::query()->with('systemBinding')->find($this->route('knowledgeBaseId'));

        return $admin instanceof Admin && $knowledgeBase instanceof KnowledgeBase
            && (! $knowledgeBase->isSystemManaged() || $admin->canManageProtectedWorkflows());
    }

    public function rules(): array
    {
        return match ((string) $this->route()?->getName()) {
            'admin.knowledge-bases.facts.store' => [
                'stable_key' => ['required', 'string', 'max:160', 'regex:/\A[a-z0-9][a-z0-9._-]*\z/'],
                'label' => ['required', 'string', 'max:255'], 'subject' => ['required', 'string', 'max:255'],
                'predicate' => ['required', 'string', 'max:255'], 'value_type' => ['required', 'in:string,integer,decimal,number,percentage,date,range,boolean,url,path,version'],
                'locale' => ['sometimes', 'string', 'max:16'], 'aliases_json' => ['sometimes', 'array', 'max:50'],
                'aliases_json.*' => ['string', 'max:255'],
                'importance' => ['sometimes', 'in:critical,high,normal'], 'usage_scope' => ['sometimes', 'in:quality_only,quality_and_generation'],
            ],
            'admin.knowledge-bases.facts.update' => [
                'lock_version' => ['required', 'integer', 'min:1'], 'label' => ['sometimes', 'string', 'max:255'],
                'subject' => ['sometimes', 'string', 'max:255'], 'predicate' => ['sometimes', 'string', 'max:255'],
                'value_type' => ['sometimes', 'in:string,integer,decimal,number,percentage,date,range,boolean,url,path,version'],
                'aliases_json' => ['sometimes', 'array', 'max:50'], 'aliases_json.*' => ['string', 'max:255'],
                'importance' => ['sometimes', 'in:critical,high,normal'],
                'usage_scope' => ['sometimes', 'in:quality_only,quality_and_generation'], 'review_status' => ['sometimes', 'in:draft,reviewed,rejected'],
                'is_enabled' => ['sometimes', 'boolean'],
            ],
            'admin.knowledge-bases.facts.review' => ['lock_version' => ['required', 'integer', 'min:1'], 'review_status' => ['required', 'in:draft,reviewed,rejected']],
            'admin.knowledge-bases.facts.archive', 'admin.knowledge-bases.fact-values.archive' => ['lock_version' => ['required', 'integer', 'min:1']],
            'admin.knowledge-bases.fact-values.store' => [
                'canonical_value_json' => ['required', 'array:value,unit'], 'canonical_value_json.value' => ['required', 'string', 'max:5000'],
                'canonical_value_json.unit' => ['nullable', 'string', 'max:64'], 'canonical_answer' => ['required', 'string', 'max:5000'],
                'temporal_kind' => ['sometimes', 'in:timeless,observed,interval'], 'scope_json' => ['sometimes', 'array', 'max:20'],
                'scope_json.*' => ['nullable', 'string', 'max:255'],
                'valid_from' => ['nullable', 'date'], 'valid_to' => ['nullable', 'date'], 'observed_at' => ['nullable', 'date'],
                'comparison_policy_json' => ['sometimes', 'array:tolerance'], 'comparison_policy_json.tolerance' => ['nullable', 'numeric', 'min:0'],
                'review_status' => ['sometimes', 'in:draft,reviewed,rejected'],
            ],
            'admin.knowledge-bases.fact-values.update' => [
                'lock_version' => ['required', 'integer', 'min:1'], 'canonical_value_json' => ['sometimes', 'array:value,unit'],
                'canonical_value_json.value' => ['required_with:canonical_value_json', 'string', 'max:5000'],
                'canonical_value_json.unit' => ['nullable', 'string', 'max:64'], 'canonical_answer' => ['sometimes', 'string', 'max:5000'],
                'temporal_kind' => ['sometimes', 'in:timeless,observed,interval'], 'scope_json' => ['sometimes', 'array', 'max:20'],
                'scope_json.*' => ['nullable', 'string', 'max:255'], 'valid_from' => ['nullable', 'date'],
                'valid_to' => ['nullable', 'date'], 'observed_at' => ['nullable', 'date'], 'comparison_policy_json' => ['sometimes', 'array:tolerance'],
                'comparison_policy_json.tolerance' => ['nullable', 'numeric', 'min:0'],
                'review_status' => ['sometimes', 'in:draft,reviewed,rejected'], 'conflict_status' => ['sometimes', 'in:clear,unresolved,resolved'],
            ],
            'admin.knowledge-bases.fact-evidences.store' => [
                'knowledge_chunk_id' => ['required', 'integer'], 'source_hash' => ['sometimes', 'string', 'size:64'], 'content_hash' => ['sometimes', 'string', 'size:64'],
                'source_locator_json' => ['sometimes', 'array'], 'excerpt' => ['sometimes', 'string', 'max:5000'], 'is_primary' => ['sometimes', 'boolean'],
            ],
            'admin.knowledge-bases.facts.merge' => ['target_fact_id' => ['required', 'integer']],
            'admin.knowledge-bases.facts.split' => ['value_ids' => ['required', 'array', 'min:1'], 'value_ids.*' => ['integer'], 'stable_key' => ['required', 'string', 'max:160', 'regex:/\A[a-z0-9][a-z0-9._-]*\z/'], 'label' => ['required', 'string', 'max:255']],
            default => [],
        };
    }
}
