<?php

namespace Tests\Feature\Topics;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Services\Topics\TopicGenerationService;
use App\Services\Topics\TopicService;
use App\Services\Topics\TopicTaskService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TopicMatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanning_reaches_articles_beyond_two_hundred_without_per_article_queries(): void
    {
        for ($i = 0; $i < 205; $i++) {
            $this->article('Other source '.$i, ['content' => 'Unrelated unique body '.$i]);
        }
        $later = $this->article('GEO practical guide');
        DB::enableQueryLog();
        $selection = app(TopicGenerationService::class)->candidateSearch('primary', 'GEO');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([$later->id], array_column($selection['candidates'], 'article_id'));
        $this->assertSame(206, $selection['report']['scanned_count']);
        $this->assertTrue($selection['report']['scan_complete']);
        $this->assertLessThan(30, count($queries), 'Matching must eager-load eligibility instead of querying each article.');
    }

    public function test_related_or_required_group_and_and_keyword_exclusion_apply_to_declared_fields(): void
    {
        $related = $this->article('A tutorial case', ['keywords' => 'optimization']);
        $missing = $this->article('GEO tutorial only');
        $excluded = $this->article('GEO guide example', ['keywords' => 'advert', 'content' => 'Paid advert']);
        $body = $this->article('A practical case', ['content' => 'Optimization manual with examples']);
        $rules = ['related_terms' => ['optimization'], 'required_groups' => [['tutorial', 'manual'], ['case', 'examples']], 'excluded_terms' => ['advert']];
        $result = app(TopicGenerationService::class)->candidateSearch('primary', 'GEO', ['matching_rules' => $rules]);
        $this->assertEqualsCanonicalizing([$related->id, $body->id], array_column($result['candidates'], 'article_id'));
        $this->assertSame(1, $result['report']['excluded_term_count']);
        $this->assertSame(1, $result['report']['required_group_miss_count']);
        $this->assertNotContains($missing->id, array_column($result['candidates'], 'article_id'));
        $this->assertNotContains($excluded->id, array_column($result['candidates'], 'article_id'));
    }

    public function test_weighted_hits_accumulate_without_dilution_and_late_body_matches_reach_ai(): void
    {
        $title = $this->article('GEO guide');
        $keyword = $this->article('Keyword guide', ['keywords' => 'GEO']);
        $body = $this->article('Long guide', ['content' => str_repeat('a', 1800).' GEO factual material']);
        $service = app(TopicGenerationService::class);
        $plain = $service->candidateSearch('primary', 'GEO');
        $expanded = $service->candidateSearch('primary', 'GEO', ['matching_rules' => ['related_terms' => ['absentword']]]);
        $this->assertSame([$title->id, $keyword->id, $body->id], array_column($plain['candidates'], 'article_id'));
        $this->assertSame([6, 3, 1], array_column($plain['candidates'], 'relevance'));
        $this->assertSame([6, 3, 1], array_column($expanded['candidates'], 'relevance'));
        $this->assertStringContainsString('GEO factual material', $plain['candidates'][2]['content']);
        $this->assertSame([['term' => 'geo', 'field' => 'body', 'weight' => 1]], $plain['candidates'][2]['hits']);
    }

    public function test_candidate_and_ai_limits_record_real_scan_counts_and_safe_hit_explanations(): void
    {
        $sources = [];
        for ($i = 0; $i < 105; $i++) {
            $sources[] = $this->article('GEO source '.$i);
        }
        $this->article('GEO duplicate', ['content' => $sources[0]->content]);
        $actor = $this->actor();
        $run = app(TopicGenerationService::class)->prepare($actor, 'primary', ['title' => 'GEO', 'model_id' => $this->model($actor)->id, 'after' => 'draft_only'], (string) Str::uuid());
        $composeCalls = 0;
        MarkdownContentWriterAgent::fake(function (string $prompt) use (&$composeCalls): string {
            $data = json_decode($prompt, true);
            if (($data['phase'] ?? null) === 'verify') {
                return json_encode(['supported' => true, 'unsupported_fields' => []]);
            }
            $composeCalls++;
            $this->assertCount(40, $data['source_articles']);
            $selected = array_slice($data['source_articles'], 0, 2);

            return json_encode(['intro' => 'Source guide', 'articles' => array_map(fn ($row) => ['article_id' => $row['article_id']], $selected)]);
        })->preventStrayPrompts();
        $done = app(TopicGenerationService::class)->process($run->id);
        $this->assertSame('completed', $done->status);
        $this->assertSame(1, $composeCalls);
        $report = collect($done->telemetry)->firstWhere('kind', 'matching')['report'];
        $this->assertSame(106, $report['scanned_count']);
        $this->assertSame(105, $report['distinct_count']);
        $this->assertSame(100, $report['candidate_count']);
        $this->assertSame(40, $report['ai_count']);
        $this->assertTrue($report['truncated']);
        $this->assertTrue($report['ai_truncated']);
        $this->assertCount(100, $report['matches']);
        $status = $this->actingAs($actor, 'admin')->getJson(route('admin.topics.runs.status', ['run' => $run->id]));
        $status->assertOk()->assertJsonPath('matching_report.scanned_count', 106)->assertJsonPath('matching_report.ai_count', 40);
        $this->assertStringNotContainsString('source_hash', $status->getContent());
        $this->assertStringNotContainsString('body_hash', $status->getContent());
        $this->get(route('admin.topics.runs.show', ['run' => $run->id]))->assertOk()->assertSee('文章匹配记录')->assertSee('查看候选与命中解释');
    }

    public function test_declared_category_date_and_permanent_exclusions_bound_candidate_scanning(): void
    {
        $wanted = $this->article('GEO wanted');
        $old = $this->article('GEO old', ['published_at' => now()->subYears(2)]);
        $excluded = $this->article('GEO excluded');
        $category = Category::query()->create(['name' => 'Other', 'slug' => 'other']);
        $this->article('GEO other category', ['category_id' => $category->id]);
        $selection = app(TopicGenerationService::class)->candidateSearch('primary', 'GEO', ['category_ids' => [$wanted->category_id], 'after' => now()->subDay()->toDateString(), 'before' => now()->toDateString(), 'excluded_article_ids' => [$excluded->id]]);
        $this->assertSame([$wanted->id], array_column($selection['candidates'], 'article_id'));
        $this->assertSame(1, $selection['report']['scanned_count']);
        $this->assertNotContains($old->id, array_column($selection['candidates'], 'article_id'));
    }

    public function test_editor_and_paused_task_parse_friendly_rules_and_share_validation(): void
    {
        $actor = $this->actor();
        $text = ['related_terms_text' => 'GEO，Optimization', 'required_groups_text' => "tutorial | manual\ncase, examples", 'excluded_terms_text' => 'advert'];
        $expected = ['version' => 1, 'related_terms' => ['geo', 'optimization'], 'required_groups' => [['tutorial', 'manual'], ['case', 'examples']], 'excluded_terms' => ['advert']];
        $task = app(TopicTaskService::class)->save(['name' => 'Paused matching', 'target_site_key' => 'primary', 'topic_limit' => 10, 'publish_interval' => 3600, 'topic_settings' => ['matching_rules' => $text]], $actor);
        $this->assertSame('paused', $task->status);
        $this->assertSame($expected, $task->topic_settings['matching_rules']);
        $topic = app(TopicService::class)->create('primary', ['title' => 'GEO']);
        $this->actingAs($actor, 'admin')->put(route('admin.topics.update', ['topic' => $topic->id]), ['site' => 'primary', 'title' => 'GEO', 'template_key' => 'default', 'expected_version' => 1, 'matching_rules' => $text])->assertRedirect();
        $this->assertSame($expected, $topic->fresh()->draft_payload['matching_rules']);
        $this->get(route('admin.topics.edit', ['topic' => $topic->id]))->assertOk()->assertSee('文章匹配规则')->assertSee('optimization', false);
    }

    public function test_running_rule_snapshot_does_not_replace_later_manual_rules(): void
    {
        $actor = $this->actor();
        $sources = [$this->article('Optimization tutorial one'), $this->article('Optimization tutorial two')];
        $service = app(TopicService::class);
        $topic = $service->create('primary', ['title' => 'GEO', 'intro' => 'Manual', 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources), 'matching_rules' => ['related_terms' => ['optimization']]]);
        $generation = app(TopicGenerationService::class);
        $run = $generation->prepare($actor, 'primary', ['title' => 'GEO', 'model_id' => $this->model($actor)->id, 'after' => 'draft_only', 'suggestion_only' => true], (string) Str::uuid(), $topic);
        $service->save($topic, ['matching_rules' => ['related_terms' => ['new manual rule']]], 1);
        MarkdownContentWriterAgent::fake([json_encode(['intro' => 'Suggested guide', 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources)]), json_encode(['supported' => true, 'unsupported_fields' => []])])->preventStrayPrompts();
        $done = $generation->process($run->id);
        $this->assertSame('needs_adoption', $done->status);
        $this->assertSame(['optimization'], collect($done->telemetry)->firstWhere('kind', 'matching')['report']['rules']['related_terms']);
        $generation->adopt($done, $topic->fresh(), 2, ['intro']);
        $this->assertSame(['new manual rule'], $topic->fresh()->draft_payload['matching_rules']['related_terms']);
        $this->assertSame('Suggested guide', $topic->fresh()->draft_payload['intro']);
    }

    private function actor(): Admin
    {
        return Admin::query()->firstOrCreate(['username' => 'matching_admin'], ['password' => 'password', 'email' => 'matching@test.local', 'role' => 'super_admin', 'status' => 'active']);
    }

    private function model(Admin $actor): AiModel
    {
        $model = AiModel::query()->create(['name' => 'Matching model', 'version' => 'test', 'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'), 'model_id' => 'test-model', 'model_type' => 'chat', 'api_url' => 'https://ai.test', 'daily_limit' => 100, 'status' => 'active']);
        $model->forceFill(['owner_admin_id' => $actor->id, 'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT])->save();

        return $model;
    }

    private function article(string $title, array $attributes = []): Article
    {
        $category = Category::query()->firstOrCreate(['slug' => 'matching'], ['name' => 'Matching']);
        $author = Author::query()->firstOrCreate(['email' => 'matching@test.local'], ['name' => 'Editor']);

        return Article::query()->create(array_replace(['title' => $title, 'slug' => Str::random(15), 'content' => 'Unique sourced body '.Str::random(20), 'excerpt' => 'Source summary', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now()], $attributes));
    }
}
