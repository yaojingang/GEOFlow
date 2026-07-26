<?php

namespace Tests\Unit;

use App\Services\GeoFlow\AiVisibility\AiVisibilityResultNormalizer;
use PHPUnit\Framework\TestCase;

class AiVisibilityResultNormalizerTest extends TestCase
{
    public function test_it_normalizes_doubao_ark_responses_annotations(): void
    {
        $result = (new AiVisibilityResultNormalizer)->normalizeArkResponses([
            'id' => 'resp_123',
            'output' => [
                [
                    'type' => 'web_search_call',
                    'id' => 'ws_123',
                    'status' => 'completed',
                    'action' => ['query' => 'GEOFlow'],
                ],
                [
                    'type' => 'message',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => 'GEOFlow 是一个面向 AI 可见性的内容系统。',
                            'annotations' => [
                                [
                                    'type' => 'url_citation',
                                    'title' => 'GEOFlow Docs',
                                    'url' => 'https://www.example.com/geoflow',
                                    'text' => 'GEOFlow Docs',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 100,
                'tool_usage' => ['web_search' => 1],
            ],
        ], ['payload' => ['model' => 'doubao-seed']], 'doubao-seed', 120);

        $this->assertSame('GEOFlow 是一个面向 AI 可见性的内容系统。', $result->answerText);
        $this->assertSame('doubao-seed', $result->modelId);
        $this->assertSame(120, $result->latencyMs);
        $this->assertCount(1, $result->sources);
        $this->assertSame('native_annotation', $result->sources[0]->sourceType);
        $this->assertSame('example.com', $result->sources[0]->domain);
        $this->assertSame('ws_123', $result->metadata['web_search_calls'][0]['id']);
    }

    public function test_it_normalizes_doubao_search_custom_results(): void
    {
        $result = (new AiVisibilityResultNormalizer)->normalizeDoubaoSearchCustom([
            'LogId' => 'log_123',
            'Result' => [
                'TimeCost' => 122,
                'WebResults' => [
                    [
                        'Title' => 'AI Visibility Guide',
                        'SiteName' => 'Example',
                        'Url' => 'https://example.com/ai-visibility',
                        'Snippet' => 'AI 可见性指南',
                        'Summary' => '介绍如何优化 AI 可见性。',
                        'Content' => '正文内容',
                        'PublishTime' => '2026-07-01T10:00:00+08:00',
                        'RankScore' => 0.98,
                        'AuthInfoLevel' => 'high',
                    ],
                ],
            ],
        ], ['payload' => ['Query' => 'AI visibility']], 88);

        $this->assertSame('', $result->answerText);
        $this->assertSame('doubao_search_custom', $result->providerType);
        $this->assertSame(88, $result->latencyMs);
        $this->assertSame('log_123', $result->metadata['log_id']);
        $this->assertCount(1, $result->sources);
        $this->assertSame('web_search_result', $result->sources[0]->sourceType);
        $this->assertSame('example.com', $result->sources[0]->domain);
        $this->assertSame(0.98, $result->sources[0]->rankScore);
    }
}
