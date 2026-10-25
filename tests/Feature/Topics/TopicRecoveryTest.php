<?php

namespace Tests\Feature\Topics;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Jobs\ProcessTopicBuildJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Topic;
use App\Models\TopicBuildRun;
use App\Services\Topics\TopicBatchService;
use App\Services\Topics\TopicGenerationService;
use App\Services\Topics\TopicService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class TopicRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_run_reuses_persisted_output_without_another_model_call(): void
    {
        Queue::fake();
        MarkdownContentWriterAgent::fake([])->preventStrayPrompts();
        [$actor, $model, $sources] = $this->setupSources();
        $topic = app(TopicService::class)->create('primary', ['title' => 'GEO']);
        $generation = app(TopicGenerationService::class);
        $run = $generation->prepare($actor, 'primary', ['title' => 'GEO', 'model_id' => $model->id, 'after' => 'draft_only'], (string) Str::uuid(), $topic);
        $result = $this->aiResult($sources);
        $run->update(['status' => 'running', 'phase' => 'saving', 'lease_token' => 'old-worker', 'lease_expires_at' => now()->subSecond(), 'result' => $result]);
        $this->actingAs($actor, 'admin')->getJson(route('admin.topics.runs.status', ['run' => $run->id]))->assertOk()->assertJsonPath('lease_expired', true)->assertJsonPath('can_retry', true);
        $this->get(route('admin.topics.runs.show', ['run' => $run->id]))->assertOk()->assertSee('恢复中断执行');
        $this->post(route('admin.topics.runs.action', ['run' => $run->id, 'action' => 'retry']))->assertRedirect();
        $this->assertNull($run->fresh()->lease_token);
        $this->assertSame($result, $run->fresh()->result);
        Queue::assertPushed(ProcessTopicBuildJob::class, 1);
        $done = $generation->process($run->id);
        $this->assertSame('completed', $done->status);
        $this->assertSame('Recovered guide', $topic->fresh()->draft_payload['intro']);
        $this->assertNull($topic->fresh()->public_revision_id);
    }

    public function test_a_live_lease_cannot_be_retried_and_changed_sources_discard_saved_output(): void
    {
        Queue::fake();
        [$actor, $model, $sources] = $this->setupSources();
        $generation = app(TopicGenerationService::class);
        $run = $generation->prepare($actor, 'primary', ['title' => 'GEO', 'model_id' => $model->id], (string) Str::uuid());
        $run->update(['status' => 'running', 'phase' => 'saving', 'lease_token' => 'live-worker', 'lease_expires_at' => now()->addMinute(), 'result' => $this->aiResult($sources)]);
        $generation->retry($run);
        $this->assertSame('live-worker', $run->fresh()->lease_token);
        Queue::assertNothingPushed();
        $sources[0]->update(['content' => 'Changed source']);
        $run->update(['lease_expires_at' => now()->subSecond()]);
        $generation->retry($run);
        $this->assertSame('pending', $run->fresh()->status);
        $this->assertNull($run->fresh()->result);
        $this->assertSame('waiting', $run->fresh()->phase);
    }

    public function test_old_worker_cannot_store_output_or_create_a_topic_after_expired_lease_recovery(): void
    {
        Queue::fake();
        [$actor, $model, $sources] = $this->setupSources();
        $generation = app(TopicGenerationService::class);
        $run = $generation->prepare($actor, 'primary', ['title' => 'GEO', 'model_id' => $model->id, 'after' => 'draft_only'], (string) Str::uuid());
        MarkdownContentWriterAgent::fake(function (string $prompt) use ($run, $generation, $sources): string {
            if (str_contains($prompt, '"phase":"verify"')) {
                $run->refresh()->update(['lease_expires_at' => now()->subSecond()]);
                $generation->retry($run->fresh());

                return json_encode(['supported' => true, 'unsupported_fields' => []]);
            }

            return json_encode($this->aiResult($sources));
        })->preventStrayPrompts();
        $old = $generation->process($run->id);
        $this->assertSame('pending', $old->status);
        $this->assertNull($old->result);
        $this->assertNull($old->lease_token);
        $this->assertSame(0, Topic::query()->count());
        MarkdownContentWriterAgent::fake([json_encode($this->aiResult($sources)), json_encode(['supported' => true, 'unsupported_fields' => []])])->preventStrayPrompts();
        $new = $generation->process($run->id);
        $this->assertSame('completed', $new->status);
        $this->assertSame(1, Topic::query()->count());
    }

    public function test_expired_batch_row_keeps_settings_and_reconciles_saved_run_result(): void
    {
        Queue::fake();
        MarkdownContentWriterAgent::fake([])->preventStrayPrompts();
        [$actor, $model, $sources] = $this->setupSources();
        $service = app(TopicBatchService::class);
        $settings = ['mode' => 'ai', 'model_id' => $model->id, 'after' => 'draft_only', 'template_key' => 'guide', 'rules' => 'Original instructions'];
        $batch = $service->create($actor, 'primary', ['GEO'], $settings, (string) Str::uuid());
        $topic = app(TopicService::class)->create('primary', ['title' => 'GEO']);
        $run = app(TopicGenerationService::class)->prepare($actor, 'primary', $settings + ['title' => 'GEO', 'batch_id' => $batch->id, 'row_number' => 1, 'batch_generation' => 1], 'batch:'.$batch->id.':row:0', $topic);
        $run->update(['status' => 'running', 'phase' => 'saving', 'lease_token' => 'old-batch-worker', 'lease_expires_at' => now()->subSecond(), 'result' => $this->aiResult($sources)]);
        $rows = $batch->rows;
        $rows[0] = array_replace($rows[0], ['status' => 'running', 'run_id' => $run->id, 'claimed_at' => now()->subMinutes(8)->toIso8601String()]);
        $batch->update(['status' => 'running', 'rows' => $rows]);
        $this->actingAs($actor, 'admin')->getJson(route('admin.topics.batches.status', ['batch' => $batch->id]))->assertOk()->assertJsonPath('can_retry', true)->assertJsonPath('rows.0.lease_expired', true);
        $this->get(route('admin.topics.batches.show', ['batch' => $batch->id]))->assertOk()->assertSee('恢复此项');
        $this->post(route('admin.topics.batches.action', ['batch' => $batch->id, 'action' => 'retry']), ['row_numbers' => [1]])->assertRedirect();
        $this->assertSame(2, $batch->fresh()->generation);
        $this->assertSame($settings, $batch->fresh()->settings);
        $this->assertNull($run->fresh()->lease_token);
        $service->processNext($batch->id, 2);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('completed', $batch->fresh()->rows[0]['status']);
        $this->assertSame($topic->id, $batch->fresh()->rows[0]['topic_id']);
        $this->assertSame(2, $run->fresh()->input['batch_generation']);
    }

    public function test_batch_recovery_invalidates_old_worker_before_its_final_result_commit(): void
    {
        Queue::fake();
        [$actor, $model, $sources] = $this->setupSources();
        $service = app(TopicBatchService::class);
        $batch = $service->create($actor, 'primary', ['GEO'], ['mode' => 'ai', 'model_id' => $model->id, 'after' => 'draft_only'], (string) Str::uuid());
        MarkdownContentWriterAgent::fake(function (string $prompt) use ($batch, $service, $sources): string {
            if (str_contains($prompt, '"phase":"verify"')) {
                $run = TopicBuildRun::query()->where('batch_id', $batch->id)->sole();
                $run->update(['lease_expires_at' => now()->subSecond()]);
                $service->retry($batch->fresh());

                return json_encode(['supported' => true, 'unsupported_fields' => []]);
            }

            return json_encode($this->aiResult($sources));
        })->preventStrayPrompts();
        $service->processNext($batch->id, 1);
        $this->assertSame(2, $batch->fresh()->generation);
        $this->assertSame('pending', $batch->fresh()->rows[0]['status']);
        $this->assertNull(TopicBuildRun::query()->where('batch_id', $batch->id)->sole()->result);
        $this->assertSame(0, Topic::query()->count());
    }

    public function test_batch_stale_claim_without_a_run_is_recoverable_but_live_claim_is_protected(): void
    {
        Queue::fake();
        [$actor, $model] = $this->setupSources();
        $service = app(TopicBatchService::class);
        $batch = $service->create($actor, 'primary', ['GEO'], ['mode' => 'draft', 'template_key' => 'default'], (string) Str::uuid());
        $rows = $batch->rows;
        $rows[0]['status'] = 'running';
        $rows[0]['claimed_at'] = now()->toIso8601String();
        $batch->update(['status' => 'running', 'rows' => $rows]);
        $this->actingAs($actor, 'admin')->post(route('admin.topics.batches.action', ['batch' => $batch->id, 'action' => 'retry']))->assertStatus(409);
        $rows[0]['claimed_at'] = now()->subMinutes(8)->toIso8601String();
        $batch->update(['rows' => $rows]);
        $this->post(route('admin.topics.batches.action', ['batch' => $batch->id, 'action' => 'retry']))->assertRedirect();
        $service->processNext($batch->id, 2);
        $this->assertSame('completed', $batch->fresh()->rows[0]['status']);
        $this->assertSame(1, Topic::query()->count());
    }

    public function test_selected_failed_retry_is_not_blocked_by_an_unselected_expired_row(): void
    {
        Queue::fake();
        MarkdownContentWriterAgent::fake([])->preventStrayPrompts();
        [$actor, $model, $sources] = $this->setupSources();
        $service = app(TopicBatchService::class);
        $settings = ['mode' => 'ai', 'model_id' => $model->id, 'after' => 'draft_only'];
        $batch = $service->create($actor, 'primary', ['GEO', 'Unrelated'], $settings, (string) Str::uuid());
        $run = app(TopicGenerationService::class)->prepare($actor, 'primary', $settings + ['title' => 'GEO', 'batch_id' => $batch->id, 'row_number' => 1, 'batch_generation' => 1], 'batch:'.$batch->id.':row:0');
        $run->update(['status' => 'running', 'phase' => 'saving', 'lease_token' => 'expired-worker', 'lease_expires_at' => now()->subSecond(), 'result' => $this->aiResult($sources)]);
        $rows = $batch->rows;
        $rows[0] = array_replace($rows[0], ['status' => 'running', 'run_id' => $run->id]);
        $rows[1]['status'] = 'failed';
        $batch->update(['status' => 'running', 'rows' => $rows]);
        $service->retry($batch, false, [2]);
        $this->assertSame('failed', $batch->fresh()->rows[0]['status']);
        $this->assertSame('pending', $batch->fresh()->rows[1]['status']);
        $this->assertSame($this->aiResult($sources), $run->fresh()->result);
        $this->assertNull($run->fresh()->lease_token);
        $service->processNext($batch->id, 2);
        $this->assertSame('waiting_content', $batch->fresh()->rows[1]['status']);
        $this->assertNotNull($batch->fresh()->rows[1]['run_id']);
        $this->assertSame($settings, $batch->fresh()->settings);
    }

    public function test_seo_override_and_evidence_shape_preserve_legacy_facts_and_utf8_offsets(): void
    {
        [$actor, $model, $sources] = $this->setupSources();
        $evidence = ['article_id' => $sources[0]->id, 'field' => 'content', 'start' => 0, 'end' => 2, 'sha256' => hash('sha256', '原文'), 'text' => '原文'];
        $payload = ['title' => 'GEO', 'intro' => 'Guide', 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources), 'seo' => ['title' => 'Search title', 'description' => 'Search description'], 'summary' => ['facts' => [['text' => 'A fact', 'article_ids' => [$sources[0]->id], 'evidence' => [$evidence]], ['text' => 'Legacy fact', 'article_ids' => [$sources[1]->id]]]]];
        $topic = app(TopicService::class)->create('primary', $payload);
        $this->assertSame($evidence, $topic->draft_payload['summary']['facts'][0]['evidence'][0]);
        $this->assertSame('Search title', $topic->draft_payload['seo']['title']);
        $this->actingAs($actor, 'admin')->put(route('admin.topics.update', ['topic' => $topic->id]), ['site' => 'primary', 'expected_version' => 1, 'template_key' => 'default'] + $payload)->assertRedirect();
        $this->assertSame('Legacy fact', $topic->fresh()->draft_payload['summary']['facts'][1]['text']);
        $payload['summary']['facts'][0]['evidence'][0]['end'] = 0;
        $this->put(route('admin.topics.update', ['topic' => $topic->id]), ['site' => 'primary', 'expected_version' => 2, 'template_key' => 'default'] + $payload)->assertSessionHasErrors('summary.facts.0.evidence.0.end');
        $payload['summary']['facts'][0]['evidence'][0]['end'] = 2;
        $payload['seo']['description'] = str_repeat('x', 501);
        $this->put(route('admin.topics.update', ['topic' => $topic->id]), ['site' => 'primary', 'expected_version' => 2, 'template_key' => 'default'] + $payload)->assertSessionHasErrors('seo.description');
    }

    private function setupSources(): array
    {
        $actor = Admin::query()->firstOrCreate(['username' => 'recovery_admin'], ['password' => 'password', 'email' => 'recovery@test.local', 'role' => 'super_admin', 'status' => 'active']);
        $model = AiModel::query()->create(['name' => 'Recovery model', 'version' => 'test', 'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'), 'model_id' => 'test-model', 'model_type' => 'chat', 'api_url' => 'https://ai.test', 'daily_limit' => 100, 'status' => 'active']);
        $model->forceFill(['owner_admin_id' => $actor->id, 'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT])->save();
        $category = Category::query()->create(['name' => 'Recovery', 'slug' => 'recovery']);
        $author = Author::query()->create(['email' => 'recovery@test.local', 'name' => 'Editor']);
        $sources = [];
        foreach (['one', 'two'] as $name) {
            $sources[] = Article::query()->create(['title' => 'GEO '.$name, 'slug' => 'geo-'.$name, 'content' => '原文 GEO facts '.$name, 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now()]);
        }

        return [$actor, $model, $sources];
    }

    private function aiResult(array $sources): array
    {
        return ['intro' => 'Recovered guide', 'summary' => ['one_sentence' => 'A guide'], 'tags' => [], 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources), 'source_hashes' => array_column(array_map(fn ($a) => ['id' => $a->id, 'hash' => TopicService::contentHash($a)], $sources), 'hash', 'id')];
    }

    public function test_old_failure_hook_cannot_overwrite_recovered_worker(): void
    {
        Queue::fake();
        $actor = Admin::query()->create(['username' => 'lease_review', 'password' => 'password', 'email' => 'lease@test.local', 'role' => 'super_admin', 'status' => 'active']);
        $run = TopicBuildRun::query()->create(['request_key' => (string) Str::uuid(), 'site_key' => 'primary', 'dispatch_key' => 'old-dispatch', 'owner_admin_id' => $actor->id, 'identity' => [], 'input' => ['title' => 'GEO'], 'status' => 'running', 'phase' => 'generating', 'lease_token' => 'old-worker', 'lease_expires_at' => now()->subMinute()]);
        $oldJob = new ProcessTopicBuildJob($run->id, 'old-dispatch');
        app(TopicGenerationService::class)->retry($run);
        $run->refresh()->update(['status' => 'running', 'phase' => 'generating', 'lease_token' => 'replacement-worker', 'lease_expires_at' => now()->addMinutes(6)]);
        $this->assertNotSame('old-dispatch', $run->fresh()->dispatch_key);
        $oldJob->handle(app(TopicGenerationService::class));
        $oldJob->failed(new \RuntimeException('old attempt finally failed'));
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame('replacement-worker', $run->fresh()->lease_token);
    }
}
