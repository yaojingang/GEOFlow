<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ArticleAiQualityRollout;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeFactLibrary;
use App\Models\KnowledgeFactLibraryRevision;
use App\Services\GeoFlow\AiQualityRetrievalReadinessService;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AiQualityRetrievalReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_selects_the_highest_mode_available_to_every_selected_knowledge_base(): void
    {
        $sourceHash = hash('sha256', '产品价格为 980 元。');
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '产品知识库',
            'content' => '产品价格为 980 元。',
            'risk_level' => 'normal',
            'review_status' => 'approved',
            'chunk_sync_status' => 'ready',
            'chunk_source_hash' => $sourceHash,
            'chunk_serving_generation' => 'generation-1',
            'chunk_serving_source_hash' => $sourceHash,
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '产品价格为 980 元。',
            'content_hash' => hash('sha256', '产品价格为 980 元。'),
            'source_hash' => hash('sha256', '|产品价格为 980 元。'),
            'generation_key' => 'generation-1',
        ]);

        $readiness = app(AiQualityRetrievalReadinessService::class)->inspect([$knowledgeBase->id]);

        $this->assertSame(AiQualityRetrievalMode::CHUNK, $readiness['highest_available_mode']);
        $this->assertTrue($readiness['modes'][AiQualityRetrievalMode::KNOWLEDGE_BROAD]['available']);
        $this->assertTrue($readiness['modes'][AiQualityRetrievalMode::CHUNK]['available']);
        $this->assertFalse($readiness['modes'][AiQualityRetrievalMode::ATOMIC_FIRST]['available']);
    }

    public function test_atomic_mode_requires_a_ready_revision_and_full_unfrozen_rollout(): void
    {
        $sourceHash = hash('sha256', '产品价格为 980 元。');
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '产品知识库',
            'content' => '产品价格为 980 元。',
            'risk_level' => 'normal',
            'review_status' => 'approved',
            'chunk_sync_status' => 'ready',
            'chunk_source_hash' => $sourceHash,
            'chunk_serving_generation' => 'generation-1',
            'chunk_serving_source_hash' => $sourceHash,
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '产品价格为 980 元。',
            'content_hash' => hash('sha256', '产品价格为 980 元。'),
            'source_hash' => hash('sha256', '|产品价格为 980 元。'),
            'generation_key' => 'generation-1',
        ]);
        $library = KnowledgeFactLibrary::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'serving_status' => 'ready',
            'source_hash' => $sourceHash,
        ]);
        $revision = KnowledgeFactLibraryRevision::query()->create([
            'library_id' => $library->id,
            'version' => 1,
            'library_hash' => hash('sha256', 'facts-v1'),
            'source_hash' => $sourceHash,
            'manifest_json' => ['facts' => [['stable_key' => 'price']]],
            'published_at' => now(),
        ]);
        $library->forceFill([
            'active_revision_id' => $revision->id,
            'active_hash' => $revision->library_hash,
            'active_fact_count' => 1,
        ])->save();
        ArticleAiQualityRollout::query()->create([
            'id' => 1,
            'atomic_fact_percent' => 100,
            'atomic_fact_frozen' => false,
            'frozen' => false,
        ]);

        $readiness = app(AiQualityRetrievalReadinessService::class)->inspect([$knowledgeBase->id]);

        $this->assertSame(AiQualityRetrievalMode::ATOMIC_FIRST, $readiness['highest_available_mode']);
        $this->assertTrue($readiness['modes'][AiQualityRetrievalMode::ATOMIC_FIRST]['available']);
    }

    public function test_multi_knowledge_base_readiness_is_strict(): void
    {
        $ready = KnowledgeBase::query()->create([
            'name' => '已切片知识库',
            'content' => '已切片正文',
            'risk_level' => 'normal',
            'review_status' => 'approved',
            'chunk_sync_status' => 'ready',
            'chunk_source_hash' => hash('sha256', '已切片正文'),
            'chunk_serving_generation' => 'generation-1',
            'chunk_serving_source_hash' => hash('sha256', '已切片正文'),
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $ready->id,
            'chunk_index' => 0,
            'content' => '已切片正文',
            'content_hash' => hash('sha256', '已切片正文'),
            'source_hash' => hash('sha256', '|已切片正文'),
            'generation_key' => 'generation-1',
        ]);
        $rawOnly = KnowledgeBase::query()->create([
            'name' => '仅正文知识库',
            'content' => '仅正文内容',
            'risk_level' => 'normal',
            'review_status' => 'approved',
        ]);

        $readiness = app(AiQualityRetrievalReadinessService::class)->inspect([$ready->id, $rawOnly->id]);

        $this->assertSame(AiQualityRetrievalMode::KNOWLEDGE_BROAD, $readiness['highest_available_mode']);
        $this->assertFalse($readiness['modes'][AiQualityRetrievalMode::CHUNK]['available']);
        $this->assertSame($rawOnly->id, $readiness['modes'][AiQualityRetrievalMode::CHUNK]['blockers'][0]['knowledge_base_id']);
    }

    public function test_high_risk_knowledge_reviewed_through_the_admin_form_is_available_for_broad_quality(): void
    {
        Queue::fake();
        $admin = Admin::query()->create([
            'username' => 'high-risk-readiness-admin',
            'password' => 'secret-password',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '已审核高风险知识库',
                'content' => '合同金额为 980 万元。',
                'file_type' => 'markdown',
                'risk_level' => 'high',
                'review_status' => 'reviewed',
            ])
            ->assertRedirect(route('admin.knowledge-bases.index'));

        $knowledgeBase = KnowledgeBase::query()->where('name', '已审核高风险知识库')->firstOrFail();
        $readiness = app(AiQualityRetrievalReadinessService::class)->inspect([$knowledgeBase->id]);

        $this->assertTrue($readiness['modes'][AiQualityRetrievalMode::KNOWLEDGE_BROAD]['available']);
    }
}
