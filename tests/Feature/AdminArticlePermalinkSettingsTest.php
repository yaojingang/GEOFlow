<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleSlugHistory;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Support\Site\ArticlePermalinkPolicy;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminArticlePermalinkSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_settings_show_the_current_policy_and_six_presets(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.site-settings.index'));

        $response->assertOk()
            ->assertSee('文章固定链接')
            ->assertSee('/article/{slug}')
            ->assertSee('检查并预览');
        $this->assertCount(6, $response->viewData('articlePermalinkPresets'));
    }

    public function test_permalink_settings_and_validation_have_english_copy(): void
    {
        app()->setLocale('en');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->withSession(['locale' => 'en'])
            ->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertSee('Article permalinks')
            ->assertSee('Check and preview')
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

        $previewResponse = $this->actingAs($admin, 'admin')
            ->from(route('admin.site-settings.index'))
            ->post(route('admin.site-settings.article-permalink.preview'), [
                'pattern' => '/news/{year}/{slug}.html',
            ])
            ->assertRedirect(route('admin.site-settings.index'))
            ->assertSessionHas('article_permalink_preview');

        $preview = $previewResponse->getSession()->get('article_permalink_preview');
        $this->assertSame('/news/{year}/{slug}.html', $preview['pattern']);
        $this->assertSame(1, $preview['affected_articles']);
        $this->assertNotEmpty($preview['credential']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.article-permalink.activate'), [
                'preview_credential' => $preview['credential'],
            ])
            ->assertRedirect(route('admin.site-settings.index'))
            ->assertSessionHasNoErrors();

        $policy = ArticlePermalinkPolicy::fromRaw(SiteSetting::query()
            ->where('setting_key', ArticlePermalinkPolicy::SETTING_KEY)
            ->value('setting_value'));
        $this->assertSame(1, $policy->revision);
        $this->assertSame('/news/{year}/{slug}.html', $policy->currentPattern);
        $this->assertSame(ArticlePermalinkPolicy::DEFAULT_PATTERN, $policy->history[0]['pattern']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.article-permalink.activate'), [
                'preview_credential' => $preview['credential'],
            ])
            ->assertSessionHasErrors('pattern');
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
            ->assertSessionHasErrors('pattern')
            ->assertSessionMissing('article_permalink_preview');
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
            ->assertSessionHasErrors('pattern')
            ->assertSessionMissing('article_permalink_preview');
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

    public function test_migration_map_is_downloadable_as_csv(): void
    {
        $article = $this->article();
        $article->update(['title' => "\n=2+2"]);
        ArticleSlugHistory::query()->create([
            'article_id' => $article->id,
            'slug' => 'retired-preview-slug',
            'created_at' => now(),
            'last_used_at' => now(),
        ]);
        $admin = $this->admin();
        $previewResponse = $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.article-permalink.preview'), [
                'pattern' => '/{slug}.html',
            ])
            ->assertSessionHas('article_permalink_preview');
        $preview = $previewResponse->getSession()->get('article_permalink_preview');

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.article-permalink.migration-map', [
                'preview_credential' => $preview['credential'],
            ]))
            ->assertOk()
            ->assertDownload('article-url-migration.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('stale_slug', $csv);
        $this->assertStringContainsString('/article/retired-preview-slug', $csv);
        $this->assertStringContainsString("'\n=2+2", $csv);
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
