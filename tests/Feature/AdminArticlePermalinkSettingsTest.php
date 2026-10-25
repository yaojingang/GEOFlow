<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleSlugHistory;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Services\Site\UrlChangeService;
use App\Support\Site\ArticlePermalinkPolicy;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChecksUrlChanges;
use Tests\TestCase;

class AdminArticlePermalinkSettingsTest extends TestCase
{
    use ChecksUrlChanges;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareUrlQueue();
    }

    public function test_site_settings_show_the_current_policy_and_seven_presets(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.site-settings.index'));

        $response->assertOk()
            ->assertSee('自定义文章链接')
            ->assertSee('/article/{slug}')
            ->assertSee('分类短链（推荐）')
            ->assertSee('/{category}/{slug}')
            ->assertSee('路径层级请直接使用 / 分隔，令牌前后不要留空格。')
            ->assertSee('检查影响');
        $this->assertCount(7, $response->viewData('articlePermalinkPresets'));
    }

    public function test_permalink_card_opens_an_independent_expandable_panel(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.site-settings.index'));

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $panel = $xpath->query('//details[@id="site-settings-permalink"]')->item(0);

        $this->assertInstanceOf(\DOMElement::class, $panel);
        $this->assertSame(0, $xpath->query('ancestor::details', $panel)->length);
        $this->assertSame(
            1,
            $xpath->query('//a[@href="#site-settings-permalink" and @data-site-settings-target="site-settings-permalink"]')->length,
        );
        $this->assertSame(
            '自定义文章链接',
            trim((string) $xpath->query('./summary//h3', $panel)->item(0)?->textContent),
        );
    }

    public function test_permalink_settings_and_validation_have_english_copy(): void
    {
        app()->setLocale('en');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->withSession(['locale' => 'en'])
            ->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertSee('Custom article links')
            ->assertSee('Category short URL (recommended)')
            ->assertSee('Separate path levels directly with /. Do not put spaces before or after tokens.')
            ->assertSee('Check impact')
            ->assertDontSee('article_permalink.');

        $this->actingAs($admin, 'admin')
            ->withSession(['locale' => 'en'])
            ->post(route('admin.site-settings.article-permalink.preview'), [
                'pattern' => '/api/{slug}.html',
            ])
            ->assertSessionHasErrors([
                'pattern' => 'The permalink template conflicts with the reserved /api entry.',
            ]);
    }

    public function test_whitespace_validation_has_english_copy(): void
    {
        app()->setLocale('en');

        $this->actingAs($this->admin(), 'admin')
            ->withSession(['locale' => 'en'])
            ->post(route('admin.site-settings.article-permalink.preview'), [
                'pattern' => '/{category} /{slug}',
            ])
            ->assertSessionHasErrors([
                'pattern' => 'The template contains whitespace, such as a space, tab, or line break. Remove it and separate path levels directly with /. For a category and article path, enter /{category}/{slug}.',
            ]);
    }

    public function test_only_super_admins_can_preview_or_activate_a_policy(): void
    {
        $regular = $this->admin('admin');

        $this->actingAs($regular, 'admin')
            ->post(route('admin.site-settings.article-permalink.preview'), [
                'pattern' => '/{slug}.html',
            ])
            ->assertForbidden();
    }

    public function test_preview_credential_is_required_and_revision_checked_before_activation(): void
    {
        $admin = $this->admin();
        $this->article();
        $this->actingAs($admin, 'admin')->post(route('admin.site-settings.article-permalink.preview'), ['pattern' => '/news/{year}/{slug}.html'])->assertSessionHasNoErrors();
        $change = $this->checkedChange();
        $this->assertSame(1, $change->summary['changed_urls']);
        $credential = app(UrlChangeService::class)->credential($change);
        $this->post(route('admin.site-settings.article-permalink.activate'), ['preview_credential' => $credential])->assertSessionHasErrors('pattern');
        $this->post(route('admin.site-settings.article-permalink.activate'), ['change_id' => $change->id, 'credential' => $credential, 'confirmation' => '确认修改文章链接'])->assertSessionHasNoErrors()->assertRedirect(route('admin.url-changes.show', $change));
        $policy = ArticlePermalinkPolicy::fromRaw(SiteSetting::query()->where('setting_key', ArticlePermalinkPolicy::SETTING_KEY)->value('setting_value'));
        $this->assertSame(1, $policy->revision);
        $this->assertSame('/news/{year}/{slug}.html', $policy->currentPattern);
        $this->post(route('admin.site-settings.article-permalink.activate'), ['change_id' => $change->id, 'credential' => $credential, 'confirmation' => '确认修改文章链接'])->assertSessionHasNoErrors();
        $this->assertSame(1, ArticlePermalinkPolicy::fromRaw(SiteSetting::query()->where('setting_key', ArticlePermalinkPolicy::SETTING_KEY)->value('setting_value'))->revision);
    }

    public function test_root_category_id_pattern_can_be_previewed(): void
    {
        $this->article();
        $response = $this->actingAs($this->admin(), 'admin')->post(route('admin.site-settings.article-permalink.preview'), ['pattern' => '/{category}/{id}'])->assertSessionHasNoErrors();
        $change = $this->checkedChange();
        $response->assertRedirect(route('admin.url-changes.show', $change));
        $this->assertSame('/{category}/{id}', $change->new_value);
        $this->assertSame(1, $change->summary['changed_urls']);
    }

    public function test_whitespace_in_a_permalink_template_returns_an_actionable_error(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.site-settings.article-permalink.preview'), [
                'pattern' => '/{category} /{slug}',
            ])
            ->assertSessionHasErrors([
                'pattern' => '模板中包含空白字符（空格、制表符或换行）。请删除多余空白，路径层级直接用 / 分隔；分类与文章两层地址请填写 /{category}/{slug}。',
            ])
            ->assertSessionMissing('article_permalink_preview');

        $page = $this->get(route('admin.site-settings.index'));
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        $panel = $xpath->query('//details[@id="site-settings-permalink"]')->item(0);
        $input = $xpath->query('//input[@id="article-permalink-pattern"]')->item(0);
        $alert = $xpath->query('//*[@id="article-permalink-error" and @role="alert"]')->item(0);

        $this->assertInstanceOf(\DOMElement::class, $panel);
        $this->assertTrue($panel->hasAttribute('open'));
        $this->assertInstanceOf(\DOMElement::class, $input);
        $this->assertSame('true', $input->getAttribute('aria-invalid'));
        $this->assertStringContainsString('article-permalink-error', $input->getAttribute('aria-describedby'));
        $this->assertInstanceOf(\DOMElement::class, $alert);
        $this->assertStringContainsString('分类与文章两层地址请填写 /{category}/{slug}', trim($alert->textContent));
    }

    public function test_reserved_existing_category_blocks_a_root_category_pattern(): void
    {
        DB::table('categories')->insert(['name' => 'Legacy API Category', 'slug' => 'api', 'description' => '', 'sort_order' => 0, 'created_at' => now()]);
        $this->actingAs($this->admin(), 'admin')->post(route('admin.site-settings.article-permalink.preview'), ['pattern' => '/{category}/{id}'])->assertSessionHasNoErrors();
        $change = $this->checkedChange('failed');
        $this->assertStringContainsString('分类 slug api 占用了保留入口 /api', $change->error);
        $this->assertNull($change->nonce_hash);
    }

    public function test_invalid_patterns_do_not_receive_a_preview_credential(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->from(route('admin.site-settings.index'))
            ->post(route('admin.site-settings.article-permalink.preview'), [
                'pattern' => '/api/{slug}.html',
            ])
            ->assertRedirect(route('admin.site-settings.index'))
            ->assertSessionHasErrors('pattern')
            ->assertSessionMissing('article_permalink_preview');
    }

    public function test_existing_slug_outside_the_new_write_contract_blocks_activation_preview(): void
    {
        $article = $this->article();
        $article->forceFill(['slug' => str_repeat('a', 256)])->save();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.site-settings.article-permalink.preview'), [
                'pattern' => '/{slug}.html',
            ])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->checkedChange('failed')->nonce_hash);
    }

    public function test_historical_slug_collision_with_an_id_pattern_blocks_activation_preview(): void
    {
        $first = $this->article();
        $second = $this->articleWithSlug('second-article');
        ArticleSlugHistory::query()->create([
            'article_id' => $second->id,
            'slug' => $first->id.'.html',
            'created_at' => now(),
            'last_used_at' => now(),
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.site-settings.article-permalink.preview'), [
                'pattern' => '/article/{id}.html',
            ])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->checkedChange('failed')->nonce_hash);
    }

    public function test_admin_prefix_change_cannot_overlap_the_active_permalink_policy(): void
    {
        $policy = ArticlePermalinkPolicy::defaults()->activate('/control-room/{slug}.html');
        SiteSetting::query()->create([
            'setting_key' => ArticlePermalinkPolicy::SETTING_KEY,
            'setting_value' => json_encode($policy->toArray(), JSON_THROW_ON_ERROR),
        ]);
        SiteSettingsBag::forget();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.site-settings.update'), [
                'site_name' => 'GEOFlow',
                'admin_base_path' => 'control-room',
            ])
            ->assertSessionHasErrors('admin_base_path');
    }

    public function test_admin_prefix_change_cannot_overlap_a_root_category_slug(): void
    {
        $policy = ArticlePermalinkPolicy::defaults()->activate('/{category}/{id}');
        SiteSetting::query()->create([
            'setting_key' => ArticlePermalinkPolicy::SETTING_KEY,
            'setting_value' => json_encode($policy->toArray(), JSON_THROW_ON_ERROR),
        ]);
        Category::query()->create([
            'name' => 'Control Room',
            'slug' => 'control-room',
        ]);
        SiteSettingsBag::forget();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.site-settings.update'), [
                'site_name' => 'GEOFlow',
                'admin_base_path' => 'control-room',
            ])
            ->assertSessionHasErrors('admin_base_path');
    }

    public function test_migration_map_is_downloadable_as_csv(): void
    {
        $article = $this->article();
        $article->update(['title' => "\n=2+2"]);
        ArticleSlugHistory::query()->create(['article_id' => $article->id, 'slug' => 'retired-preview-slug', 'created_at' => now(), 'last_used_at' => now()]);
        $this->actingAs($this->admin(), 'admin')->post(route('admin.site-settings.article-permalink.preview'), ['pattern' => '/{slug}.html'])->assertSessionHasNoErrors();
        $change = $this->checkedChange();
        $this->get(route('admin.site-settings.article-permalink.migration-map', ['change_id' => $change->id]))->assertRedirect(route('admin.url-changes.download', $change));
        $csv = $this->get(route('admin.url-changes.download', $change))->assertOk()->assertDownload('url-migration-'.$change->id.'-1.csv')->streamedContent();
        $this->assertStringContainsString('/article/'.$article->slug, $csv);
        $this->assertStringContainsString("'\n=2+2", $csv);
        $this->assertStringContainsString('currently_public', $csv);
        $this->assertStringNotContainsString("\n=2+2,", $csv);
    }

    private function admin(string $role = 'super_admin'): Admin
    {
        return Admin::query()->create([
            'username' => 'permalink_'.$role,
            'password' => 'secret-123',
            'email' => $role.'-permalink@example.test',
            'display_name' => 'Permalink Admin',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function article(): Article
    {
        $category = Category::query()->create(['name' => 'AI', 'slug' => 'ai']);
        $author = Author::query()->create(['name' => 'GEOFlow']);

        return Article::query()->create([
            'title' => 'Preview article',
            'slug' => 'preview-article',
            'content' => 'Body',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
    }

    private function articleWithSlug(string $slug): Article
    {
        $category = Category::query()->firstOrCreate(['slug' => 'ai'], ['name' => 'AI']);
        $author = Author::query()->firstOrCreate(['name' => 'GEOFlow']);

        return Article::query()->create([
            'title' => 'Article '.$slug,
            'slug' => $slug,
            'content' => 'Body',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
    }
}
