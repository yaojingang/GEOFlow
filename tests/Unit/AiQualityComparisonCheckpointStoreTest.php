<?php

namespace Tests\Unit;

use App\Exceptions\AiQualityComparisonCheckpointException;
use App\Services\GeoFlow\AiQualityComparisonCheckpointStore;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AiQualityComparisonCheckpointStoreTest extends TestCase
{
    public function test_checkpoint_claim_is_exclusive_and_a_matching_run_can_resume_completed_calls(): void
    {
        $directory = storage_path('framework/testing/comparison-checkpoint-exclusive');
        File::deleteDirectory($directory);
        $basePath = $directory.'/report';
        $request = $this->request();
        $store = app(AiQualityComparisonCheckpointStore::class);
        $first = $store->claim($basePath, 'run-one', $request);
        $store->persist($first, [['article_id' => 10, 'attempt' => 1, 'mode' => 'atomic']]);

        try {
            $store->claim($basePath, 'concurrent-run', $request);
            $this->fail('Expected the active checkpoint claim to reject a concurrent consumer.');
        } catch (AiQualityComparisonCheckpointException $exception) {
            $this->assertSame('ai_quality_comparison_checkpoint_busy', $exception->getMessage());
        } finally {
            $first->release();
        }

        $resumed = $store->claim($basePath, 'run-two', $request);
        try {
            $this->assertCount(1, $resumed->calls);
            $checkpoint = json_decode((string) File::get($basePath.'.partial.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('run-two', $checkpoint['run_id']);
            $this->assertSame([], glob($basePath.'.partial.json.*.tmp') ?: []);
        } finally {
            $resumed->release();
            File::deleteDirectory($directory);
        }
    }

    public function test_checkpoint_with_a_different_fingerprint_is_preserved_and_cannot_be_claimed(): void
    {
        $directory = storage_path('framework/testing/comparison-checkpoint-mismatch');
        File::deleteDirectory($directory);
        $basePath = $directory.'/report';
        $store = app(AiQualityComparisonCheckpointStore::class);
        $claim = $store->claim($basePath, 'original-run', $this->request());
        $store->persist($claim, [['article_id' => 10, 'attempt' => 1, 'mode' => 'atomic']]);
        $claim->release();
        $before = (string) File::get($basePath.'.partial.json');

        try {
            $store->claim($basePath, 'different-run', $this->request(['admin_id' => 99]));
            $this->fail('Expected a different execution identity to be rejected.');
        } catch (AiQualityComparisonCheckpointException $exception) {
            $this->assertSame('ai_quality_comparison_checkpoint_mismatch', $exception->getMessage());
        }

        $this->assertSame($before, File::get($basePath.'.partial.json'));
        $this->assertSame([], glob($basePath.'.partial.json.*.tmp') ?: []);
        File::deleteDirectory($directory);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function request(array $overrides = []): array
    {
        return array_replace([
            'article_ids' => [10],
            'knowledge_base_id' => 20,
            'requested_model_id' => null,
            'admin_id' => 40,
            'access_version' => 1,
            'policy_version' => 1,
            'repeat' => 1,
            'compare' => ['atomic', 'knowledge'],
        ], $overrides);
    }
}
