<?php

namespace Tests\Feature;

use App\Ai\Agents\KnowledgeFactGeneratorAgent;
use App\Models\AiModel;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactAiGenerator;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeFactAiGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_generation_keeps_only_candidates_grounded_in_batch_evidence(): void
    {
        KnowledgeFactGeneratorAgent::fake([['facts' => [
            ['stable_key' => 'company.founded_at', 'label' => '成立时间', 'subject' => '公司', 'predicate' => '成立于', 'value_type' => 'date', 'canonical_value' => '2020', 'canonical_answer' => '公司成立于 2020 年。', 'unit' => '年', 'evidence_keys' => ['chunk:1:aaaaaaaaaaaa']],
            ['stable_key' => 'company.hallucinated', 'label' => '无依据', 'subject' => '公司', 'predicate' => '虚构', 'value_type' => 'string', 'canonical_value' => 'x', 'canonical_answer' => '无依据。', 'unit' => '', 'evidence_keys' => ['chunk:99:ffffffffffff']],
        ]]])->preventStrayPrompts();
        $model = AiModel::query()->create([
            'name' => 'Atomic Facts Model', 'version' => 'test', 'api_key' => app(ApiKeyCrypto::class)->encrypt('fact-test-key'),
            'model_id' => 'fact-model', 'model_type' => 'chat', 'api_url' => 'https://ai.test/v1', 'daily_limit' => 10, 'used_today' => 0, 'total_used' => 0, 'status' => 'active',
        ]);

        $facts = app(KnowledgeFactAiGenerator::class)->generate($model, [[
            'evidence_key' => 'chunk:1:aaaaaaaaaaaa', 'chunk_id' => '1', 'source_hash' => str_repeat('a', 64), 'content_hash' => str_repeat('a', 64), 'section_path' => '企业简介', 'content' => '公司成立于 2020 年。',
        ]], 10);

        $this->assertCount(1, $facts);
        $this->assertSame('company.founded_at', $facts[0]['stable_key']);
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame('ready', data_get($model->fresh()->ai_workspace_readiness_profile, 'knowledge_fact_structured_output.status'));
        KnowledgeFactGeneratorAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'evidence_key'));
    }
}
