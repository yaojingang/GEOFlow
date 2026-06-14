<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Models\CaseRecord;
use App\Models\EntityRecord;
use App\Models\KnowledgeBase;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\MaterialAnalysisPromptRules;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;

class CrmInquiryAnalysisService
{
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    /**
     * @return list<array{id:int,name:string}>
     */
    public function modelOptions(): array
    {
        return AiModel::query()
            ->select(['id', 'name'])
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get()
            ->map(static fn (AiModel $model): array => [
                'id' => (int) $model->id,
                'name' => (string) $model->name,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $message, ?int $collectionId = null, int $modelId = 0): array
    {
        $message = trim($message);
        if ($message === '') {
            return [];
        }

        $fallback = $this->fallback($message, $collectionId);
        $model = $this->resolveModel($modelId);
        if (! $model) {
            return $fallback;
        }

        try {
            $content = $this->requestJson($model, $message, $collectionId);
            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                return $fallback;
            }

            return array_replace($fallback, $this->normalizeAiPayload($decoded, $collectionId));
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function resolveModel(int $modelId): ?AiModel
    {
        $query = AiModel::query()
            ->where('status', 'active')
            ->where(function (Builder $builder): void {
                $builder->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            });

        if ($modelId > 0) {
            return (clone $query)->whereKey($modelId)->first();
        }

        return $query->orderBy('failover_priority')->orderBy('id')->first();
    }

    private function requestJson(AiModel $model, string $message, ?int $collectionId): string
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($providerUrl === '' || $apiKey === '') {
            throw new \RuntimeException('AI model is not configured');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($model->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('crm_inquiry_analysis', $driver, $providerUrl, $apiKey);
        $system = 'You are the GEOFlow CRM inquiry analysis assistant. '.MaterialAnalysisPromptRules::jsonOnlyRule();
        $prompt = $this->buildPrompt($message, $collectionId);

        $response = agent($system)->prompt($prompt, [], $providerName, (string) ($model->model_id ?? ''));
        $content = OpenAiRuntimeProvider::normalizeGeneratedText((string) ($response->text ?? ''));
        if ($content === '') {
            throw new \RuntimeException('empty ai response');
        }

        return trim(preg_replace('/^```(?:json)?|```$/m', '', $content) ?? $content);
    }

    private function buildPrompt(string $message, ?int $collectionId): string
    {
        $context = [
            'entities' => $this->entityOptions($collectionId),
            'knowledge_bases' => $this->knowledgeBaseOptions($collectionId),
            'cases' => $this->caseOptions($collectionId),
        ];

        return implode("\n\n", [
            MaterialAnalysisPromptRules::autoLanguageDirective(),
            MaterialAnalysisPromptRules::jsonOnlyRule(),
            MaterialAnalysisPromptRules::factGroundingRules(),
            'Analyze the customer inquiry. Do not create new entities, tags, knowledge bases, or cases. Recommend only existing object ids from the provided context.',
            'Return JSON with keys: detected_language, customer_need_summary, product_interest, entity_ids, knowledge_base_ids, case_record_ids, suggested_reply_points, missing_information_questions, urgency_level.',
            "Existing selectable objects:\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            "Customer inquiry:\n".$message,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fallback(string $message, ?int $collectionId): array
    {
        $summary = Str::limit(trim(preg_replace('/\s+/u', ' ', $message) ?? $message), 800, '');

        return [
            'detected_language' => $this->detectLanguage($message),
            'customer_need_summary' => $summary,
            'product_interest' => $this->productInterest($message),
            'entity_ids' => $this->matchedIds(EntityRecord::class, $message, $collectionId, ['name', 'aliases', 'description']),
            'knowledge_base_ids' => $this->matchedIds(KnowledgeBase::class, $message, $collectionId, ['name', 'summary', 'description', 'content']),
            'case_record_ids' => $this->matchedIds(CaseRecord::class, $message, $collectionId, ['title', 'summary', 'challenge', 'solution', 'result']),
            'suggested_reply_points' => $this->replyPoints($message),
            'missing_information_questions' => $this->missingQuestions($message),
            'urgency_level' => $this->urgencyLevel($message),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeAiPayload(array $payload, ?int $collectionId): array
    {
        return [
            'detected_language' => trim((string) ($payload['detected_language'] ?? '')),
            'customer_need_summary' => trim((string) ($payload['customer_need_summary'] ?? '')),
            'product_interest' => trim((string) ($payload['product_interest'] ?? '')),
            'entity_ids' => $this->validIds(EntityRecord::class, $payload['entity_ids'] ?? $payload['recommended_entities'] ?? [], $collectionId),
            'knowledge_base_ids' => $this->validIds(KnowledgeBase::class, $payload['knowledge_base_ids'] ?? $payload['recommended_knowledge_bases'] ?? [], $collectionId),
            'case_record_ids' => $this->validIds(CaseRecord::class, $payload['case_record_ids'] ?? $payload['recommended_cases'] ?? [], $collectionId),
            'suggested_reply_points' => $this->textList($payload['suggested_reply_points'] ?? ''),
            'missing_information_questions' => $this->textList($payload['missing_information_questions'] ?? ''),
            'urgency_level' => trim((string) ($payload['urgency_level'] ?? '')),
        ];
    }

    /**
     * @param  class-string<EntityRecord|KnowledgeBase|CaseRecord>  $modelClass
     * @param  list<string>  $fields
     * @return list<int>
     */
    private function matchedIds(string $modelClass, string $message, ?int $collectionId, array $fields): array
    {
        $messageLower = mb_strtolower($message, 'UTF-8');

        return $modelClass::query()
            ->when($collectionId !== null && $collectionId > 0, static fn ($query) => $query->where('collection_id', $collectionId))
            ->limit(300)
            ->get()
            ->map(function ($record) use ($fields, $messageLower): array {
                $score = 0;
                foreach ($fields as $field) {
                    $value = mb_strtolower((string) ($record->{$field} ?? ''), 'UTF-8');
                    if ($value === '') {
                        continue;
                    }
                    foreach ($this->tokens($value) as $token) {
                        if ($token !== '' && mb_strlen($token, 'UTF-8') >= 3 && str_contains($messageLower, $token)) {
                            $score += $field === $fields[0] ? 4 : 1;
                        }
                    }
                    if (mb_strlen($value, 'UTF-8') >= 3 && str_contains($messageLower, $value)) {
                        $score += 8;
                    }
                }

                return ['id' => (int) $record->id, 'score' => $score];
            })
            ->filter(static fn (array $item): bool => (int) $item['score'] > 0)
            ->sortByDesc('score')
            ->take(8)
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * @param  class-string<EntityRecord|KnowledgeBase|CaseRecord>  $modelClass
     * @return list<int>
     */
    private function validIds(string $modelClass, mixed $ids, ?int $collectionId): array
    {
        $idList = collect(is_array($ids) ? $ids : [])
            ->map(static fn ($id): int => (int) (is_array($id) ? ($id['id'] ?? 0) : $id))
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($idList === []) {
            return [];
        }

        return $modelClass::query()
            ->when($collectionId !== null && $collectionId > 0, static fn ($query) => $query->where('collection_id', $collectionId))
            ->whereIn('id', $idList)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<array{id:int,label:string,meta:string,collection_id:int}>
     */
    private function entityOptions(?int $collectionId): array
    {
        return EntityRecord::query()
            ->with('collection')
            ->when($collectionId !== null && $collectionId > 0, static fn ($query) => $query->where('collection_id', $collectionId))
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(static fn (EntityRecord $entity): array => [
                'id' => (int) $entity->id,
                'label' => (string) $entity->name,
                'meta' => trim((string) ($entity->entity_type ?? '').' '.(string) ($entity->collection?->name ?? '')),
                'collection_id' => (int) ($entity->collection_id ?? 0),
            ])
            ->all();
    }

    /**
     * @return list<array{id:int,label:string,meta:string,collection_id:int}>
     */
    private function knowledgeBaseOptions(?int $collectionId): array
    {
        return KnowledgeBase::query()
            ->with('collection')
            ->when($collectionId !== null && $collectionId > 0, static fn ($query) => $query->where('collection_id', $collectionId))
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(static fn (KnowledgeBase $knowledgeBase): array => [
                'id' => (int) $knowledgeBase->id,
                'label' => (string) $knowledgeBase->name,
                'meta' => trim((string) ($knowledgeBase->knowledge_type ?? '').' '.(string) ($knowledgeBase->collection?->name ?? '')),
                'collection_id' => (int) ($knowledgeBase->collection_id ?? 0),
            ])
            ->all();
    }

    /**
     * @return list<array{id:int,label:string,meta:string,collection_id:int}>
     */
    private function caseOptions(?int $collectionId): array
    {
        return CaseRecord::query()
            ->with('collection')
            ->when($collectionId !== null && $collectionId > 0, static fn ($query) => $query->where('collection_id', $collectionId))
            ->orderBy('title')
            ->limit(200)
            ->get()
            ->map(static fn (CaseRecord $caseRecord): array => [
                'id' => (int) $caseRecord->id,
                'label' => (string) $caseRecord->title,
                'meta' => trim((string) ($caseRecord->case_type ?? '').' '.(string) ($caseRecord->collection?->name ?? '')),
                'collection_id' => (int) ($caseRecord->collection_id ?? 0),
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        return preg_split('/[^\p{L}\p{N}_-]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function detectLanguage(string $message): string
    {
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $message) === 1) {
            return 'zh-CN';
        }

        return 'en';
    }

    private function productInterest(string $message): string
    {
        if (preg_match_all('/\b[A-Z]{1,6}[-_]?\d{2,8}[A-Z0-9-]*\b/u', $message, $matches) > 0) {
            return implode(', ', array_values(array_unique($matches[0])));
        }

        return '';
    }

    private function replyPoints(string $message): string
    {
        $points = [
            '确认客户目标应用、预算范围和交付时间。',
            '根据已关联 Entity、知识库和案例准备针对性回复。',
        ];

        if (str_contains(mb_strtolower($message, 'UTF-8'), 'price') || str_contains($message, '报价')) {
            $points[] = '补充报价所需的型号、数量、目的港、贸易条款和付款条件。';
        }

        return implode("\n", $points);
    }

    private function missingQuestions(string $message): string
    {
        $messageLower = mb_strtolower($message, 'UTF-8');
        $questions = [];
        if (! str_contains($messageLower, 'quantity') && ! str_contains($message, '数量')) {
            $questions[] = '请确认采购数量或预计项目规模。';
        }
        if (! str_contains($messageLower, 'country') && ! str_contains($message, '国家')) {
            $questions[] = '请确认客户所在国家或交付目的地。';
        }
        if (! str_contains($messageLower, 'application') && ! str_contains($message, '应用')) {
            $questions[] = '请确认具体应用场景或使用工况。';
        }

        return implode("\n", $questions);
    }

    private function urgencyLevel(string $message): string
    {
        $messageLower = mb_strtolower($message, 'UTF-8');
        if (str_contains($messageLower, 'urgent') || str_contains($messageLower, 'asap') || str_contains($message, '紧急')) {
            return 'high';
        }

        return 'normal';
    }

    private function textList(mixed $value): string
    {
        if (is_array($value)) {
            return collect($value)
                ->map(static fn ($item): string => trim((string) $item))
                ->filter()
                ->implode("\n");
        }

        return trim((string) $value);
    }
}
