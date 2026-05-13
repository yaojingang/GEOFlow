<?php

namespace Tests\Unit;

use App\Support\GeoFlow\OpenAiRuntimeProvider;
use RuntimeException;
use Tests\TestCase;
use TypeError;

class OpenAiRuntimeProviderTest extends TestCase
{
    public function test_it_normalizes_html_response_errors_into_actionable_api_url_hint(): void
    {
        $message = OpenAiRuntimeProvider::normalizeApiException(
            new RuntimeException('API响应格式错误：{"http":200,"body":"<!doctype html><html lang=\"zh-CN\">"}'),
            'https://example.com/v1'
        );

        $this->assertStringContainsString('AI 接口返回了非 JSON 响应', $message);
        $this->assertStringContainsString('https://example.com/v1/chat/completions', $message);
        $this->assertStringContainsString('不是官网、控制台、代理页或网页地址', $message);
    }

    public function test_it_normalizes_laravel_ai_null_json_type_error(): void
    {
        $message = OpenAiRuntimeProvider::normalizeApiException(
            new TypeError('Laravel\Ai\Gateway\DeepSeek\Concerns\ParsesTextResponses::validateTextResponse(): Argument #1 ($data) must be of type array, null given'),
            'https://api.deepseek.com/v1'
        );

        $this->assertStringContainsString('AI 接口返回了非 JSON 响应', $message);
    }

    public function test_it_keeps_regular_api_errors_unchanged(): void
    {
        $message = OpenAiRuntimeProvider::normalizeApiException(
            new RuntimeException('DeepSeek Error: [invalid_request] model not found'),
            'https://api.deepseek.com/v1'
        );

        $this->assertSame('DeepSeek Error: [invalid_request] model not found', $message);
    }

    public function test_it_keeps_regular_generated_text_unchanged(): void
    {
        $content = "# 标题\n\n这是正常生成的正文。";

        $this->assertSame($content, OpenAiRuntimeProvider::normalizeGeneratedText($content));
    }

    public function test_it_extracts_generated_text_from_sse_chunks(): void
    {
        $content = implode("\n", [
            'data: {"id":"1","object":"chat.completion.chunk","choices":[{"delta":{"content":"第一段"}}]}',
            'data: {"id":"1","object":"chat.completion.chunk","choices":[{"delta":{"content":"，第二段"}}]}',
            'data: [DONE]',
        ]);

        $this->assertSame('第一段，第二段', OpenAiRuntimeProvider::normalizeGeneratedText($content));
    }

    public function test_it_drops_empty_usage_only_sse_chunks(): void
    {
        $content = implode("\n\n", [
            'data: {"id":"","object":"chat.completion.chunk","created":0,"model":"gpt-5.5","choices":[],"usage":{"prompt_tokens":1158,"completion_tokens":0,"total_tokens":1158}}',
            'data: [DONE]',
        ]);

        $this->assertSame('', OpenAiRuntimeProvider::normalizeGeneratedText($content));
    }

    public function test_it_resolves_embedding_base_urls_without_forcing_chat_endpoint(): void
    {
        $this->assertSame(
            'https://api.openai.com/v1',
            OpenAiRuntimeProvider::resolveEmbeddingBaseUrl('https://api.openai.com')
        );

        $this->assertSame(
            'https://api.example.com/v1',
            OpenAiRuntimeProvider::resolveEmbeddingBaseUrl('https://api.example.com/v1/embeddings')
        );

        $this->assertSame(
            'https://ark.cn-beijing.volces.com/api/v3',
            OpenAiRuntimeProvider::resolveEmbeddingBaseUrl('https://ark.cn-beijing.volces.com/api/v3')
        );
    }

    public function test_it_resolves_chat_driver_for_openai(): void
    {
        $this->assertSame('openai', OpenAiRuntimeProvider::resolveChatDriver('https://api.openai.com/v1', 'gpt-4'));
    }

    public function test_it_resolves_chat_driver_for_deepseek(): void
    {
        $this->assertSame('deepseek', OpenAiRuntimeProvider::resolveChatDriver('https://api.deepseek.com/v1', 'deepseek-chat'));
    }

    public function test_it_resolves_chat_driver_by_model_prefix(): void
    {
        $this->assertSame('deepseek', OpenAiRuntimeProvider::resolveChatDriver('https://custom.api.com/v1', 'deepseek-v4-pro'));
    }

    public function test_it_resolves_chat_driver_for_openrouter(): void
    {
        $this->assertSame('openrouter', OpenAiRuntimeProvider::resolveChatDriver('https://openrouter.ai/api/v1', 'anthropic/claude-3'));
    }

    public function test_it_resolves_chat_driver_for_zhipu(): void
    {
        $this->assertSame('deepseek', OpenAiRuntimeProvider::resolveChatDriver('https://open.bigmodel.cn/api/paas/v4', 'glm-5.1'));
    }

    public function test_it_resolves_chat_driver_for_minimax(): void
    {
        $this->assertSame('deepseek', OpenAiRuntimeProvider::resolveChatDriver('https://api.minimaxi.com/v1', 'MiniMax-M2.7'));
    }

    public function test_it_resolves_chat_driver_for_siliconflow(): void
    {
        $this->assertSame('deepseek', OpenAiRuntimeProvider::resolveChatDriver('https://api.siliconflow.cn/v1', 'deepseek-ai/DeepSeek-V4-Flash'));
    }

    public function test_it_resolves_chat_driver_for_volcengine(): void
    {
        $this->assertSame('deepseek', OpenAiRuntimeProvider::resolveChatDriver('https://ark.cn-beijing.volces.com/api/v3', 'doubao-seed-2-0-lite-260428'));
    }

    public function test_it_resolves_chat_driver_for_aliyun(): void
    {
        $this->assertSame('deepseek', OpenAiRuntimeProvider::resolveChatDriver('https://dashscope.aliyuncs.com/compatible-mode/v1', 'qwen3.6-plus'));
    }

    public function test_it_defaults_to_deepseek_for_unknown(): void
    {
        $this->assertSame('deepseek', OpenAiRuntimeProvider::resolveChatDriver('https://api.unknown-provider.com/v1', 'some-model'));
    }
}
