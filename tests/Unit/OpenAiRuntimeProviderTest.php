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
}
