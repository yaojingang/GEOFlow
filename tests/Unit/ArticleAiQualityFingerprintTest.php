<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleAiQualityFingerprint;
use PHPUnit\Framework\TestCase;

class ArticleAiQualityFingerprintTest extends TestCase
{
    public function test_fingerprint_is_canonical_and_changes_when_a_publication_input_changes(): void
    {
        $fingerprint = new ArticleAiQualityFingerprint;
        $input = [
            'article' => ['title' => '标题', 'content' => '正文'],
            'policy' => ['pass_score' => 85, 'model_id' => 3],
            'prompt' => ['id' => 2, 'hash' => hash('sha256', 'prompt')],
            'knowledge' => [['id' => 9, 'chunk_source_hash' => 'kb-v1']],
            'rules' => ['version' => 'cn-ads-1.0.0'],
            'publication_context' => ['channel' => 'website'],
        ];

        $reordered = [
            'publication_context' => ['channel' => 'website'],
            'rules' => ['version' => 'cn-ads-1.0.0'],
            'knowledge' => [['chunk_source_hash' => 'kb-v1', 'id' => 9]],
            'prompt' => ['hash' => hash('sha256', 'prompt'), 'id' => 2],
            'policy' => ['model_id' => 3, 'pass_score' => 85],
            'article' => ['content' => '正文', 'title' => '标题'],
        ];

        $this->assertSame($fingerprint->make($input), $fingerprint->make($reordered));

        $changed = $input;
        $changed['policy']['pass_score'] = 90;
        $this->assertNotSame($fingerprint->make($input), $fingerprint->make($changed));
    }
}
