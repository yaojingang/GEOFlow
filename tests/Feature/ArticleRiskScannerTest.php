<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleRiskScan;
use App\Models\Author;
use App\Models\Category;
use App\Models\SensitiveWord;
use App\Services\GeoFlow\ArticleRiskScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ArticleRiskScannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_it_returns_a_clean_result_when_no_active_rule_matches(): void
    {
        $rule = SensitiveWord::query()->create(['word' => '[danger]']);

        $result = $this->scanner()->scan([
            'title' => 'A safe title',
            'excerpt' => 'A safe excerpt',
            'content' => 'The word danger has no literal brackets.',
            'keywords' => 'safe,article',
            'meta_description' => 'A safe description',
        ]);

        $this->assertSame('warning', $rule->severity);
        $this->assertSame('sensitive', $rule->category);
        $this->assertTrue($rule->is_enabled);
        $this->assertSame('clean', $result['status']);
        $this->assertSame(0, $result['match_count']);
        $this->assertSame([], $result['matches']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['content_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['dictionary_hash']);
    }

    public function test_it_matches_unicode_case_insensitively_across_supported_fields(): void
    {
        SensitiveWord::query()->create([
            'word' => 'MÜNCHEN',
            'category' => 'location',
            'suggestion' => 'Use a broader location.',
        ]);

        $result = $this->scanner()->scan([
            'title' => 'A guide to münchen',
            'excerpt' => 'München and MÜNCHEN highlights',
            'content' => 'No match here.',
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertSame('warning', $result['status']);
        $this->assertSame(3, $result['match_count']);
        $this->assertSame(['title', 'excerpt'], array_column($result['matches'], 'field'));
        $this->assertSame([1, 2], array_column($result['matches'], 'count'));
        $this->assertSame('MÜNCHEN', $result['matches'][0]['word']);
        $this->assertSame('warning', $result['matches'][0]['severity']);
        $this->assertSame('location', $result['matches'][0]['category']);
        $this->assertSame('Use a broader location.', $result['matches'][0]['suggestion']);
        $this->assertStringContainsString('münchen', mb_strtolower($result['matches'][0]['snippet']));
    }

    public function test_unicode_case_fold_expansion_does_not_shift_the_match_out_of_the_snippet(): void
    {
        SensitiveWord::query()->create(['word' => 'TARGET']);
        $content = str_repeat('ß ', 80).'TARGET '.str_repeat('tail ', 30);

        $result = $this->scanner()->scan(['content' => $content]);

        $this->assertSame(1, $result['match_count']);
        $this->assertStringContainsString('TARGET', $result['matches'][0]['snippet']);
    }

    public function test_snippet_maps_a_folded_ss_match_back_to_the_original_sharp_s(): void
    {
        SensitiveWord::query()->create(['word' => 'SS']);
        $content = str_repeat('前', 130).'Straße '.str_repeat('后', 30);

        $result = $this->scanner()->scan(['content' => $content]);

        $this->assertSame(1, $result['match_count']);
        $this->assertStringContainsString('Straße', $result['matches'][0]['snippet']);
    }

    public function test_it_ignores_disabled_rules(): void
    {
        SensitiveWord::query()->create([
            'word' => 'hidden term',
            'is_enabled' => false,
            'severity' => 'blocked',
        ]);

        $result = $this->scanner()->scan(['content' => 'This contains a hidden term.']);

        $this->assertSame('clean', $result['status']);
        $this->assertSame(0, $result['match_count']);
        $this->assertSame([], $result['matches']);
    }

    public function test_it_limits_a_rule_to_its_applies_to_fields(): void
    {
        SensitiveWord::query()->create([
            'word' => 'private',
            'applies_to' => ['title'],
        ]);

        $result = $this->scanner()->scan([
            'title' => 'Private notice',
            'content' => 'private private',
        ]);

        $this->assertSame(1, $result['match_count']);
        $this->assertCount(1, $result['matches']);
        $this->assertSame('title', $result['matches'][0]['field']);
    }

    public function test_blocking_matches_win_over_warning_matches(): void
    {
        SensitiveWord::query()->create(['word' => 'caution']);
        SensitiveWord::query()->create([
            'word' => 'prohibited',
            'severity' => 'blocked',
        ]);

        $result = $this->scanner()->scan([
            'content' => 'Use caution because this is prohibited.',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(2, $result['match_count']);
        $this->assertSame(['warning', 'blocked'], array_column($result['matches'], 'severity'));
    }

    public function test_it_scans_the_visible_text_rendered_from_markdown_and_html_entities(): void
    {
        SensitiveWord::query()->create([
            'word' => '赌博',
            'severity' => 'blocked',
        ]);

        $markdownResult = $this->scanner()->scan(['content' => '赌**博**']);
        $entityResult = $this->scanner()->scan(['content' => '赌&#x535A;']);

        $this->assertSame('blocked', $markdownResult['status']);
        $this->assertSame(1, $markdownResult['match_count']);
        $this->assertSame('blocked', $entityResult['status']);
        $this->assertSame(1, $entityResult['match_count']);
    }

    public function test_it_removes_invisible_unicode_characters_before_matching(): void
    {
        SensitiveWord::query()->create([
            'word' => '赌博',
            'severity' => 'blocked',
        ]);

        $result = $this->scanner()->scan(['content' => "赌\u{200B}博"]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(1, $result['match_count']);
        $this->assertStringContainsString('赌博', $result['matches'][0]['snippet']);
    }

    public function test_it_removes_unicode_default_ignorable_code_points_before_matching(): void
    {
        SensitiveWord::query()->create([
            'word' => '赌博',
            'severity' => 'blocked',
        ]);

        foreach (["\u{E0061}", "\u{1BCA0}"] as $invisibleCharacter) {
            $result = $this->scanner()->scan(['content' => '赌'.$invisibleCharacter.'博']);

            $this->assertSame('blocked', $result['status']);
            $this->assertSame(1, $result['match_count']);
        }
    }

    public function test_it_normalizes_unicode_compatibility_characters_before_matching(): void
    {
        SensitiveWord::query()->create([
            'word' => 'casino',
            'severity' => 'blocked',
        ]);

        $result = $this->scanner()->scan(['content' => '𝐜𝐚𝐬𝐢𝐧𝐨']);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(1, $result['match_count']);
    }

    public function test_blocked_ascii_rule_cannot_be_bypassed_with_visible_separators(): void
    {
        SensitiveWord::query()->create([
            'word' => 'casino',
            'severity' => 'blocked',
        ]);

        $result = $this->scanner()->scan(['content' => 'Visit c a.s-i_n o today.']);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(1, $result['match_count']);
        $this->assertStringContainsString('c a.s-i_n o', $result['matches'][0]['snippet']);
    }

    public function test_blocked_cjk_rule_cannot_be_bypassed_with_visible_punctuation(): void
    {
        SensitiveWord::query()->create([
            'word' => '赌博',
            'severity' => 'blocked',
        ]);

        $result = $this->scanner()->scan(['content' => '禁止赌·博推广。']);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(1, $result['match_count']);
        $this->assertStringContainsString('赌·博', $result['matches'][0]['snippet']);
    }

    public function test_warning_rule_keeps_literal_separator_matching_to_limit_false_positives(): void
    {
        SensitiveWord::query()->create(['word' => 'casino']);

        $result = $this->scanner()->scan(['content' => 'A product code: c-a-s-i-n-o.']);

        $this->assertSame('clean', $result['status']);
        $this->assertSame(0, $result['match_count']);
    }

    public function test_visible_text_surface_does_not_double_count_a_literal_match(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);

        $result = $this->scanner()->scan(['content' => 'Please review me.']);

        $this->assertSame(1, $result['match_count']);
    }

    public function test_dictionary_hash_includes_the_scanner_algorithm_version(): void
    {
        $result = $this->scanner()->scan(['content' => 'Safe content.']);
        $legacyRulesOnlyHash = hash('sha256', json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->assertNotSame($legacyRulesOnlyHash, $result['dictionary_hash']);
    }

    public function test_it_rejects_content_above_the_synchronous_scan_limit(): void
    {
        $this->expectException(\LengthException::class);

        $this->scanner()->scan([
            'content' => str_repeat('x', ArticleRiskScanner::MAX_CONTENT_CHARACTERS + 1),
        ]);
    }

    public function test_it_persists_a_scan_with_relations_and_casts(): void
    {
        SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle(['content' => 'Please review me.']);
        $admin = Admin::query()->create([
            'username' => 'risk-reviewer',
            'password' => 'secret-123',
            'email' => 'risk-reviewer@example.com',
            'display_name' => 'Risk Reviewer',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $scan = $this->scanner()->record($article, 'manual', $admin->id);

        $this->assertModelExists($scan);
        $this->assertInstanceOf(ArticleRiskScan::class, $scan);
        $this->assertSame('warning', $scan->status);
        $this->assertSame(1, $scan->match_count);
        $this->assertIsArray($scan->matches);
        $this->assertNotNull($scan->scanned_at);
        $this->assertTrue($scan->article->is($article));
        $this->assertTrue($scan->admin->is($admin));
        $this->assertTrue($article->fresh()->riskScans->contains($scan));
        $this->assertTrue($article->fresh()->latestRiskScan->is($scan));
    }

    public function test_a_recorded_scan_is_not_fresh_after_article_content_changes(): void
    {
        $article = $this->createArticle();
        $scanner = $this->scanner();
        $scan = $scanner->record($article, 'save');

        $article->update(['content' => 'Changed article content.']);

        $this->assertFalse($scanner->isFresh($article->fresh(), $scan));
    }

    public function test_a_scan_is_not_fresh_for_another_article_with_identical_content(): void
    {
        $firstArticle = $this->createArticle();
        $secondArticle = $this->createArticle();
        $scanner = $this->scanner();
        $scan = $scanner->record($firstArticle, 'save');

        $this->assertFalse($scanner->isFresh($secondArticle, $scan));
    }

    public function test_dictionary_changes_automatically_make_a_scan_stale(): void
    {
        $rule = SensitiveWord::query()->create(['word' => 'review me']);
        $article = $this->createArticle(['content' => 'Please review me.']);
        $scanner = $this->scanner();
        $scan = $scanner->record($article, 'save');

        $rule->update(['severity' => 'blocked']);

        $this->assertFalse($scanner->isFresh($article, $scan));
    }

    private function scanner(): ArticleRiskScanner
    {
        return new ArticleRiskScanner;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createArticle(array $attributes = []): Article
    {
        $category = Category::query()->create([
            'name' => 'Risk checks',
            'slug' => 'risk-checks-'.uniqid(),
        ]);
        $author = Author::query()->create([
            'name' => 'Risk Author',
            'email' => uniqid().'@example.com',
        ]);

        return Article::query()->create(array_merge([
            'title' => 'Article title',
            'slug' => 'article-'.uniqid(),
            'excerpt' => 'Article excerpt',
            'content' => 'Original article content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'keywords' => 'article,risk',
            'meta_description' => 'Article meta description',
        ], $attributes));
    }
}
