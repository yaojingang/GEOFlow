<?php

namespace App\Ai\Agents;

use App\Jobs\ProcessGeoFlowTaskJob;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Worker 正文生成专用 Agent：通过 {@see Timeout} 配置 HTTP 超时（秒）。
 *
 * 须小于 {@see ProcessGeoFlowTaskJob::$timeout}，避免队列作业尚未结束而 HTTP 已先超时。
 *
 * 通过 {@see HasProviderOptions} 在请求体注入最大输出 token 配置，覆盖各服务商较小的默认输出上限
 * （常见 4K），避免长文在生成阶段被截断。不同 provider 使用各自兼容的字段名：
 * 火山方舟 Ark / DeepSeek 等 Chat Completions 接口使用 `max_tokens`，OpenAI Responses
 * 使用 `max_output_tokens`，Gemini 使用 `maxOutputTokens`。未设置 maxTokens 时不附带该字段，
 * 不影响知识库切片、URL 导入等其他调用方的既有行为。
 */
#[Timeout(240)]
class MarkdownContentWriterAgent implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable;

    /**
     * @param  iterable<int, mixed>  $messages
     * @param  iterable<int, mixed>  $tools
     */
    public function __construct(
        public string $instructions = '你是专业中文写作助手，请输出高质量、可发布的 Markdown 文章。',
        public iterable $messages = [],
        public iterable $tools = [],
        public ?int $maxTokens = null,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * {@inheritdoc}
     */
    public function messages(): iterable
    {
        return $this->messages;
    }

    /**
     * {@inheritdoc}
     */
    public function tools(): iterable
    {
        return $this->tools;
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        if (is_null($this->maxTokens) || $this->maxTokens <= 0) {
            return [];
        }

        $providerKey = $provider instanceof Lab ? $provider->value : $provider;

        return match ($providerKey) {
            'gemini' => ['maxOutputTokens' => $this->maxTokens],
            'openai' => ['max_output_tokens' => $this->maxTokens],
            default => ['max_tokens' => $this->maxTokens],
        };
    }
}
