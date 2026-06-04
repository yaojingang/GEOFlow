<?php

namespace Tests\Feature;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use ReflectionMethod;
use Tests\TestCase;

class WorkerExecutionServiceMaxTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_writer_agent_uses_provider_specific_max_token_option_names(): void
    {
        $agent = new MarkdownContentWriterAgent(maxTokens: 8192);

        $this->assertSame(['max_tokens' => 8192], $agent->providerOptions('deepseek'));
        $this->assertSame(['max_tokens' => 8192], $agent->providerOptions('openrouter'));
        $this->assertSame(['max_output_tokens' => 8192], $agent->providerOptions('openai'));
        $this->assertSame(['max_output_tokens' => 8192], $agent->providerOptions(Lab::OpenAI));
        $this->assertSame(['maxOutputTokens' => 8192], $agent->providerOptions('gemini'));
        $this->assertSame(['maxOutputTokens' => 8192], $agent->providerOptions(Lab::Gemini));
    }

    public function test_generate_content_sends_configured_model_max_tokens(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response($this->completion('# 标题'."\n\n".'完整正文。')),
        ]);

        $model = $this->createChatModel(['max_tokens' => 8192]);

        $content = $this->generateContent($model, '写一篇文章。');

        $this->assertSame('# 标题'."\n\n".'完整正文。', $content);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions'
            && ($request['max_tokens'] ?? null) === 8192
            && ! array_key_exists('max_completion_tokens', (array) $request->data()));
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
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'generateContent');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $model, $prompt);
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
