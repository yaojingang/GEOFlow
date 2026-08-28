<?php

namespace Tests\Unit\AiWorkspace;

use App\Models\Admin;
use App\Services\AiWorkspace\AdminHelpKnowledgeCatalog;
use App\Services\AiWorkspace\AdminHelpKnowledgeRetriever;
use App\Services\AiWorkspace\SystemKnowledgeBaseManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AdminHelpEvaluationDatasetTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_fixed_72_question_evaluation_dataset_is_valid_and_retrieves_expected_features(): void
    {
        $dataset = json_decode((string) file_get_contents(
            resource_path('knowledge/ai-workspace/evals/admin-help.zh_CN.json'),
        ), true, flags: JSON_THROW_ON_ERROR);
        $cases = $dataset['cases'] ?? [];

        self::assertCount(72, $cases);
        self::assertCount(72, array_unique(array_column($cases, 'id')));
        self::assertSame('zh_CN', $dataset['locale'] ?? null);

        app()->setLocale('zh_CN');
        $catalog = app(AdminHelpKnowledgeCatalog::class);
        foreach ($cases as $case) {
            $expectedFeature = $case['expected_feature_id'] ?? null;
            if (! is_string($expectedFeature)) {
                $admin = new Admin([
                    'role' => ($case['role'] ?? 'admin') === 'super_admin' ? 'super_admin' : 'admin',
                    'status' => 'active',
                ]);
                $matches = $catalog->search($admin, (string) $case['question'], 3);
                self::assertTrue(collect($matches)->every(
                    fn (array $entry): bool => ! collect($entry['keywords'] ?? [])->contains(
                        fn (mixed $keyword): bool => str_contains((string) $case['question'], (string) $keyword),
                    ),
                ), 'Boundary evaluation produced a direct feature match: '.$case['id']);

                continue;
            }

            $admin = new Admin([
                'role' => ($case['role'] ?? 'admin') === 'super_admin' ? 'super_admin' : 'admin',
                'status' => 'active',
            ]);
            $matches = collect($catalog->search($admin, (string) $case['question'], 3))->pluck('id')->all();

            self::assertContains($expectedFeature, $matches, 'Evaluation case failed: '.$case['id']);
        }
    }

    public function test_catalog_labels_are_available_in_every_supported_admin_locale(): void
    {
        $locales = ['zh_CN', 'en', 'es', 'ja', 'pt_BR', 'ru'];

        foreach ($locales as $locale) {
            app()->setLocale($locale);
            $entries = app(AdminHelpKnowledgeCatalog::class)->entries();

            self::assertNotEmpty($entries, $locale);
            self::assertTrue(collect($entries)->every(
                static fn (array $entry): bool => trim((string) $entry['name']) !== ''
                    && trim((string) $entry['description']) !== '',
            ), $locale);
        }
    }

    public function test_fixed_questions_retrieve_the_expected_feature_within_the_top_three_sections(): void
    {
        Queue::fake();
        Cache::flush();
        app()->setLocale('zh_CN');
        app(SystemKnowledgeBaseManager::class)->sync();
        $dataset = json_decode((string) file_get_contents(
            resource_path('knowledge/ai-workspace/evals/admin-help.zh_CN.json'),
        ), true, flags: JSON_THROW_ON_ERROR);
        $catalog = app(AdminHelpKnowledgeCatalog::class);
        $retriever = app(AdminHelpKnowledgeRetriever::class);
        $expectedCount = 0;
        $hitCount = 0;
        $misses = [];

        foreach ($dataset['cases'] ?? [] as $case) {
            $admin = new Admin([
                'id' => 900,
                'username' => 'evaluation-admin',
                'role' => ($case['role'] ?? 'admin') === 'super_admin' ? 'super_admin' : 'admin',
                'status' => 'active',
            ]);
            $question = (string) $case['question'];
            $result = $retriever->retrieve($admin, $question, $catalog->search($admin, $question));
            $topThreeFeatures = collect($result['sources'])
                ->take(3)
                ->pluck('feature_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
            $expectedFeature = $case['expected_feature_id'] ?? null;

            if (is_string($expectedFeature)) {
                $expectedCount++;
                if (in_array($expectedFeature, $topThreeFeatures, true)) {
                    $hitCount++;
                } else {
                    $misses[] = $case['id'].' '.json_encode(
                        collect($result['sources'])->take(3)->map(
                            static fn (array $source): array => collect($source)
                                ->only(['section_path', 'feature_id', 'score'])
                                ->all(),
                        )->all(),
                        JSON_UNESCAPED_UNICODE,
                    );
                }
            } else {
                self::assertSame([], $topThreeFeatures, 'Boundary case returned a feature: '.$case['id']);
            }
        }

        self::assertGreaterThan(0, $expectedCount);
        self::assertGreaterThanOrEqual(
            0.95,
            $hitCount / $expectedCount,
            'Top-3 retrieval misses: '.implode('; ', $misses),
        );
    }
}
