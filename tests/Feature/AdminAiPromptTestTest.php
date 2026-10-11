<?php

namespace Tests\Feature;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Ai\Agents\PromptDiagnosticAgent;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\Article;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\KnowledgeRetrievalService;
use App\Services\GeoFlow\PromptTestRunStore;
use App\Services\GeoFlow\PromptTestService;
use App\Support\AdminUiRegistry;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use App\Support\GeoFlow\PromptTestDiagnosticResult;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

class AdminAiPromptTestTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private Prompt $prompt;

    private AiModel $model;

    private KnowledgeBase $knowledge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Admin::query()->create(['username' => 'prompt_tester', 'password' => 'secret-123',
            'email' => 'prompt@example.test', 'role' => 'admin', 'status' => 'active']);
        $this->prompt = Prompt::query()->create(['name' => '正文提示词', 'type' => 'content', 'content' => '已保存提示词']);
        $this->knowledge = KnowledgeBase::query()->create(['name' => '产品知识库', 'content' => '产品证据',
            'usage_count' => 0, 'risk_level' => 'low', 'review_status' => 'reviewed']);
        KnowledgeChunk::query()->create(['knowledge_base_id' => $this->knowledge->id, 'chunk_index' => 0,
            'content' => 'GEO 产品已有来源资料。', 'chunk_title' => '产品资料']);
        $this->model = AiModel::query()->create(['name' => '测试模型', 'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'), 'model_id' => 'test-chat-model',
            'model_type' => 'chat', 'api_url' => 'https://ai.test', 'daily_limit' => 0, 'used_today' => 0,
            'total_used' => 0, 'status' => 'active']);
        $this->model->forceFill(['owner_admin_id' => $this->admin->id, 'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT])->save();
        $this->actingAs($this->admin, 'admin');
        MarkdownContentWriterAgent::fake(['文章正文'])->preventStrayPrompts();
        PromptDiagnosticAgent::fake([$this->diagnosticResult()])->preventStrayPrompts();
    }

    public function test_test_routes_are_endpoints_and_are_not_added_to_recent_page_history(): void
    {
        $registry = app(AdminUiRegistry::class);
        foreach (['test-options', 'test', 'tests.show', 'tests.diagnose', 'tests.cancel'] as $name) {
            $this->assertSame('endpoint', $registry->routeClassification('admin.ai-prompts.'.$name));
            $this->assertFalse($registry->shouldRememberRoute('admin.ai-prompts.'.$name));
        }
    }

    public function test_only_content_edit_page_exposes_testing_and_options_hide_secrets(): void
    {
        $this->get(route('admin.ai-prompts.edit', $this->prompt->id))->assertOk()->assertSee('data-prompt-test-open', false)
            ->assertSee('data-test-config', false)->assertDontSee('data-test-dialog', false)->assertSee('article-prose.css');
        $this->get(route('admin.ai-prompts.create'))->assertOk()->assertDontSee('data-prompt-test-open', false);
        $this->getJson($this->url('test-options'))->assertOk()->assertJsonPath('models.0.id', $this->model->id)
            ->assertJsonPath('knowledge_bases.0.id', $this->knowledge->id)->assertDontSee('test-key')->assertDontSee('api_url');
        $this->prompt->update(['type' => 'quality_check']);
        $this->get(route('admin.ai-prompts.edit', $this->prompt->id))->assertOk()->assertDontSee('data-prompt-test-open', false);
        $this->postJson($this->url('test'), $this->payload())->assertNotFound();
    }

    public function test_unsaved_snapshot_streams_and_reuses_site_markdown_without_production_writes(): void
    {
        $content = "## 概览\n\n文章正文\n\n| 项目 | 值 |\n| --- | --- |\n| A | B |\n\n- [x] 完成\n\n```php\necho 1;\n```\n\n<script>alert(1)</script>\n[危险](javascript:alert(1))";
        MarkdownContentWriterAgent::fake([$content])->preventStrayPrompts();
        $library = TitleLibrary::query()->create(['name' => '标题库']);
        $title = Title::query()->create(['library_id' => $library->id, 'title' => 'GEO 测试', 'used_count' => 0, 'usage_count' => 0]);
        $payload = $this->payload(['title_source' => 'library', 'title_id' => $title->id, 'title' => null,
            'prompt_content' => "  # 测试😀\r\n请写事实 {{title}} {{knowledge}}\n "]);
        $run = $this->generate($payload);
        $this->assertSame('article_ready', $run['status']);
        $this->assertSame("  # 测试😀\n请写事实 {{title}} {{knowledge}}\n ", $run['prompt_content']);
        $this->assertSame('GEO 测试', $run['title']);
        $this->assertSame(ArticleHtmlPresenter::markdownToHtml($content), $run['html']);
        $this->assertStringContainsString('<table', $run['html']);
        $this->assertStringContainsString('type="checkbox"', $run['html']);
        $this->assertStringNotContainsString('<script>', $run['html']);
        $this->assertStringNotContainsString('href="javascript:', $run['html']);
        MarkdownContentWriterAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, '# 测试😀') && str_contains($prompt->prompt, 'GEO 测试'));
        $this->assertSame('已保存提示词', $this->prompt->fresh()->content);
        $this->assertSame(0, Article::query()->count());
        $this->assertSame(0, $title->fresh()->usage_count);
        $this->assertSame(0, $title->fresh()->used_count);
        $this->assertSame(0, $this->knowledge->fresh()->usage_count);
        $this->assertSame(1, $this->model->fresh()->total_used);
        $this->assertSame(1, AiModelUsageEvent::query()->where('operation', 'prompt_test.generate')->count());
    }

    public function test_diagnosis_is_separate_validated_and_idempotent(): void
    {
        $run = $this->generate();
        $this->assertNull($run['diagnosis']);
        $request = ['request_id' => (string) Str::uuid()];
        $this->postJson($this->url('tests.diagnose', $run['id']), $request)->assertOk()
            ->assertJsonPath('status', 'completed')->assertJsonPath('diagnosis.overall', 75)
            ->assertJsonPath('diagnosis.suggestions.0.anchor.quote', '请写事实');
        $this->postJson($this->url('tests.diagnose', $run['id']), $request)->assertOk()->assertJsonPath('status', 'completed');
        $this->assertSame(2, $this->model->fresh()->total_used);
        $this->assertSame(1, AiModelUsageEvent::query()->where('operation', 'prompt_test.diagnose')->count());
    }

    public function test_invalid_diagnosis_preserves_article_and_can_retry_without_generation(): void
    {
        $bad = $this->diagnosticResult();
        $bad['criteria'][0]['quote'] = '编造引文';
        PromptDiagnosticAgent::fake([$bad, $this->diagnosticResult()])->preventStrayPrompts();
        $run = $this->generate();
        $first = ['request_id' => (string) Str::uuid()];
        $this->postJson($this->url('tests.diagnose', $run['id']), $first)->assertOk()
            ->assertJsonPath('status', 'diagnosis_failed')->assertJsonPath('content', '文章正文');
        $this->postJson($this->url('tests.diagnose', $run['id']), $first)->assertConflict();
        $this->postJson($this->url('tests.diagnose', $run['id']), ['request_id' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('status', 'completed');
        $this->assertSame(1, AiModelUsageEvent::query()->where('operation', 'prompt_test.generate')->count());
        $this->assertSame(2, AiModelUsageEvent::query()->where('operation', 'prompt_test.diagnose')->count());
    }

    public function test_invalid_diagnosis_consumes_its_daily_attempt_and_cannot_retry_past_the_limit(): void
    {
        $this->model->forceFill(['daily_limit' => 2])->save();
        $run = $this->generate();
        PromptDiagnosticAgent::fake([['criteria' => [], 'suggestions' => []]])->preventStrayPrompts();
        $this->postJson($this->url('tests.diagnose', $run['id']), ['request_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('status', 'diagnosis_failed');
        $this->assertSame(2, $this->model->fresh()->used_today);
        $this->assertSame(1, $this->model->fresh()->total_used);
        $this->postJson($this->url('tests.diagnose', $run['id']), ['request_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_duplicate_and_concurrent_generation_requests_are_rejected(): void
    {
        $payload = $this->payload();
        $this->generate($payload);
        $this->postJson($this->url('test'), $payload)->assertConflict();
        $lock = app(PromptTestRunStore::class)->acquire($this->admin->id, 30);
        try {
            $this->postJson($this->url('test'), $this->payload())->assertConflict();
        } finally {
            $lock->release();
        }
        $this->assertSame(1, AiModelUsageEvent::query()->where('operation', 'prompt_test.generate')->count());
    }

    public function test_cancel_before_provider_delivery_preserves_partial_output_and_releases_locks(): void
    {
        $payload = $this->payload();
        MarkdownContentWriterAgent::fake(function () use ($payload): string {
            $store = app(PromptTestRunStore::class);
            $store->cancel($store->find($this->admin->id, $this->prompt->id, $payload['request_id']));

            return '未交付正文';
        })->preventStrayPrompts();
        $run = $this->generate($payload);
        $this->assertSame('cancelled', $run['status']);
        $this->postJson($this->url('tests.diagnose', $run['id']), ['request_id' => (string) Str::uuid()])->assertConflict();
        $lock = app(PromptTestRunStore::class)->acquire($this->admin->id, 30);
        $this->assertNotNull($lock);
        $lock->release();
        PromptDiagnosticAgent::assertNeverPrompted();
    }

    public function test_cancel_at_stream_end_preserves_the_received_usage_in_the_discarded_attempt(): void
    {
        $payload = $this->payload();
        $cancel = function () use ($payload): void {
            $store = app(PromptTestRunStore::class);
            $store->cancel($store->find($this->admin->id, $this->prompt->id, $payload['request_id']));
        };
        $gateway = new class($cancel) extends FakeTextGateway
        {
            public function __construct(private readonly \Closure $cancel)
            {
                parent::__construct([new TextResponse('已经生成的正文', new Usage(123, 45), new Meta('test', 'test-chat-model'))]);
            }

            public function generateStreamStep(
                string $invocationId,
                TextProvider $provider,
                string $model,
                ?string $instructions,
                array $messages,
                array $tools,
                ?array $schema,
                ?TextGenerationOptions $options,
                ?int $timeout,
                StepContext $stepContext,
            ): \Generator {
                $response = yield from parent::generateStreamStep(
                    $invocationId, $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext,
                );
                ($this->cancel)();

                return $response;
            }
        };
        Ai::swap(new AiManager($this->app));
        $provider = OpenAiRuntimeProvider::registerProvider('article_editor', 'openai-compatible', 'https://ai.test/v1', 'test-key');
        Ai::textProvider($provider)->useTextGateway($gateway);
        Http::preventStrayRequests();

        $run = $this->generate($payload);

        $this->assertSame('cancelled', $run['status']);
        $this->assertSame('已经生成的正文', $run['content']);
        $attempt = AiModelUsageEvent::query()->where('operation', 'prompt_test.generate')->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_DISCARDED, $attempt->status);
        $this->assertSame('cancelled', $attempt->error_code);
        $this->assertSame(123, $attempt->input_tokens);
        $this->assertSame(45, $attempt->output_tokens);
        $this->assertSame(168, $attempt->total_tokens);
        Http::assertNothingSent();
    }

    public function test_reasoning_progress_is_visible_without_exposing_private_reasoning_text(): void
    {
        $this->model->forceFill(['model_id' => 'deepseek-test'])->save();
        Ai::swap(new AiManager($this->app));
        Http::preventStrayRequests();
        $chunks = [
            ['choices' => [['delta' => ['reasoning_content' => '仅供模型内部使用的推理内容'], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['content' => '文章正文'], 'finish_reason' => null]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 123, 'completion_tokens' => 45]],
        ];
        $body = implode('', array_map(fn (array $chunk): string => 'data: '.json_encode($chunk, JSON_UNESCAPED_UNICODE)."\n\n", $chunks))."data: [DONE]\n\n";
        Http::fake(['https://ai.test/v1/chat/completions' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);

        $response = $this->postJson($this->url('test'), $this->payload())->assertOk();
        $stream = $response->streamedContent();

        $this->assertStringContainsString('"phase":"reasoning"', $stream);
        $this->assertStringContainsString('"phase":"writing"', $stream);
        $this->assertStringContainsString('文章正文', $stream);
        $this->assertStringContainsString('"type":"article_complete"', $stream);
        $this->assertStringNotContainsString('仅供模型内部使用的推理内容', $stream);
        $attempt = AiModelUsageEvent::query()->where('operation', 'prompt_test.generate')->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $attempt->status);
        $this->assertSame(123, $attempt->input_tokens);
        $this->assertSame(45, $attempt->output_tokens);
        Http::assertSentCount(1);
    }

    public function test_cancel_is_acknowledged_while_provider_cleanup_still_holds_the_lease(): void
    {
        $prepared = app(PromptTestService::class)->start($this->admin, $this->prompt, $this->payload());
        try {
            $run = $prepared['run'];
            $this->postJson($this->url('tests.cancel', $run['id']))->assertOk()
                ->assertJsonPath('status', 'cancelled')->assertJsonPath('processing', true);
            $this->getJson($this->url('tests.show', $run['id']))->assertOk()
                ->assertJsonPath('status', 'cancelled')->assertJsonPath('processing', true);
            $this->assertNull(app(PromptTestRunStore::class)->acquire($this->admin->id, 30));
            $run['status'] = 'cancelled';
            app(PromptTestRunStore::class)->save($run);
            $this->getJson($this->url('tests.show', $run['id']))->assertJsonPath('processing', false);
        } finally {
            $prepared['lock']->release();
        }
    }

    public function test_cancelling_an_already_generated_article_is_terminal_and_preserves_it(): void
    {
        $run = $this->generate();
        $this->postJson($this->url('tests.cancel', $run['id']))->assertOk()
            ->assertJsonPath('status', 'cancelled')->assertJsonPath('processing', false)
            ->assertJsonPath('content', $run['content']);
    }

    public function test_cancel_endpoint_prevents_diagnosis_and_snapshots_are_scoped_and_expire(): void
    {
        $run = $this->generate();
        $this->postJson($this->url('tests.cancel', $run['id']))->assertOk()->assertJsonPath('cancel_requested', true);
        $this->postJson($this->url('tests.diagnose', $run['id']), ['request_id' => (string) Str::uuid()])->assertConflict();
        $other = Admin::query()->create(['username' => 'other', 'password' => 'secret', 'role' => 'admin', 'status' => 'active']);
        $this->actingAs($other, 'admin')->getJson($this->url('tests.show', $run['id']))->assertNotFound();
        $this->actingAs($this->admin, 'admin');
        $this->travel(121)->minutes();
        $this->getJson($this->url('tests.show', $run['id']))->assertNotFound();
    }

    public function test_revoked_generation_access_stops_output_and_forbids_run_recovery(): void
    {
        MarkdownContentWriterAgent::fake(function (): string {
            $this->admin->increment('ai_config_access_version');

            return '不应交付';
        })->preventStrayPrompts();
        $payload = $this->payload();
        $response = $this->postJson($this->url('test'), $payload)->assertOk();
        $this->assertStringContainsString('access_changed', $response->streamedContent());
        $this->getJson($this->url('tests.show', $payload['request_id']))->assertUnprocessable();
    }

    public function test_validation_and_model_access_happen_before_model_calls(): void
    {
        $this->postJson($this->url('test'), $this->payload(['knowledge_base_ids' => []]))->assertJsonValidationErrors('knowledge_base_ids');
        $this->postJson($this->url('test'), $this->payload(['knowledge_base_ids' => array_fill(0, 6, $this->knowledge->id)]))->assertJsonValidationErrors('knowledge_base_ids');
        $this->postJson($this->url('test'), $this->payload(['title_source' => 'library']))->assertJsonValidationErrors('title_id');
        $this->postJson($this->url('test'), $this->payload(['ai_model_id' => 999999]))->assertUnprocessable();
        MarkdownContentWriterAgent::assertNeverPrompted();
    }

    public function test_stale_run_is_reported_as_interrupted_without_replaying_generation(): void
    {
        $run = $this->generate();
        $store = app(PromptTestRunStore::class);
        $cached = $store->find($this->admin->id, $this->prompt->id, $run['id']);
        $cached['status'] = 'generating';
        $cached['deadline'] = now()->timestamp - 1;
        $store->save($cached);
        $this->getJson($this->url('tests.show', $run['id']))->assertOk()
            ->assertJsonPath('status', 'generation_failed')->assertJsonPath('error_code', 'interrupted')
            ->assertJsonPath('content', '文章正文');
    }

    public function test_browser_accept_header_returns_field_errors_without_redirecting_to_html(): void
    {
        $this->postJson($this->url('test'), $this->payload(['prompt_content' => str_repeat('长', 50001)]),
            ['Accept' => 'application/json, text/event-stream'])
            ->assertUnprocessable()->assertJsonValidationErrors('prompt_content');
        MarkdownContentWriterAgent::assertNeverPrompted();
    }

    public function test_search_and_status_reads_do_not_consume_generation_diagnosis_or_cancel_limits(): void
    {
        for ($index = 0; $index < 12; $index++) {
            $this->getJson($this->url('test-options'))->assertOk();
        }
        $run = $this->generate();
        $this->postJson($this->url('tests.diagnose', $run['id']), ['request_id' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('status', 'completed');
        for ($index = 0; $index < 20; $index++) {
            $this->getJson($this->url('tests.show', $run['id']))->assertOk();
        }
        $this->postJson($this->url('tests.cancel', $run['id']))->assertOk();
    }

    public function test_diagnosis_cannot_start_when_the_snapshot_will_expire_during_the_call(): void
    {
        $run = $this->generate();
        $this->travel(118)->minutes();
        $this->postJson($this->url('tests.diagnose', $run['id']), ['request_id' => (string) Str::uuid()])->assertGone();
        PromptDiagnosticAgent::assertNeverPrompted();
        $this->assertSame(1, $this->model->fresh()->total_used);
        $this->getJson($this->url('tests.show', $run['id']))->assertOk()->assertJsonPath('content', '文章正文');
    }

    public function test_retrieval_checks_cancellation_before_each_knowledge_base(): void
    {
        $other = KnowledgeBase::query()->create(['name' => '第二知识库', 'content' => '补充资料']);
        $checks = 0;
        try {
            app(KnowledgeRetrievalService::class)->retrieveContextBundleFromMany(
                [$this->knowledge->id, $other->id], 'GEO', identity: $this->admin,
                assertActive: function () use (&$checks): void {
                    if (++$checks === 2) {
                        throw new \RuntimeException('cancelled');
                    }
                },
            );
            $this->fail('The cancelled retrieval must not complete.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('cancelled', $exception->getMessage());
            $this->assertSame(2, $checks);
        }
    }

    private function generate(?array $payload = null): array
    {
        $payload ??= $this->payload();
        $response = $this->postJson($this->url('test'), $payload)->assertOk();
        $stream = $response->streamedContent();
        $this->assertStringContainsString('"type":"started"', $stream);

        return $this->getJson($this->url('tests.show', $payload['request_id']))->assertOk()->json();
    }

    private function url(string $name, ?string $runId = null): string
    {
        return route('admin.ai-prompts.'.$name, array_filter(['promptId' => $this->prompt->id, 'runId' => $runId]));
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'prompt_content' => '请写事实 {{title}} {{knowledge}}',
            'knowledge_base_ids' => [$this->knowledge->id], 'title_source' => 'manual', 'title' => '测试文章',
            'keyword' => 'GEO', 'ai_model_id' => $this->model->id], $overrides);
    }

    private function diagnosticResult(): array
    {
        $criteria = [];
        foreach (array_merge(...array_values(PromptTestDiagnosticResult::DIMENSIONS)) as $id) {
            $criteria[] = ['id' => $id, 'level' => 3, 'status' => 'observed', 'quote' => '文章正文', 'scope' => '', 'reason' => '可核验的正文依据'];
        }

        return ['criteria' => $criteria, 'suggestions' => [[
            'dimension' => 'geo', 'priority' => 'high', 'problem' => '来源说明不足', 'article_quote' => '文章正文',
            'article_scope' => '', 'segment_id' => 'p1', 'prompt_quote' => '请写事实', 'action' => 'replace',
            'replacement' => '请写事实并引用知识库来源', 'reason' => '让依据可追溯', 'expected_improvement' => '便于核验', 'needed_evidence' => '',
        ]]];
    }
}
