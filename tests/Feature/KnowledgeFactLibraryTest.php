<?php

namespace Tests\Feature;

use App\Jobs\GenerateKnowledgeFactBatchJob;
use App\Jobs\ReconcileArticleAiQualityJob;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeFact;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\KnowledgeFactLibrary;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactAiGenerator;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactEditor;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactEvidenceReconciler;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactGenerationCoordinator;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactPublisher;
use Illuminate\Bus\PendingBatch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class KnowledgeFactLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_fact_workbench_is_paginated_and_scoped_to_parent_knowledge_base(): void
    {
        $admin = Admin::query()->create(['username' => 'workbench', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $base = KnowledgeBase::query()->create(['name' => 'Workbench']);
        $other = KnowledgeBase::query()->create(['name' => 'Other']);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $otherLibrary = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $other->id]);
        foreach (range(1, 26) as $index) {
            $library->facts()->create(['stable_key' => 'workbench.fact.'.$index, 'label' => '指标 '.$index, 'subject' => 'GEOFlow', 'predicate' => '值为', 'value_type' => 'string']);
        }
        $otherLibrary->facts()->create(['stable_key' => 'other.secret', 'label' => '其他库秘密指标', 'subject' => '其他', 'predicate' => '值为', 'value_type' => 'string']);

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version])->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.facts.index', $base->id))
            ->assertOk()
            ->assertSee('原子事实工作台')
            ->assertSee('指标 1')
            ->assertDontSee('其他库秘密指标')
            ->assertSee('page=2', false);
    }

    public function test_generation_status_exposes_operable_progress_fields(): void
    {
        $admin = Admin::query()->create(['username' => 'progress-fields', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $base = KnowledgeBase::query()->create(['name' => 'Progress fields']);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $run = KnowledgeFactGenerationRun::query()->create([
            'library_id' => $library->id, 'mode' => 'supplement', 'target_count' => 20, 'source_hash' => str_repeat('a', 64),
            'base_working_version' => 1, 'status' => 'running', 'request_key' => (string) Str::uuid(), 'active_key' => 'active',
            'result_json' => ['candidates' => [], 'conflicts' => []], 'started_at' => now()->subSeconds(4),
        ]);

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version])->actingAs($admin, 'admin')
            ->getJson(route('admin.knowledge-bases.fact-generation.show', [$base->id, $run->id]))
            ->assertOk()
            ->assertJsonPath('data.run.mode', 'supplement')
            ->assertJsonPath('data.run.target_count', 20)
            ->assertJsonPath('data.run.batch.completed', 0)
            ->assertJsonStructure(['data' => ['run' => ['cancel_url', 'elapsed_seconds', 'actionable_error', 'batch']]]);
    }

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
        $chunk = KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id, 'chunk_index' => 0,
            'content' => '截至 2026 年，累计拥有 128 件专利。', 'content_hash' => str_repeat('b', 64), 'source_hash' => str_repeat('a', 64),
        ]);
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
            'knowledge_chunk_id' => $chunk->id,
            'source_hash' => str_repeat('a', 64),
            'content_hash' => str_repeat('b', 64),
            'excerpt' => '截至 2026 年，累计拥有 128 件专利。',
            'excerpt_hash' => hash('sha256', '截至 2026 年，累计拥有 128 件专利。'),
            'is_primary' => true,
        ]);
        $fact->values()->create([
            'canonical_value_json' => ['value' => '99', 'unit' => '件'], 'canonical_answer' => '已归档旧值',
            'scope_hash' => hash('sha256', '[]'), 'review_status' => 'rejected',
        ]);

        $revision = app(KnowledgeFactPublisher::class)->publish($library, $admin);

        $this->assertSame(1, $revision->version);
        $this->assertSame($revision->id, $library->fresh()->active_revision_id);
        $this->assertSame('ready', $library->fresh()->serving_status);
        $this->assertSame('128', data_get($revision->manifest_json, 'facts.0.values.0.canonical_value.value'));

        $second = app(KnowledgeFactPublisher::class)->publish($library->fresh(), $admin);
        $this->assertSame(1, $second->version);
        $this->assertSame($revision->id, $second->id);
        $this->assertSame(1, $library->revisions()->count());
        $this->assertSame($revision->library_hash, $second->library_hash);
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
        config()->set('ai-workspace.require_verified_model', false);
        Bus::fake();
        $admin = Admin::query()->create(['username' => 'generator', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $model = AiModel::query()->create(['name' => 'Facts', 'model_id' => 'facts-model', 'model_type' => 'chat', 'status' => 'active']);
        $model->forceFill([
            'owner_admin_id' => $admin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
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

    public function test_generation_job_can_be_added_to_a_laravel_batch(): void
    {
        $job = new GenerateKnowledgeFactBatchJob(1, 1, str_repeat('a', 64), []);

        $batch = Bus::batch([$job]);

        $this->assertCount(1, $batch->jobs);
    }

    public function test_generation_status_exposes_safe_modal_payload_and_detail_renders_workbench_entry(): void
    {
        $admin = Admin::query()->create(['username' => 'progress-dialog', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'Progress', 'chunk_sync_status' => 'ready', 'chunk_source_hash' => str_repeat('a', 64)]);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $knowledgeBase->id]);
        $run = KnowledgeFactGenerationRun::query()->create([
            'library_id' => $library->id, 'mode' => 'initial', 'target_count' => 10, 'source_hash' => str_repeat('a', 64),
            'base_working_version' => 1, 'status' => 'running', 'request_key' => (string) Str::uuid(), 'active_key' => 'active',
            'result_json' => ['candidates' => [['stable_key' => 'must.not.leak']], 'conflicts' => []],
            'error_message' => 'internal exception details must not leak',
        ]);
        $session = [Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version];

        $this->withSession($session)->actingAs($admin, 'admin')
            ->getJson(route('admin.knowledge-bases.fact-generation.show', [$knowledgeBase->id, $run->id]))
            ->assertOk()
            ->assertJsonPath('data.run.id', $run->id)
            ->assertJsonPath('data.run.status', 'running')
            ->assertJsonPath('data.run.active', true)
            ->assertJsonPath('data.run.candidate_count', 1)
            ->assertJsonPath('data.run.progress_percent', 15)
            ->assertJsonMissingPath('data.run.result_json')
            ->assertJsonMissingPath('data.run.error_message');

        $this->withSession($session)->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.detail', $knowledgeBase->id))
            ->assertOk()
            ->assertSee('进入原子事实工作台')
            ->assertSee(route('admin.knowledge-bases.facts.index', $knowledgeBase->id), false)
            ->assertDontSee('data-atomic-fact-generation-dialog', false);
    }

    public function test_generation_cancel_is_immediately_terminal_and_releases_active_key(): void
    {
        Bus::fake();
        config()->set('ai-workspace.require_verified_model', false);
        $admin = Admin::query()->create(['username' => 'cancel', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $model = AiModel::query()->create(['name' => 'Facts', 'model_id' => 'facts-model', 'model_type' => 'chat', 'status' => 'active']);
        $model->forceFill([
            'owner_admin_id' => $admin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $base = KnowledgeBase::query()->create(['name' => 'Cancel', 'chunk_sync_status' => 'ready', 'chunk_source_hash' => str_repeat('a', 64)]);
        KnowledgeChunk::query()->create(['knowledge_base_id' => $base->id, 'chunk_index' => 0, 'content' => '有效证据', 'content_hash' => str_repeat('b', 64), 'source_hash' => str_repeat('c', 64)]);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $coordinator = app(KnowledgeFactGenerationCoordinator::class);
        $run = $coordinator->start($library, $model, $admin, 'initial', 10);

        $coordinator->cancel($run);

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertNull($run->fresh()->active_key);
        $this->assertSame('idle', $library->fresh()->workflow_status);
        $next = $coordinator->start($library->fresh(), $model, $admin, 'initial', 10);
        $this->assertSame('running', $next->fresh()->status);
    }

    public function test_completed_generation_batch_is_not_sent_to_ai_again(): void
    {
        $generator = Mockery::mock(KnowledgeFactAiGenerator::class);
        $generator->shouldNotReceive('generate');
        $this->app->instance(KnowledgeFactAiGenerator::class, $generator);
        $base = KnowledgeBase::query()->create(['name' => 'Idempotent', 'chunk_sync_status' => 'ready', 'chunk_source_hash' => str_repeat('a', 64)]);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $model = AiModel::query()->create(['name' => 'Facts', 'model_id' => 'facts-model', 'model_type' => 'chat', 'status' => 'active']);
        $hash = str_repeat('d', 64);
        $run = KnowledgeFactGenerationRun::query()->create([
            'library_id' => $library->id, 'mode' => 'initial', 'target_count' => 1, 'source_hash' => str_repeat('a', 64), 'base_working_version' => 1,
            'status' => 'running', 'ai_model_id' => $model->id, 'request_key' => (string) Str::uuid(), 'active_key' => 'active',
            'result_json' => ['candidates' => [], 'conflicts' => [], 'batches' => ['1' => ['input_hash' => $hash, 'status' => 'completed']]],
        ]);

        app(KnowledgeFactGenerationCoordinator::class)->processBatch($run->id, 1, $hash, []);
        $this->addToAssertionCount(1);
    }

    public function test_scope_hash_is_canonical_and_update_cannot_create_interval_overlap(): void
    {
        $admin = Admin::query()->create(['username' => 'interval', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $base = KnowledgeBase::query()->create(['name' => 'Intervals']);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $fact = $library->facts()->create(['stable_key' => 'company.count', 'label' => '数量', 'subject' => '公司', 'predicate' => '拥有', 'value_type' => 'integer']);
        $editor = app(KnowledgeFactEditor::class);
        $first = $editor->createValue($library, $fact, ['canonical_value_json' => ['value' => '1'], 'canonical_answer' => '一件', 'scope_json' => ['region' => 'CN', 'channel' => 'web'], 'valid_from' => '2025-01-01', 'valid_to' => '2025-12-31'], $admin);
        $second = $editor->createValue($library, $fact, ['canonical_value_json' => ['value' => '2'], 'canonical_answer' => '两件', 'scope_json' => ['channel' => 'web', 'region' => 'CN'], 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31'], $admin);
        $this->assertSame($first->scope_hash, $second->scope_hash);

        $this->expectException(ConflictHttpException::class);
        $editor->updateValue($library, $second, ['lock_version' => 1, 'valid_from' => '2025-06-01', 'valid_to' => '2025-10-01'], $admin);
    }

    public function test_numeric_value_requires_decimal_string_and_content_edit_resets_review(): void
    {
        $admin = Admin::query()->create(['username' => 'review-reset', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $base = KnowledgeBase::query()->create(['name' => 'Review reset']);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $fact = $library->facts()->create(['stable_key' => 'company.count', 'label' => '数量', 'subject' => '公司', 'predicate' => '拥有', 'value_type' => 'integer', 'review_status' => 'reviewed']);
        $editor = app(KnowledgeFactEditor::class);
        $updated = $editor->updateFact($library, $fact, ['lock_version' => 1, 'label' => '新数量'], $admin);
        $this->assertSame('draft', $updated->review_status);

        $this->expectException(ValidationException::class);
        $editor->createValue($library, $updated, ['canonical_value_json' => ['value' => 128], 'canonical_answer' => '128 件'], $admin);
    }

    public function test_publisher_rejects_unlinked_or_stale_evidence(): void
    {
        $admin = Admin::query()->create(['username' => 'stale-publisher', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $base = KnowledgeBase::query()->create(['name' => 'Stale', 'chunk_sync_status' => 'ready', 'chunk_source_hash' => str_repeat('a', 64)]);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $fact = $library->facts()->create(['stable_key' => 'company.fact', 'label' => '事实', 'subject' => '公司', 'predicate' => '为', 'value_type' => 'string', 'review_status' => 'reviewed']);
        $value = $fact->values()->create(['canonical_value_json' => ['value' => '值'], 'canonical_answer' => '答案', 'scope_hash' => hash('sha256', '[]'), 'review_status' => 'reviewed']);
        $value->evidences()->create(['source_hash' => str_repeat('b', 64), 'content_hash' => str_repeat('c', 64), 'excerpt' => '失效证据', 'excerpt_hash' => hash('sha256', '失效证据')]);

        $this->expectException(ValidationException::class);
        app(KnowledgeFactPublisher::class)->publish($library, $admin);
    }

    public function test_finalize_marks_run_obsolete_when_working_copy_changed(): void
    {
        $admin = Admin::query()->create(['username' => 'obsolete-finalizer', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $model = AiModel::query()->create(['name' => 'Obsolete facts', 'model_id' => 'obsolete-facts-model', 'model_type' => 'chat', 'status' => 'active']);
        $model->forceFill([
            'owner_admin_id' => $admin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $base = KnowledgeBase::query()->create(['name' => 'Drift', 'chunk_sync_status' => 'ready', 'chunk_source_hash' => str_repeat('a', 64)]);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id, 'working_version' => 2, 'workflow_status' => 'generating']);
        $run = KnowledgeFactGenerationRun::query()->create([
            'library_id' => $library->id, 'mode' => 'initial', 'target_count' => 1, 'source_hash' => str_repeat('a', 64),
            'base_working_version' => 1, 'status' => 'running', 'ai_model_id' => $model->id, 'created_by_admin_id' => $admin->id,
            'request_key' => (string) Str::uuid(), 'active_key' => 'active',
            'result_json' => ['candidates' => [], 'conflicts' => [], 'batches' => []],
        ]);
        $finalizerToken = (string) Str::uuid7();
        $run->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'super_admin',
            'ai_config_access_version' => 1,
            'requested_ai_model_id' => $model->id,
            'resolver_policy_version' => 1,
            'execution_attempt' => 1,
            'batch_claims_json' => [],
            'finalizer_lease_token' => $finalizerToken,
        ])->save();

        app(KnowledgeFactGenerationCoordinator::class)->finalize($run->id, 1, $finalizerToken);

        $this->assertSame('obsolete', $run->fresh()->status);
        $this->assertNull($run->fresh()->active_key);
    }

    public function test_evidence_created_from_chunk_ignores_spoofed_client_content(): void
    {
        $admin = Admin::query()->create(['username' => 'evidence', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $base = KnowledgeBase::query()->create(['name' => 'Evidence']);
        $chunk = KnowledgeChunk::query()->create(['knowledge_base_id' => $base->id, 'chunk_index' => 0, 'section_path' => '企业简介', 'content' => '可信证据正文', 'content_hash' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64)]);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $fact = $library->facts()->create(['stable_key' => 'company.name', 'label' => '名称', 'subject' => '公司', 'predicate' => '名称', 'value_type' => 'string']);
        $value = $fact->values()->create(['canonical_value_json' => ['value' => '公司'], 'canonical_answer' => '公司名称', 'scope_hash' => hash('sha256', '[]')]);

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version])->actingAs($admin, 'admin')->postJson(route('admin.knowledge-bases.fact-evidences.store', [$base->id, $value->id]), [
            'knowledge_chunk_id' => $chunk->id, 'source_hash' => str_repeat('f', 64), 'content_hash' => str_repeat('e', 64), 'excerpt' => '伪造内容', 'is_primary' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('knowledge_fact_evidences', ['value_id' => $value->id, 'source_hash' => str_repeat('b', 64), 'content_hash' => str_repeat('a', 64), 'excerpt' => '可信证据正文']);
    }

    public function test_reconciler_relinks_by_chunk_hash_and_keeps_active_revision_stale_after_source_change(): void
    {
        $base = KnowledgeBase::query()->create(['name' => 'Relink', 'chunk_source_hash' => str_repeat('a', 64)]);
        $chunk = KnowledgeChunk::query()->create(['knowledge_base_id' => $base->id, 'chunk_index' => 0, 'section_path' => '简介', 'content' => '内容', 'content_hash' => str_repeat('b', 64), 'source_hash' => str_repeat('c', 64)]);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id, 'serving_status' => 'stale']);
        $fact = $library->facts()->create(['stable_key' => 'company.fact', 'label' => '事实', 'subject' => '公司', 'predicate' => '为', 'value_type' => 'string']);
        $value = $fact->values()->create(['canonical_value_json' => ['value' => '值'], 'canonical_answer' => '答案', 'scope_hash' => hash('sha256', '[]')]);
        $evidence = $value->evidences()->create(['source_hash' => str_repeat('d', 64), 'content_hash' => str_repeat('b', 64), 'source_locator_json' => ['section_path' => '简介'], 'excerpt' => '内容', 'excerpt_hash' => hash('sha256', '内容')]);

        app(KnowledgeFactEvidenceReconciler::class)->reconcile($base->id, str_repeat('a', 64));

        $this->assertSame($chunk->id, $evidence->fresh()->knowledge_chunk_id);
        $this->assertSame(str_repeat('c', 64), $evidence->fresh()->source_hash);
        $this->assertSame('unavailable', $library->fresh()->serving_status);
    }

    public function test_reconciler_requeues_atomic_quality_when_a_stale_library_becomes_ready(): void
    {
        Queue::fake();
        $servingSourceHash = str_repeat('a', 64);
        $chunkContentHash = str_repeat('b', 64);
        $chunkSourceHash = str_repeat('c', 64);
        $base = KnowledgeBase::query()->create([
            'name' => 'Recovered atomic evidence',
            'content' => '公司成立于 2020 年。',
            'chunk_sync_status' => 'ready',
            'chunk_source_hash' => $servingSourceHash,
            'chunk_serving_generation' => 'generation-ready',
            'chunk_serving_source_hash' => $servingSourceHash,
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $base->id,
            'chunk_index' => 0,
            'content' => '公司成立于 2020 年。',
            'content_hash' => $chunkContentHash,
            'source_hash' => $chunkSourceHash,
            'generation_key' => 'generation-ready',
        ]);
        $library = KnowledgeFactLibrary::query()->create([
            'knowledge_base_id' => $base->id,
            'serving_status' => 'stale',
            'source_hash' => $servingSourceHash,
        ]);
        $revision = $library->revisions()->create([
            'version' => 1,
            'library_hash' => hash('sha256', 'recovered-facts'),
            'source_hash' => $servingSourceHash,
            'published_at' => now(),
            'manifest_json' => ['facts' => [[
                'values' => [[
                    'evidence' => [[
                        'source_hash' => $chunkSourceHash,
                        'content_hash' => $chunkContentHash,
                    ]],
                ]],
            ]]],
        ]);
        $library->forceFill([
            'active_revision_id' => $revision->id,
            'active_hash' => $revision->library_hash,
            'active_fact_count' => 1,
        ])->save();
        $category = Category::query()->create(['name' => '原子恢复分类', 'slug' => 'atomic-recovery-category']);
        $author = Author::query()->create(['name' => '原子恢复作者']);
        $article = Article::query()->create([
            'title' => '等待原子事实恢复的文章',
            'slug' => 'waiting-atomic-evidence-recovery',
            'content' => '公司成立于 2020 年。',
            'status' => 'draft',
            'review_status' => 'pending',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'ai_quality_required_at_creation' => true,
            'ai_quality_retrieval_mode_override' => 'atomic_first',
        ]);
        $article->aiQualityKnowledgeBases()->sync([$base->id => ['sort_order' => 0]]);

        app(KnowledgeFactEvidenceReconciler::class)->reconcile($base->id, $servingSourceHash);

        $this->assertSame('ready', $library->fresh()->serving_status);
        Queue::assertPushed(
            ReconcileArticleAiQualityJob::class,
            static fn (ReconcileArticleAiQualityJob $job): bool => in_array($article->id, $job->articleIds, true),
        );
    }

    public function test_generation_diagnostic_prune_dry_run_is_non_mutating_and_real_run_rehashes_summary(): void
    {
        $base = KnowledgeBase::query()->create(['name' => 'Prune']);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $base->id]);
        $run = KnowledgeFactGenerationRun::query()->create([
            'library_id' => $library->id, 'mode' => 'initial', 'target_count' => 1, 'source_hash' => str_repeat('a', 64),
            'base_working_version' => 1, 'status' => 'completed', 'request_key' => (string) Str::uuid(),
            'result_json' => ['candidates' => [['stable_key' => 'company.fact']], 'conflicts' => [], 'batches' => ['1' => ['status' => 'completed']]],
        ]);
        DB::table('knowledge_fact_generation_runs')->where('id', $run->id)->update(['updated_at' => now()->subDays(100), 'created_at' => now()->subDays(100)]);

        $this->artisan('geoflow:prune-knowledge-fact-generations', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain('Eligible knowledge fact generation diagnostics: 1');
        $this->assertNull($run->fresh()->diagnostic_payload_pruned_at);

        $this->artisan('geoflow:prune-knowledge-fact-generations')->assertSuccessful()->expectsOutputToContain('Pruned knowledge fact generation diagnostics: 1');
        $summary = (array) $run->fresh()->result_json;
        $this->assertSame(hash('sha256', json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), $run->fresh()->result_hash);
    }

    public function test_generation_finalize_appends_review_candidates_with_scoped_evidence(): void
    {
        $admin = Admin::query()->create(['username' => 'finalizer', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $model = AiModel::query()->create(['name' => 'Finalize facts', 'model_id' => 'finalize-facts-model', 'model_type' => 'chat', 'status' => 'active']);
        $model->forceFill([
            'owner_admin_id' => $admin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'Finalize', 'chunk_sync_status' => 'ready', 'chunk_source_hash' => str_repeat('f', 64)]);
        $chunk = KnowledgeChunk::query()->create(['knowledge_base_id' => $knowledgeBase->id, 'chunk_index' => 0, 'content' => '公司成立于 2020 年。', 'content_hash' => str_repeat('1', 64), 'source_hash' => str_repeat('f', 64)]);
        $library = KnowledgeFactLibrary::query()->create(['knowledge_base_id' => $knowledgeBase->id, 'workflow_status' => 'generating']);
        $run = KnowledgeFactGenerationRun::query()->create([
            'library_id' => $library->id, 'mode' => 'initial', 'target_count' => 1, 'source_hash' => str_repeat('f', 64),
            'base_working_version' => 1, 'status' => 'running', 'ai_model_id' => $model->id,
            'created_by_admin_id' => $admin->id, 'request_key' => (string) Str::uuid(),
            'active_key' => 'knowledge-fact-library:'.$library->id, 'result_json' => ['candidates' => [[
                'stable_key' => 'company.founded_at', 'label' => '成立时间', 'subject' => '公司', 'predicate' => '成立于', 'value_type' => 'date',
                'canonical_value' => '2020', 'canonical_answer' => '公司成立于 2020 年。', 'unit' => '年', 'evidence_keys' => ['chunk:'.$chunk->id.':'.substr($chunk->content_hash, 0, 12)],
            ]], 'conflicts' => [], 'batches' => ['1' => ['status' => 'completed']]],
        ]);
        $finalizerToken = (string) Str::uuid7();
        $run->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'super_admin',
            'ai_config_access_version' => 1,
            'requested_ai_model_id' => $model->id,
            'resolved_ai_model_id' => $model->id,
            'resolved_model_source' => 'personal',
            'resolver_policy_version' => 1,
            'execution_attempt' => 1,
            'batch_claims_json' => ['1' => [
                'status' => 'completed',
                'resolved_ai_model_id' => $model->id,
                'resolved_model_source' => 'personal',
            ]],
            'finalizer_lease_token' => $finalizerToken,
        ])->save();

        app(KnowledgeFactGenerationCoordinator::class)->finalize($run->id, 1, $finalizerToken);

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
