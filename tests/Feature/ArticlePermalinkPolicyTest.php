<?php

namespace Tests\Feature;

use App\Support\Site\ArticlePermalinkPolicy;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ArticlePermalinkPolicyTest extends TestCase
{
    public function test_future_schema_falls_back_without_logging_untrusted_policy_content(): void
    {
        Log::spy();

        $policy = ArticlePermalinkPolicy::fromRaw([
            'schema_version' => 2,
            'current_pattern' => '/article/{slug}?secret=do-not-log',
            'revision' => 99,
        ]);

        $this->assertSame(ArticlePermalinkPolicy::DEFAULT_PATTERN, $policy->currentPattern);
        $this->assertSame(0, $policy->revision);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Invalid article permalink policy; using the default policy.'
                    && isset($context['exception_type'])
                    && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'do-not-log');
            });
    }
}
