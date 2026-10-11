<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\PromptDiagnosticAgent;
use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use App\Support\GeoFlow\PromptTestAnchors;
use App\Support\GeoFlow\PromptTestDiagnosticResult;
use Closure;
use InvalidArgumentException;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StructuredAgentResponse;

class PromptTestDiagnosticService
{
    public function __construct(private readonly ApiKeyCrypto $crypto, private readonly PromptTestDiagnosticResult $validator) {}

    /** @param array<string,mixed> $run */
    public function input(array $run): string
    {
        return json_encode([
            'language' => $run['locale'], 'article_title' => $run['title'], 'keyword' => $run['keyword'],
            'article' => $run['content'], 'prompt' => $run['prompt_content'],
            'prompt_segments' => array_map(fn (array $segment): array => [
                'id' => $segment['id'], 'text' => $segment['text'], 'section' => $segment['section'],
            ], PromptTestAnchors::segments($run['prompt_content'])),
            'rendered_prompt' => $run['rendered_prompt'], 'knowledge_evidence' => $run['evidence'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function timeout(): int
    {
        return (int) config('geoflow.ai_quality_request_timeout_seconds', 160);
    }

    public function review(AiModel $model, string $input, ?Closure $beforeProvider = null): StructuredAgentResponse
    {
        $url = OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url);
        $driver = OpenAiRuntimeProvider::resolveChatDriver($url, (string) $model->model_id);
        $key = $this->crypto->decrypt((string) $model->getRawOriginal('api_key'));
        $provider = OpenAiRuntimeProvider::registerProvider('prompt_diagnostic', $driver, $url, $key);
        $agent = new PromptDiagnosticAgent((int) $model->max_tokens > 0 ? min(8192, (int) $model->max_tokens) : 8192);
        $beforeProvider?->__invoke();
        $response = $agent->prompt($input, [], $provider, (string) $model->model_id, $this->timeout());
        if (! $response instanceof StructuredAgentResponse) {
            throw new InvalidArgumentException('invalid_diagnosis');
        }

        return $response;
    }

    /** @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    public function validate(StructuredAgentResponse $response, array $run): array
    {
        if ($response->steps->last()?->finishReason === FinishReason::Length) {
            throw new InvalidArgumentException('invalid_diagnosis');
        }

        return $this->validator->validate($response->toArray(), $run['content'], $run['prompt_content']);
    }
}
