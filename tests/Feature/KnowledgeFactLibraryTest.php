<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\AiModel;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeFact;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\KnowledgeFactLibrary;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactEditor;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactGenerationCoordinator;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactPublisher;
use Illuminate\Bus\PendingBatch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeFactLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_atomic_fact_schema_and_relationships_are_available(): void
    {
        foreach ([
            'knowledge_fact_libraries',
            'knowledge_facts',
            'knowledge_fact_values',
            'knowledge_fact_evidences',
            'knowledge_fact_library_revisions',
            'knowledge_fact_generation_runs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' should exist');
        }

        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'Acme']);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $knowledgeBase->id]);
        $fact = $library->facts()->create([
            'stable_key' => 'company.founded_at',
            'label' => '成立时间',
            'subject' => 'Acme',
            'predicate' => '成立于',
            'value_type' => 'date',
        ]);

        $this->assertTrue($knowledgeBase->factLibrary()->is($library));
        $this->assertTrue($fact->library()->is($library));
    }

    public function test_reviewed_fact_can_be_published_to_an_immutable_revision(): void
    {
        $admin = Admin::query()->create([
            'username' => 'fact-admin',
            'password' => 'password',
            'role' => 'super_admin',
            'status' => 1,
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Acme',
            'chunk_sync_status' => 'ready',
            'chunk_source_hash' => str_repeat('a', 64),
        ]);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $knowledgeBase->id]);
        $fact = KnowledgeFact::query()->create([
            'library_id' => $library->id,
            'stable_key' => 'company.patent_count',
            'label' => '专利数量',
            'subject' => 'Acme',
            'predicate' => '拥有专利',
            'value_type' => 'integer',
            'review_status' => 'reviewed',
        ]);
        $value = $fact->values()->create([
            'canonical_value_json' => ['value' => '128', 'unit' => '件'],
            'canonical_answer' => 'Acme 拥有 128 件专利。',
            'scope_hash' => hash('sha256', '{}'),
            'review_status' => 'reviewed',
        ]);
        $value->evidences()->create([
            'source_hash' => str_repeat('a', 64),
            'content_hash' => str_repeat('b', 64),
            'excerpt' => '截至 2026 年，累计拥有 128 件专利。',
            'excerpt_hash' => str_repeat('c', 64),
            'is_primary' => true,
        ]);

        $revision = app(KnowledgeFactPublisher::class)->publish($library, $admin);

        $this->assertSame(1, $revision->version);
        $this->assertSame($revision->id, $library->fresh()->active_revision_id);
        $this->assertSame('ready', $library->fresh()->serving_status);
        $this->assertSame('128', data_get($revision->manifest_json, 'facts.0.values.0.canonical_value.value'));
    }

    public function test_admin_can_create_fact_and_stale_lock_version_returns_conflict_without_audit_body_leak(): void
    {
        if (! Schema::hasTable('admin_activity_logs')) {
            Schema::create('admin_activity_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('admin_id')->nullable();
                $table->string('admin_username')->default('');
                $table->string('admin_role')->default('');
                $table->string('action');
                $table->string('request_method');
                $table->string('page')->default('');
                $table->string('target_type')->default('');
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('ip_address')->default('');
                $table->text('details')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
        $admin = Admin::query()->create(['username' => 'editor', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'Scoped']);
        $response = $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version])->actingAs($admin, 'admin')->postJson(route('admin.knowledge-bases.facts.store', $knowledgeBase->id), [
            'stable_key' => 'company.secret_metric', 'label' => '秘密指标', 'subject' => 'Scoped', 'predicate' => '指标为', 'value_type' => 'integer',
            'canonical_answer' => '审计日志不能记录这段标准答案', 'evidence_excerpt' => '审计日志不能记录这段证据摘录',
        ])->assertSuccessful();
        $factId = $response->json('data.fact.id');

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version])->actingAs($admin, 'admin')->putJson(route('admin.knowledge-bases.facts.update', [$knowledgeBase->id, $factId]), [
            'lock_version' => 99, 'label' => '过期更新',
        ])->assertStatus(409)->assertSee('knowledge_fact_revision_conflict');

        $details = AdminActivityLog::query()->latest('id')->value('details');
        $this->assertStringNotContainsString('标准答案', (string) $details);
        $this->assertStringNotContainsString('证据摘录', (string) $details);
    }

    public function test_generation_start_creates_one_active_run_and_batches_at_most_eight_jobs(): void
    {
        Bus::fake();
        $admin = Admin::query()->create(['username' => 'generator', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $model = AiModel::query()->create(['name' => 'Facts', 'model_id' => 'facts-model', 'model_type' => 'chat', 'status' => 'active']);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'Generate', 'chunk_sync_status' => 'ready', 'chunk_source_hash' => str_repeat('d', 64)]);
        KnowledgeChunk::query()->create(['knowledge_base_id' => $knowledgeBase->id, 'chunk_index' => 0, 'content' => '公司成立于 2020 年。', 'content_hash' => str_repeat('e', 64), 'source_hash' => str_repeat('d', 64)]);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $knowledgeBase->id]);

        $run = app(KnowledgeFactGenerationCoordinator::class)->start($library, $model, $admin, 'initial', 200);

        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame('knowledge-fact-library:'.$library->id, $run->fresh()->active_key);
        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() > 0 && $batch->jobs->count() <= 8 && $batch->allowsFailures());
        $this->expectException(\RuntimeException::class);
        app(KnowledgeFactGenerationCoordinator::class)->start($library, $model, $admin, 'supplement', 10);
    }

    public function test_generation_finalize_appends_review_candidates_with_scoped_evidence(): void
    {
        $admin = Admin::query()->create(['username' => 'finalizer', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'Finalize', 'chunk_sync_status' => 'ready', 'chunk_source_hash' => str_repeat('f', 64)]);
        $chunk = KnowledgeChunk::query()->create(['knowledge_base_id' => $knowledgeBase->id, 'chunk_index' => 0, 'content' => '公司成立于 2020 年。', 'content_hash' => str_repeat('1', 64), 'source_hash' => str_repeat('f', 64)]);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $knowledgeBase->id, 'workflow_status' => 'generating']);
        $run = KnowledgeFactGenerationRun::query()->create([
            'library_id' => $library->id, 'mode' => 'initial', 'target_count' => 1, 'source_hash' => str_repeat('f', 64),
            'base_working_version' => 1, 'status' => 'running', 'created_by_admin_id' => $admin->id, 'request_key' => (string) Str::uuid(),
            'active_key' => 'knowledge-fact-library:'.$library->id, 'result_json' => ['candidates' => [[
                'stable_key' => 'company.founded_at', 'label' => '成立时间', 'subject' => '公司', 'predicate' => '成立于', 'value_type' => 'date',
                'canonical_value' => '2020', 'canonical_answer' => '公司成立于 2020 年。', 'unit' => '年', 'evidence_keys' => ['chunk:'.$chunk->id.':'.substr($chunk->content_hash, 0, 12)],
            ]], 'conflicts' => [], 'batches' => ['1' => ['status' => 'completed']]],
        ]);

        app(KnowledgeFactGenerationCoordinator::class)->finalize($run->id);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('review_required', $library->fresh()->workflow_status);
        $this->assertDatabaseHas('knowledge_fact_evidences', ['knowledge_chunk_id' => $chunk->id, 'source_hash' => str_repeat('f', 64)]);

        $beforeVersion = $library->fresh()->working_version;
        app(KnowledgeFactEditor::class)->resolveGeneratedCandidate($library->fresh(), [
            'stable_key' => 'company.founded_at', 'label' => '成立年份', 'subject' => '公司', 'predicate' => '成立于', 'value_type' => 'date',
            'canonical_value' => '2021', 'canonical_answer' => '另一主体成立于 2021 年。', 'unit' => '年', 'evidence_keys' => ['chunk:'.$chunk->id.':'.substr($chunk->content_hash, 0, 12)],
        ], 'create_with_new_key', 'company.alternative_founded_at', $admin, $run->id);

        $this->assertDatabaseHas('knowledge_facts', ['library_id' => $library->id, 'stable_key' => 'company.alternative_founded_at']);
        $this->assertSame($beforeVersion + 1, $library->fresh()->working_version);
    }
}
