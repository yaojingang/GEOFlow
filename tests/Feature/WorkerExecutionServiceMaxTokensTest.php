<?php

namespace Tests\Feature;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Data\Ai\AiExecutionContext;
use App\Jobs\ProcessGeoFlowTaskJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\Article;
use App\Models\Category;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\Admin\AdminAiModelMutationService;
use App\Services\AiWorkspace\AiModelInvocationLock;
use App\Services\GeoFlow\AiExecutionContextFactory;
use App\Services\GeoFlow\ArticleContentGenerationService;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\WorkerAiModelInvocationGateway;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\Site\ArticleHtmlPresenter;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Enums\Lab;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class WorkerExecutionServiceMaxTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_writer_agent_uses_provider_specific_max_token_option_names(): void
    {
        $agent = new MarkdownContentWriterAgent(maxTokens: 8192);

        $this->assertSame(['max_tokens' => 8192], $agent->providerOptions('deepseek'));
        $this->assertSame(['max_tokens' => 8192], $agent->providerOptions('openai-compatible'));
        $this->assertSame(['max_tokens' => 8192], $agent->providerOptions('openrouter'));
        $this->assertSame(['max_output_tokens' => 8192], $agent->providerOptions('openai'));
        $this->assertSame(['max_output_tokens' => 8192], $agent->providerOptions(Lab::OpenAI));
        $this->assertSame(['maxOutputTokens' => 8192], $agent->providerOptions('gemini'));
        $this->assertSame(['maxOutputTokens' => 8192], $agent->providerOptions(Lab::Gemini));
    }

    public function test_worker_invocation_lock_covers_the_provider_timeout_and_persistence_margin(): void
    {
        $timeoutAttribute = (new ReflectionClass(MarkdownContentWriterAgent::class))
            ->getAttributes(Timeout::class)[0]
            ->newInstance();
        $providerTimeout = app(ArticleContentGenerationService::class)->providerTimeoutSeconds();
        $leaseSeconds = $providerTimeout + WorkerAiModelInvocationGateway::PERSISTENCE_MARGIN_SECONDS;

        $this->assertSame(MarkdownContentWriterAgent::PROVIDER_TIMEOUT_SECONDS, $timeoutAttribute->value);
        $this->assertSame(600, $providerTimeout);
        $jobTimeout = (new ProcessGeoFlowTaskJob(1))->timeout;
        $recoveryTimeout = (new ReflectionMethod(JobQueueService::class, 'recoverStaleJobs'))
            ->getParameters()[0]->getDefaultValue();
        $this->assertGreaterThan($providerTimeout, $jobTimeout);
        $this->assertGreaterThan($jobTimeout, $recoveryTimeout);
        $this->assertGreaterThan($jobTimeout, config('queue.connections.redis.retry_after'));
        $this->assertGreaterThanOrEqual($providerTimeout + 60, $leaseSeconds);

        $locks = app(AiModelInvocationLock::class);
        $invocationLock = $locks->acquireForInvocation(999_991, $leaseSeconds);
        $seconds = new ReflectionProperty(Lock::class, 'seconds');
        $this->assertSame($leaseSeconds, $seconds->getValue($invocationLock));

        $this->travel($providerTimeout)->seconds();
        $this->assertNull($locks->acquireForMutation(999_991));
        $this->travel(WorkerAiModelInvocationGateway::PERSISTENCE_MARGIN_SECONDS - 1)->seconds();
        $this->assertNull($locks->acquireForMutation(999_991));
        $this->travel(2)->seconds();
        $replacement = $locks->acquireForMutation(999_991);
        $this->assertNotNull($replacement);

        $locks->release($replacement);
        $locks->release($invocationLock);
    }

    public function test_same_shared_model_allows_parallel_invocations_while_mutation_remains_exclusive(): void
    {
        $locks = app(AiModelInvocationLock::class);
        $firstInvocation = $locks->acquireForInvocation(999_992);
        $secondInvocation = $locks->acquireForInvocation(999_992);

        try {
            $this->assertNotSame($firstInvocation->owner(), $secondInvocation->owner());
            $this->assertNull($locks->acquireForMutation(999_992));
        } finally {
            $locks->release($secondInvocation);
            $locks->release($firstInvocation);
        }

        $mutation = $locks->acquireForMutation(999_992);
        $this->assertNotNull($mutation);
        $locks->release($mutation);
    }

    public function test_worker_invocation_lock_remains_held_during_post_provider_persistence(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion('持久化前正文。')),
        ]);
        $model = $this->createChatModel();
        $context = $this->executionContextForModel($model, 'worker-lock-persistence');
        $locks = app(AiModelInvocationLock::class);
        $callbackEntered = false;

        $result = app(WorkerAiModelInvocationGateway::class)->generate(
            $context,
            $model,
            '写一篇文章。',
            function (array $invocation) use (&$callbackEntered, $locks, $model): string {
                $callbackEntered = true;
                $this->assertSame('持久化前正文。', (string) $invocation['response']->text);
                $this->assertNull($locks->acquireForMutation((int) $model->id));
                $owner = Admin::query()->findOrFail((int) $model->owner_admin_id);
                $mutation = app(AdminAiModelMutationService::class)->update(
                    $owner,
                    (int) $model->id,
                    ['api_url' => 'https://changed-while-persisting.test'],
                    AiModel::ACCESS_SCOPE_USER_CONTENT,
                );
                $this->assertFalse($mutation->succeeded());
                $this->assertSame('task', $mutation->error);
                $this->assertSame('https://ai.test', (string) $model->fresh()->api_url);

                return 'persisted';
            },
        );

        $this->assertTrue($callbackEntered);
        $this->assertSame('persisted', $result);
        $usageEvent = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $usageEvent->status);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_PERSONAL, $usageEvent->model_source);
        $this->assertSame(10, $usageEvent->input_tokens);
        $this->assertSame(20, $usageEvent->output_tokens);
        $mutationLock = $locks->acquireForMutation((int) $model->id);
        $this->assertNotNull($mutationLock);
        $locks->release($mutationLock);
    }

    public function test_worker_invocation_lock_releases_when_post_provider_persistence_throws(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion('异常路径正文。')),
        ]);
        $model = $this->createChatModel();
        $context = $this->executionContextForModel($model, 'worker-lock-exception');
        $locks = app(AiModelInvocationLock::class);

        try {
            app(WorkerAiModelInvocationGateway::class)->generate(
                $context,
                $model,
                '写一篇文章。',
                static function (): never {
                    throw new \RuntimeException('persistence failed');
                },
            );
            $this->fail('Expected persistence failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('persistence failed', $exception->getMessage());
        }

        $usageEvent = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_DISCARDED, $usageEvent->status);
        $this->assertSame('ai_result_persistence_failed', $usageEvent->error_code);

        $mutationLock = $locks->acquireForMutation((int) $model->id);
        $this->assertNotNull($mutationLock);
        $locks->release($mutationLock);
    }

    public function test_generate_content_sends_configured_model_max_tokens(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion('# 标题'."\n\n".'完整正文。')),
        ]);

        $model = $this->createChatModel(['max_tokens' => 8192]);

        $content = $this->generateContent($model, '写一篇文章。');

        $this->assertSame('# 标题'."\n\n".'完整正文。', $content);
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions'
            && ($request['max_tokens'] ?? null) === 8192
            && ! array_key_exists('max_completion_tokens', (array) $request->data()));
    }

    public function test_generate_content_removes_citation_markers_and_preserves_legitimate_k_terms(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion('结论 [K1]。Vitamin K2 与 K1 签证保留。')),
        ]);

        $content = $this->generateContent($this->createChatModel(), '写一篇文章。');

        $this->assertSame('结论。Vitamin K2 与 K1 签证保留。', $content);
    }

    #[DataProvider('reasoningResponses')]
    public function test_generate_content_removes_only_leading_reasoning(string $raw, string $expected): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://ai.test/v1/chat/completions' => Http::response($this->completion($raw))]);
        $model = $this->createChatModel();

        $content = $this->generateContent($model, '写一篇榜单文章。');

        $this->assertSame($expected, $content);
        $service = app(WorkerExecutionService::class);
        $excerpt = new ReflectionMethod($service, 'buildExcerpt');
        $this->assertSame($excerpt->invoke($service, $expected), $excerpt->invoke($service, $content));
        $this->assertSame(1, (int) $model->fresh()->total_used);
        $this->assertSame(20, AiModelUsageEvent::query()->sole()->output_tokens);
    }

    public static function reasoningResponses(): array
    {
        $body = "## 示例产品 榜单\n\n中文文章正文。";
        $thought = 'Let me plan a product ranking article before writing the final answer.';
        $raw = '<think>'.$thought."</think>\n\n".$body;

        return [
            'inline reasoning' => [$raw, $body],
            'multiple blocks and whitespace' => [" \n<THINK>Analyze.</THINK>\n<think>Again.</think>\n".$body, $body],
            'tag attributes' => ['<think mode="analysis">Analyze.</think>'.$body, $body],
            'SSE text fallback' => ['data: '.json_encode(['choices' => [['delta' => ['content' => $raw]]]])."\n\ndata: [DONE]", $body],
            'plain article' => [$body, $body],
            'literal tags in article' => ["说明：<think>这是引用示例。</think>\n\n```html\n<think>示例</think>\n```", "说明：<think>这是引用示例。</think>\n\n```html\n<think>示例</think>\n```"],
            'ordinary HTML' => ['<div>中文正文</div>', '<div>中文正文</div>'],
            'similar tag' => ['<thinking>中文正文</thinking>', '<thinking>中文正文</thinking>'],
            'short literal' => ['<', '<'],
            'partial ordinary tag' => ['<th', '<th'],
        ];
    }

    public function test_worker_saves_clean_article_excerpt_and_meta_description(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://ai.test/v1/chat/completions' => Http::response($this->completion(
            "<think>Let me plan the article before writing the final answer.</think>\n\n## 示例产品 榜单\n\n中文正文。",
        ))]);
        $model = $this->createChatModel();
        $context = $this->executionContextForModel($model, 'worker-reasoning-persistence');
        $library = TitleLibrary::query()->create(['name' => '榜单标题库']);
        Title::query()->create(['library_id' => $library->id, 'title' => '示例产品 榜单', 'keyword' => '示例产品']);
        Category::query()->create(['name' => '默认分类', 'slug' => 'reasoning-test-category']);
        Task::query()->findOrFail($context->sourceId)->update([
            'title_library_id' => $library->id,
            'draft_limit' => 10,
            'article_limit' => 10,
        ]);

        $result = app(WorkerExecutionService::class)->executeTask($context->sourceId, $context);

        $article = Article::query()->findOrFail($result['article_id']);
        $this->assertSame("## 示例产品 榜单\n\n中文正文。", $article->content);
        $this->assertSame('示例产品 榜单 中文正文。', $article->excerpt);
        $this->assertSame('示例产品 榜单 中文正文。', $article->meta_description);
    }

    public function test_filtered_image_separator_is_absent_from_persisted_excerpt_and_meta_description(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://ai.test/v1/chat/completions' => Http::response($this->completion(
            'Intro text. ![unapproved](https://outside.example/image.jpg) Keep Omega paragraph.',
        ))]);
        $model = $this->createChatModel();
        $context = $this->executionContextForModel($model, 'worker-image-excerpt');
        $library = TitleLibrary::create(['name' => 'Image excerpt titles']);
        Title::create(['library_id' => $library->id, 'title' => 'Image excerpt article', 'keyword' => 'image']);
        Category::create(['name' => 'Image excerpt category', 'slug' => 'image-excerpt-category']);
        Task::findOrFail($context->sourceId)->update([
            'title_library_id' => $library->id, 'draft_limit' => 10, 'article_limit' => 10,
            'image_count' => 0, 'auto_description' => true,
        ]);

        $result = app(WorkerExecutionService::class)->executeTask($context->sourceId, $context);
        $article = Article::findOrFail($result['article_id']);
        $this->assertStringContainsString('&#32;', $article->content);
        $this->assertSame('Intro text. Keep Omega paragraph.', $article->excerpt);
        $this->assertSame('Intro text. Keep Omega paragraph.', $article->meta_description);
        $this->assertSame('Intro text. Keep Omega paragraph.', ArticleHtmlPresenter::cardSummary($article));
    }

    #[DataProvider('reasoningOnlyResponses')]
    public function test_generate_content_rejects_reasoning_without_an_article(string $raw): void
    {
        Http::fake(['https://ai.test/v1/chat/completions' => Http::response($this->completion($raw))]);
        $model = $this->createChatModel(['daily_limit' => 1]);

        try {
            $this->generateContent($model, '写一篇文章。');
            $this->fail('Expected reasoning-only output to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('AI返回空正文', $exception->getMessage());
        }

        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    public static function reasoningOnlyResponses(): array
    {
        return [
            'closed' => ['<think>Analyze only.</think>'],
            'unclosed' => ['<think>Analyze without a final answer.'],
            'incomplete opening tag' => ['<think'],
        ];
    }

    #[DataProvider('miniMaxEndpoints')]
    public function test_reasoning_split_is_limited_to_official_minimax_article_requests(string $url, string $modelId, bool $split): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->completion('中文正文。'))]);
        $model = $this->createChatModel(['api_url' => $url, 'model_id' => $modelId, 'max_tokens' => 8192]);

        $this->assertSame('中文正文。', $this->generateContent($model, '写一篇文章。'));

        Http::assertSent(function ($request) use ($split): bool {
            $this->assertSame(8192, $request['max_tokens']);
            if ($split) {
                $this->assertTrue($request['reasoning_split'] ?? false);
            } else {
                $this->assertArrayNotHasKey('reasoning_split', $request->data());
            }

            return true;
        });
        Http::assertSentCount(1);
    }

    public static function miniMaxEndpoints(): array
    {
        return [
            'China' => ['https://api.minimaxi.com/v1', 'MiniMax-M2.5', true],
            'international' => ['https://api.minimax.io/v1', 'MiniMax-M2.1', true],
            'China current' => ['https://api.minimax.cn/v1', 'MiniMax-M3', true],
            'proxy' => ['https://ai.test/v1', 'MiniMax-M2.5', false],
            'lookalike domain' => ['https://api.minimaxi.com.example.test/v1', 'MiniMax-M2.5', false],
            'other model' => ['https://ai.test/v1', 'test-chat-model', false],
        ];
    }

    public function test_generate_content_releases_usage_for_an_empty_response(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion('')),
        ]);

        $model = $this->createChatModel(['daily_limit' => 1]);

        try {
            $this->generateContent($model, '写一篇文章。');
            $this->fail('Expected empty content to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('AI返回空正文', $exception->getMessage());
        }

        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    public function test_generate_content_resets_a_previous_day_limit_before_calling_the_model(): void
    {
        $this->travelTo('2026-07-27 09:00:00');
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion('# 标题'."\n\n".'新一天正文。')),
        ]);

        $model = $this->createChatModel([
            'daily_limit' => 1,
            'used_today' => 1,
            'usage_date' => '2026-07-26',
        ]);

        $content = $this->generateContent($model, '写一篇文章。');

        $this->assertSame('# 标题'."\n\n".'新一天正文。', $content);
        $this->assertSame('2026-07-27', $model->fresh()->usage_date?->toDateString());
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);
    }

    public function test_generate_content_falls_back_to_config_default_max_tokens(): void
    {
        config(['geoflow.content_max_tokens' => 5000]);

        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion('# 标题'."\n\n".'完整正文。')),
        ]);

        $model = $this->createChatModel(['max_tokens' => null]);

        $this->generateContent($model, '写一篇文章。');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions'
            && ($request['max_tokens'] ?? null) === 5000);
    }

    public function test_generate_content_uses_the_system_default_max_tokens(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion('# 标题'."\n\n".'完整正文。')),
        ]);

        $model = $this->createChatModel(['max_tokens' => null]);

        $this->generateContent($model, '写一篇文章。');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions'
            && ($request['max_tokens'] ?? null) === 16384);
    }

    public function test_generate_content_logs_warning_when_output_looks_truncated(): void
    {
        // 结尾停在未闭合代码块中间，模拟输出 token 用尽被截断。
        $truncated = "# 标题\n\n正文开始。\n\n```\n└── 探";

        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion($truncated)),
        ]);

        Log::spy();

        $model = $this->createChatModel(['max_tokens' => 256]);

        $this->generateContent($model, '写一篇文章。');

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($model): bool {
                return str_contains($message, '疑似被截断')
                    && (int) ($context['ai_model_id'] ?? 0) === (int) $model->id
                    && ($context['unclosed_code_fence'] ?? false) === true;
            })
            ->once();
    }

    public function test_generate_content_does_not_warn_for_complete_output(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion('# 标题'."\n\n".'这是一篇完整收尾的文章。')),
        ]);

        Log::spy();

        $model = $this->createChatModel(['max_tokens' => 8192]);

        $this->generateContent($model, '写一篇文章。');

        Log::shouldNotHaveReceived('warning');
    }

    public function test_generate_content_does_not_warn_for_valid_markdown_colon_ending(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion('# 标题'."\n\n".'下一节重点如下：')),
        ]);

        Log::spy();

        $model = $this->createChatModel(['max_tokens' => 8192]);

        $this->generateContent($model, '写一篇文章。');

        Log::shouldNotHaveReceived('warning');
    }

    public function test_smart_failover_uses_an_authorized_fallback_after_a_transient_primary_failure(): void
    {
        Http::fake([
            'https://primary.test/v1/chat/completions' => Http::response(['error' => ['message' => 'temporary outage']], 503),
            'https://fallback.test/v1/chat/completions' => Http::response($this->completion('# 标题'."\n\n".'备用模型正文。')),
        ]);

        $primary = $this->createChatModel([
            'name' => 'Transient Primary',
            'api_url' => 'https://primary.test',
        ]);
        $fallback = $this->createChatModel([
            'name' => 'Active Fallback',
            'api_url' => 'https://fallback.test',
            'failover_priority' => 1,
        ]);
        $admin = Admin::query()->create([
            'username' => 'worker-failover-admin',
            'password' => 'safe-password',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $provider = Admin::query()->create([
            'username' => 'worker-failover-provider',
            'password' => 'safe-password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $admin->forceFill(['shared_ai_config_owner_id' => $provider->id])->save();
        $primary->forceFill([
            'owner_admin_id' => $admin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $fallback->forceFill([
            'owner_admin_id' => $provider->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $task = Task::query()->create([
            'name' => 'Worker failover task',
            'ai_model_id' => (int) $primary->id,
            'model_selection_mode' => 'smart_failover',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $task->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'model_access_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
        ])->save();
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $run->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'ai_config_access_version' => (int) $admin->ai_config_access_version,
            'requested_ai_model_id' => $primary->id,
            'resolver_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
            'execution_lease_token' => (string) Str::uuid(),
        ])->save();
        $context = app(AiExecutionContextFactory::class)->fromTaskRun($run);

        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'generateContentWithModelSelection');
        $method->setAccessible(true);
        $result = $method->invoke(
            $service,
            $task,
            '写一篇文章。',
            $context,
            static fn (array $generation): array => $generation,
        );

        $this->assertSame((int) $fallback->id, (int) $result['model']->id);
        $this->assertSame(['failed', 'success'], array_column($result['attempts'], 'status'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://primary.test/v1/chat/completions');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://fallback.test/v1/chat/completions');
        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(AiModelUsageEvent::STATUS_FAILED, $events[0]->status);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_PERSONAL, $events[0]->model_source);
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $events[1]->status);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_SHARED, $events[1]->model_source);
        $this->assertSame($provider->id, $events[1]->config_owner_admin_id);
        $this->assertSame($admin->id, $events[1]->execution_admin_id);
    }

    #[DataProvider('emptyReasoningContents')]
    public function test_generation_diagnostics_identify_reasoning_only_output_at_the_token_limit_without_logging_content(?string $providerContent): void
    {
        Log::spy();
        Log::shouldReceive('channel')->with('article_generation')->andReturnSelf();
        Http::preventStrayRequests();
        $payload = $this->completion('');
        $payload['choices'][0]['message']['content'] = $providerContent;
        $payload['id'] = 'provider-request-123';
        $payload['choices'][0]['message']['reasoning_content'] = 'private-reasoning';
        $payload['choices'][0]['finish_reason'] = 'length';
        $payload['usage'] = ['prompt_tokens' => 3387, 'completion_tokens' => 16384];
        Http::fake(['https://ai.test/v1/chat/completions' => Http::response($payload)]);
        $model = $this->createChatModel();

        try {
            $this->generateContent($model, 'private-prompt');
            $this->fail('Expected empty content to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('AI返回空正文', $exception->getMessage());
        }

        Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context) use ($model, $providerContent): bool {
            $this->assertSame('GeoFlow article generation response.', $message);
            $this->assertSame((int) $model->id, $context['ai_model_id']);
            $this->assertSame(600, $context['configured_timeout_seconds']);
            $this->assertSame(16384, $context['max_output_tokens']);
            $this->assertSame(200, $context['http_status']);
            $this->assertSame('length', $context['finish_reason']);
            $this->assertSame(16384, $context['completion_tokens']);
            $this->assertNull($context['reasoning_tokens']);
            $this->assertSame($providerContent === null ? null : 0, $context['provider_content_chars']);
            $this->assertSame(17, $context['provider_reasoning_chars']);
            $this->assertSame(0, $context['clean_content_chars']);
            $this->assertTrue($context['reasoning_budget_exhausted']);
            $this->assertGreaterThan(0, $context['task_run_id']);
            $this->assertGreaterThan(0, $context['task_id']);
            $this->assertSame('provider-request-123', $context['provider_request_id']);
            $this->assertStringStartsWith(gethostname().':queue:', $context['worker_id']);
            $this->assertStringNotContainsString('private-', json_encode($context));
            $this->assertStringNotContainsString('test-api-key', json_encode($context));

            return true;
        });
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    public static function emptyReasoningContents(): array
    {
        return ['empty' => [''], 'null' => [null]];
    }

    #[DataProvider('generationTransportFailures')]
    public function test_generation_diagnostics_distinguish_curl_timeout_and_gateway_failure(string $kind, string $category, ?int $status, ?int $errno): void
    {
        Log::spy();
        Log::shouldReceive('channel')->with('article_generation')->andReturnSelf();
        Http::preventStrayRequests();
        Http::fake(function () use ($kind) {
            if ($kind === 'curl') {
                throw new OutboundRequestFailedException(new ConnectException(
                    'cURL error 28: timed out private-secret',
                    new Request('POST', 'https://ai.test/private-secret'),
                    null,
                    ['errno' => 28, 'total_time' => 240.005, 'connect_time' => 0.2, 'url' => 'private-secret', 'error' => 'private-secret'],
                ));
            }

            return Http::response(['error' => ['message' => 'private-secret', 'type' => 'gateway_error']], 504);
        });
        $model = $this->createChatModel();
        try {
            $this->generateContent($model, 'private-prompt');
            $this->fail('Expected transport failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringStartsWith('AI 生成失败:', $exception->getMessage());
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($category, $status, $errno): bool {
            $this->assertSame('GeoFlow article generation transport failed.', $message);
            $this->assertSame($category, $context['transport_category']);
            $this->assertSame($status, $context['http_status']);
            $this->assertSame($errno, $context['curl_errno']);
            if ($errno === 28) {
                $this->assertSame(240005, $context['http_total_ms']);
                $this->assertSame(200, $context['http_connect_ms']);
            }
            $this->assertStringNotContainsString('private-', json_encode($context));

            return true;
        });
        $this->assertSame(0, (int) $model->fresh()->total_used);
        $this->assertSame('ai_provider_request_failed', AiModelUsageEvent::query()->sole()->error_code);
    }

    public static function generationTransportFailures(): array
    {
        return ['curl timeout' => ['curl', 'timeout', null, 28], 'gateway timeout' => ['gateway', 'gateway', 504, null]];
    }

    public function test_diagnostic_logger_failure_preserves_generated_content_and_quota_accounting(): void
    {
        Log::shouldReceive('channel')->with('article_generation')->andReturnSelf();
        Log::shouldReceive('info')->once()->andThrow(new \RuntimeException('diagnostic sink unavailable'));
        Http::preventStrayRequests();
        Http::fake(['https://ai.test/v1/chat/completions' => Http::response($this->completion('文章正文。'))]);
        $model = $this->createChatModel();

        $this->assertSame('文章正文。', $this->generateContent($model, '写一篇文章。'));
        $this->assertSame(1, (int) $model->fresh()->total_used);
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, AiModelUsageEvent::query()->sole()->status);
    }

    public function test_generation_diagnostics_are_persisted_when_the_default_logger_filters_info(): void
    {
        $prefix = sys_get_temp_dir().'/geoflow-generation-'.Str::uuid();
        config(['logging.channels.daily.level' => 'warning', 'logging.default' => 'daily']);
        config(['logging.channels.article_generation' => [
            'driver' => 'daily', 'path' => $prefix.'.log', 'level' => 'info', 'days' => 14,
        ]]);
        Http::preventStrayRequests();
        Http::fake(['https://ai.test/v1/chat/completions' => Http::response($this->completion('private-article'))]);

        try {
            $this->generateContent($this->createChatModel(), 'private-prompt');

            $files = glob($prefix.'*.log');
            $this->assertCount(1, $files);
            $log = file_get_contents($files[0]);
            $this->assertStringContainsString('GeoFlow article generation response.', $log);
            $this->assertStringContainsString('"http_status":200', $log);
            $this->assertStringNotContainsString('private-', $log);
            $this->assertStringNotContainsString('test-api-key', $log);
        } finally {
            Log::forgetChannel('article_generation');
            foreach (glob($prefix.'*.log') as $file) {
                unlink($file);
            }
        }
    }

    #[DataProvider('glmPreviewBudgets')]
    public function test_glm_preview_output_budget_reaches_the_provider_and_preserves_explicit_model_limits(string $url, string $modelId, ?int $configured, int $expected, int $global = 16384): void
    {
        config(['geoflow.content_max_tokens' => $global]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->completion('文章正文。'))]);
        $model = $this->createChatModel(['api_url' => $url, 'model_id' => $modelId, 'max_tokens' => $configured]);

        $this->assertSame('文章正文。', $this->generateContent($model, '写一篇文章。'));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => ($request['max_tokens'] ?? null) === $expected);
    }

    public static function glmPreviewBudgets(): array
    {
        return [
            'verified official preview' => ['https://open.bigmodel.cn/api/coding/paas/v4/chat/completions', 'glm-x-preview-f', null, 32768],
            'explicit smaller limit' => ['https://open.bigmodel.cn/api/coding/paas/v4/chat/completions', 'glm-x-preview-f', 8192, 8192],
            'explicit larger limit' => ['https://open.bigmodel.cn/api/coding/paas/v4/chat/completions', 'glm-x-preview-f', 65536, 65536],
            'larger global default' => ['https://open.bigmodel.cn/api/coding/paas/v4/chat/completions', 'glm-x-preview-f', null, 65536, 65536],
            'other model' => ['https://open.bigmodel.cn/api/coding/paas/v4/chat/completions', 'glm-4-flash', null, 16384],
            'other provider' => ['https://ai.test', 'glm-x-preview-f', null, 16384],
            'lookalike provider' => ['https://open.bigmodel.cn.untrusted.test', 'glm-x-preview-f', null, 16384],
        ];
    }

    public function test_generation_diagnostics_keep_successful_content_and_reasoning_token_counts_separate(): void
    {
        Log::spy();
        Log::shouldReceive('channel')->with('article_generation')->andReturnSelf();
        Http::preventStrayRequests();
        $payload = $this->completion('private-article');
        $payload['choices'][0]['message']['reasoning_content'] = 'private-reasoning';
        $payload['usage']['completion_tokens_details'] = ['reasoning_tokens' => 12];
        Http::fake(['https://ai.test/v1/chat/completions' => Http::response($payload)]);

        $this->assertSame('private-article', $this->generateContent($this->createChatModel(), 'private-prompt'));

        Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertSame('GeoFlow article generation response.', $message);
            $this->assertSame('stop', $context['finish_reason']);
            $this->assertSame(12, $context['reasoning_tokens']);
            $this->assertSame(15, $context['clean_content_chars']);
            $this->assertFalse($context['reasoning_budget_exhausted']);
            $this->assertStringNotContainsString('private-', json_encode($context));

            return true;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function completion(string $content): array
    {
        return [
            'model' => 'test-chat-model',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ];
    }

    private function generateContent(AiModel $model, string $prompt): string
    {
        $context = $this->executionContextForModel($model, 'worker-content-admin-'.$model->id);
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'generateContent');
        $method->setAccessible(true);
        $result = $method->invoke(
            $service,
            $context,
            $model,
            $prompt,
            static fn (array $generation): array => $generation,
        );

        return (string) $result['content'];
    }

    private function executionContextForModel(AiModel $model, string $username): AiExecutionContext
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'safe-password',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $admin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $task = Task::query()->create([
            'name' => 'Worker content invocation '.$model->id,
            'ai_model_id' => (int) $model->id,
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $task->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'model_access_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
        ])->save();
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $run->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'ai_config_access_version' => (int) $admin->ai_config_access_version,
            'requested_ai_model_id' => $model->id,
            'resolver_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
            'execution_lease_token' => (string) Str::uuid(),
        ])->save();

        return app(AiExecutionContextFactory::class)->fromTaskRun($run);
    }

    private function createChatModel(array $overrides = []): AiModel
    {
        return AiModel::query()->create(array_merge([
            'name' => 'Test Chat',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-chat-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test',
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $overrides));
    }
}
