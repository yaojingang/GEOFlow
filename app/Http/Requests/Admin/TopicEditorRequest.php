<?php

namespace App\Http\Requests\Admin;

use App\Models\Topic;
use App\Services\Topics\TopicFreshnessService;
use App\Services\Topics\TopicMatchingRules;
use App\Services\Topics\TopicPayload;
use App\Support\TopicAdminContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class TopicEditorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->route('topic');

        return $id ? Gate::forUser(TopicAdminContext::actor())->allows('update', Topic::query()->findOrFail($id)) : Gate::forUser(TopicAdminContext::actor())->allows('create', Topic::class);
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();
        Validator::make($input, [
            'tags_text' => ['nullable', 'string'],
            'summary' => ['nullable', 'array'],
            'summary.facts' => ['nullable', 'array'],
            'summary.facts.*' => ['array'],
            'summary.facts.*.text' => ['nullable', 'string'],
            'summary.facts.*.article_ids_text' => ['nullable', 'string'],
            'faq' => ['nullable', 'array'],
            'faq.*' => ['array'],
            'faq.*.question' => ['nullable', 'string'],
            'faq.*.article_ids_text' => ['nullable', 'string'],
            'articles' => ['nullable', 'array'],
            'articles.*' => ['array'],
            'basic_info' => ['nullable', 'array'],
            'basic_info.*' => ['array'],
            'score' => ['nullable', 'array'],
            'score.dimensions' => ['nullable', 'array'],
            'score.dimensions.*' => ['array'],
            'score.dimensions.*.weight' => ['nullable', 'numeric', 'min:0'],
            'score.evidence' => ['nullable', 'array'],
            'score.evidence.*' => ['array'],
            'score.evidence.*.text' => ['nullable', 'string'],
            'score.evidence.*.article_ids_text' => ['nullable', 'string'],
        ])->validate();
        if (is_array($input['matching_rules'] ?? null)) {
            $input['matching_rules'] = app(TopicMatchingRules::class)->parse($input['matching_rules']);
        }
        if (array_key_exists('display_order', $input) && ($input['display_order'] === null || $input['display_order'] === '')) {
            $input['display_order'] = 0;
        }
        if ($this->boolean('source_overrides_present')) {
            if (! isset($input['source_overrides'])) {
                $input['source_overrides'] = ['excluded_article_ids' => []];
            }
        }
        if (array_key_exists('tags_text', $input)) {
            $input['tags'] = array_values(array_filter(array_map('trim', preg_split('/[,，\n]+/u', (string) $input['tags_text']))));
        }
        foreach (['summary.facts', 'faq', 'score.evidence'] as $path) {
            $rows = data_get($input, $path, []);
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as &$row) {
                if (isset($row['article_ids_text'])) {
                    $row['article_ids'] = array_map('intval', array_filter(preg_split('/[,，\s]+/u', trim((string) $row['article_ids_text']))));
                    unset($row['article_ids_text']);
                }
            }
            unset($row);
            data_set($input, $path, array_values(array_filter($rows, fn ($row) => trim((string) ($row['text'] ?? $row['question'] ?? '')) !== '')));
        }
        foreach (['articles', 'basic_info', 'score.dimensions'] as $path) {
            $rows = data_get($input, $path, []);
            if (is_array($rows)) {
                data_set($input, $path, array_values(array_filter($rows, fn ($row) => filled($row['article_id'] ?? $row['label'] ?? $row['name'] ?? null))));
            }
        }
        if (isset($input['score'])) {
            $input['score']['enabled'] = $this->boolean('score.enabled');
            $weights = [];
            $dimensions = $input['score']['dimensions'] ?? [];
            foreach ($dimensions as &$row) {
                $weights[] = (float) ($row['weight'] ?? 1);
                unset($row['weight']);
            }unset($row);
            $input['score']['dimensions'] = $dimensions;
            $input['score']['weights'] = $weights;
            foreach (['total', 'rated_at', 'valid_until'] as $key) {
                if (($input['score'][$key] ?? null) === '') {
                    unset($input['score'][$key]);
                }
            }
            if (! $input['score']['enabled']) {
                $input['score'] = null;
            }
        }
        $this->merge($input);
    }

    public function rules(): array
    {
        return [
            'site' => ['required', 'string', 'max:80'], 'expected_version' => [$this->route('topic') ? 'required' : 'nullable', 'integer', 'min:1'],
            'seo' => ['nullable', 'array:title,description'], 'seo.title' => ['nullable', 'string', 'max:200'], 'seo.description' => ['nullable', 'string', 'max:500'],
            'display_order' => ['nullable', 'integer', 'between:-1000000,1000000'],
            'title' => ['required', 'string', 'max:200'], 'intro' => ['nullable', 'string', 'max:20000'],
            'summary' => ['nullable', 'array:one_sentence,facts,scope,reading_advice'], 'summary.one_sentence' => ['nullable', 'string', 'max:2000'], 'summary.facts' => ['nullable', 'array', 'max:30'], 'summary.facts.*' => ['array:text,article_ids,evidence,evidence_quotes_text'], 'summary.facts.*.evidence_quotes_text' => ['nullable', 'string', 'max:45000'], 'summary.scope' => ['nullable', 'string', 'max:2000'], 'summary.reading_advice' => ['nullable', 'string', 'max:4000'],
            'tags' => ['nullable', 'array', 'max:30'], 'tags.*' => ['string', 'max:100'],
            'articles' => ['nullable', 'array', 'max:200'], 'articles.*' => ['array:article_id,group,reason'],
            'source_overrides' => ['nullable', 'array:excluded_article_ids'], 'source_overrides.excluded_article_ids' => ['nullable', 'array', 'max:1000'], 'source_overrides.excluded_article_ids.*' => ['integer', 'min:1', 'distinct'],
            'template_key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'basic_info' => ['nullable', 'array', 'max:30'], 'basic_info.*' => ['array:label,value'], 'faq' => ['nullable', 'array', 'max:30'], 'faq.*' => ['array:question,answer,article_ids'],
            'score' => ['nullable', 'array:enabled,type,name,source,total,dimensions,weights,evidence,rated_at,valid_until'],
            'action' => ['nullable', 'in:save,verify_sources,generate,preview,publish'],
            'model_id' => ['required_if:action,generate', 'nullable', 'integer', 'exists:ai_models,id'], 'rules' => ['nullable', 'string', 'max:5000'], 'target_count' => ['nullable', 'integer', 'between:2,24'],
            'request_key' => ['required_if:action,generate,publish', 'nullable', 'uuid'], 'field' => ['nullable', 'in:intro,summary,tags,articles,seo,faq,basic_info'],
            'list_return' => ['nullable', 'string', 'max:2000'],
        ] + TopicFreshnessService::rules() + TopicMatchingRules::rules() + TopicPayload::evidenceRules();
    }

    public function payload(): array
    {
        $payload = $this->safe()->only(['seo', 'display_order', 'title', 'intro', 'summary', 'tags', 'articles', 'source_overrides', 'matching_rules', 'template_key', 'freshness', 'basic_info', 'faq', 'score']);
        if (isset($payload['source_overrides'])) {
            $payload['source_overrides']['excluded_article_ids'] = array_values(array_diff($payload['source_overrides']['excluded_article_ids'] ?? [], array_column($payload['articles'] ?? [], 'article_id')));
        }

        return $payload;
    }
}
