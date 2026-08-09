<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\LeadForm;
use App\Models\SiteSetting;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnterpriseSignatureThemeTest extends TestCase
{
    use RefreshDatabase;

    private const THEME_ID = 'geoflow-template-21-enterprise-signature';

    public function test_theme_is_discovered_and_uses_only_solid_backgrounds(): void
    {
        $theme = collect(app(SiteThemeCatalog::class)->all())
            ->firstWhere('id', self::THEME_ID);

        $this->assertIsArray($theme);
        $this->assertSame('GEOFlow 21 Enterprise Signature', $theme['name']);
        $this->assertFileExists(resource_path('views/theme/'.self::THEME_ID.'/layout.blade.php'));
        $this->assertFileExists(public_path('themes/'.self::THEME_ID.'/theme.css'));

        $css = (string) file_get_contents(public_path('themes/'.self::THEME_ID.'/theme.css'));

        $this->assertStringNotContainsString('gradient', strtolower($css));
        $this->assertStringContainsString('--ent-white: #ffffff', $css);
        $this->assertStringContainsString('background: var(--ent-white)', $css);

        $design = json_decode(
            (string) file_get_contents(resource_path('views/theme/'.self::THEME_ID.'/homepage-design.json')),
            true
        );
        $metricModule = collect($design['modules'] ?? [])->firstWhere('type', 'metric_band');

        $this->assertIsArray($metricModule);
        $this->assertSame('以下均为演示数据', $metricModule['subtitle']);
        $this->assertStringNotContainsString('|演示数据', $metricModule['body']);
    }

    public function test_theme_is_published_with_about_and_archive_pages(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(resource_path('views/theme/'.self::THEME_ID.'/manifest.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('published', $manifest['session_state'] ?? null);
        $this->assertFalse($manifest['requires_admin_activation'] ?? true);
        $this->assertEqualsCanonicalizing(
            ['home', 'category', 'article', 'archive-index', 'archive-month', 'about'],
            $manifest['compatible_pages'] ?? [],
        );
        $this->assertContains('/about', $manifest['preview_routes'] ?? []);
        $this->assertContains('/archive', $manifest['preview_routes'] ?? []);
        $this->assertContains('/archive/{year}/{month}', $manifest['preview_routes'] ?? []);
    }

    public function test_homepage_visual_rules_avoid_forced_title_breaks_and_dark_feature_panels(): void
    {
        $css = (string) file_get_contents(public_path('themes/'.self::THEME_ID.'/theme.css'));
        $view = (string) file_get_contents(resource_path('views/theme/'.self::THEME_ID.'/home.blade.php'));

        $this->assertMatchesRegularExpression(
            '/\.ent-hero::before\s*\{\s*display:\s*none;/',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.ent-(?:code-card|case-card__visual|tab-list button\[aria-selected="true"\])\s*\{[^}]*background:\s*var\(--ent-ink\)/s',
            $css
        );
        $this->assertStringContainsString(
            'grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));',
            $css
        );
        $this->assertStringNotContainsString('让全球知识<br>', $view);
        $this->assertStringNotContainsString('增长阶段的<br', $view);
        $this->assertStringNotContainsString('跨时区团队<br', $view);
    }

    public function test_homepage_renders_enterprise_modules_and_demo_form_fallback(): void
    {
        $this->activateTheme();
        $this->createPublishedArticle();

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('themes/'.self::THEME_ID.'/theme.css', false)
            ->assertSee('GEOFlow Control Plane')
            ->assertSee('让全球知识')
            ->assertSee('成为')
            ->assertSee('可信答案')
            ->assertSee('客户、案例、覆盖与指标均为演示信息')
            ->assertSee('演示表单 · 当前未连接数据提交')
            ->assertSee('演示状态不会发送或保存任何信息')
            ->assertSee('data-ent-tabs', false);
    }

    public function test_homepage_uses_an_active_backend_lead_form(): void
    {
        $this->activateTheme();

        $leadForm = LeadForm::query()->create([
            'name' => '企业 GEO 交流',
            'slug' => 'enterprise-geo',
            'status' => LeadForm::STATUS_ACTIVE,
            'description' => '提交企业 GEO 需求。',
            'submit_button_label' => '提交需求',
            'success_message' => '已收到。',
            'fields' => [
                [
                    'name' => 'work_email',
                    'label' => '工作邮箱',
                    'type' => 'email',
                    'required' => true,
                    'options' => [],
                ],
            ],
        ]);
        $this->selectHomepageLeadForm($leadForm->slug);

        $response = $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('已连接后台表单')
            ->assertSee('提交内容会安全进入 GEOFlow 增长中心。')
            ->assertSee(
                'action="'.route('site.lead-forms.submit', ['slug' => $leadForm->slug]).'"',
                false
            )
            ->assertSee('name="work_email"', false)
            ->assertDontSee('演示状态不会发送或保存任何信息');

        $this->assertSame(
            1,
            substr_count(
                (string) $response->getContent(),
                'action="'.route('site.lead-forms.submit', ['slug' => $leadForm->slug]).'"'
            )
        );
    }

    public function test_search_state_stays_focused_on_results(): void
    {
        $this->activateTheme();
        $article = $this->createPublishedArticle();

        $this->get(route('site.home', ['search' => 'Enterprise']))
            ->assertOk()
            ->assertSee($article->title)
            ->assertSee('Search results')
            ->assertDontSee('GEOFlow Control Plane')
            ->assertDontSee('NORTHSTAR INDUSTRIAL');
    }

    public function test_homepage_uses_only_the_explicitly_selected_form(): void
    {
        $this->activateTheme();

        foreach (['enterprise-sales', 'event-registration'] as $slug) {
            LeadForm::query()->create([
                'name' => $slug,
                'slug' => $slug,
                'status' => LeadForm::STATUS_ACTIVE,
                'description' => '',
                'submit_button_label' => '提交',
                'success_message' => '已收到。',
                'fields' => [],
            ]);
        }
        $this->selectHomepageLeadForm('event-registration');

        $response = $this->get(route('site.home'))
            ->assertOk()
            ->assertDontSee('action="'.route('site.lead-forms.submit', ['slug' => 'enterprise-sales']).'"', false)
            ->assertSee('action="'.route('site.lead-forms.submit', ['slug' => 'event-registration']).'"', false);

        $this->assertSame(
            1,
            substr_count(
                (string) $response->getContent(),
                'action="'.route('site.lead-forms.submit', ['slug' => 'event-registration']).'"'
            )
        );
    }

    public function test_an_active_form_without_homepage_selection_stays_in_demo_mode(): void
    {
        $this->activateTheme();

        LeadForm::query()->create([
            'name' => '企业 GEO 交流',
            'slug' => 'enterprise-geo',
            'status' => LeadForm::STATUS_ACTIVE,
            'description' => '',
            'submit_button_label' => '提交',
            'success_message' => '已收到。',
            'fields' => [],
        ]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('演示表单 · 请在首页模块指定表单')
            ->assertSee('请在后台首页模块中添加线索表单模块')
            ->assertSee('在后台启用并指定表单后，提交内容会进入 GEOFlow 增长中心。')
            ->assertDontSee('action="'.route('site.lead-forms.submit', ['slug' => 'enterprise-geo']).'"', false);
    }

    public function test_category_article_about_and_archive_pages_render_with_the_theme(): void
    {
        $this->activateTheme();
        $article = $this->createPublishedArticle();

        $this->get(route('site.category', ['slug' => $article->category->slug]))
            ->assertOk()
            ->assertSee('全部文章')
            ->assertSee('ent-article-card--category', false)
            ->assertSee('ent-article-card__category-link', false)
            ->assertSee($article->title)
            ->assertDontSee('Knowledge domain')
            ->assertDontSee('Category index')
            ->assertDontSee('Browse by topic')
            ->assertDontSee('GEOFlow Insight')
            ->assertDontSee('ent-article-card__visual', false)
            ->assertDontSee('published resources')
            ->assertSee('themes/'.self::THEME_ID.'/theme.css', false);

        $this->get(route('site.article', ['slug' => $article->slug]))
            ->assertOk()
            ->assertSee('<h2>Enterprise evidence body</h2>', false)
            ->assertSee('data-ent-article-toc', false)
            ->assertSee('data-ent-article-content', false)
            ->assertDontSee('GEOFlow Insight')
            ->assertDontSee('data-ent-copy-url', false);

        $this->get(route('site.about'))
            ->assertOk()
            ->assertSee('关于 GEOFlow')
            ->assertSee('让可信知识进入 AI 答案')
            ->assertSee('一条完整的内容工作流')
            ->assertSee('GEOFlow 包含的核心能力')
            ->assertSee('开放、可部署的技术基础')
            ->assertSee('从开源仓库开始')
            ->assertSee('data-ent-article-toc', false)
            ->assertSee('data-ent-article-content', false)
            ->assertSee('AboutPage')
            ->assertSee('https://github.com/yaojingang/GEOFlow');

        $this->get(route('site.archive'))
            ->assertOk()
            ->assertSee('Archive ledger');

        $this->get(route('site.archive.month', [
            'year' => $article->published_at->format('Y'),
            'month' => $article->published_at->format('m'),
        ]))
            ->assertOk()
            ->assertSee($article->title)
            ->assertSee('Monthly archive');
    }

    public function test_about_page_falls_back_to_the_default_site_view(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'active_theme'],
            ['setting_value' => 'theme-without-about-template'],
        );
        SiteSettingsBag::forget();

        $this->get(route('site.about'))
            ->assertOk()
            ->assertSee('article-detail-shell', false)
            ->assertSee('关于 GEOFlow')
            ->assertSee('一条完整的内容工作流')
            ->assertSee('https://github.com/yaojingang/GEOFlow');
    }

    public function test_article_related_heading_and_footer_use_the_compact_copy(): void
    {
        $this->activateTheme();
        $article = $this->createPublishedArticle();
        $css = (string) file_get_contents(public_path('themes/'.self::THEME_ID.'/theme.css'));

        $this->assertMatchesRegularExpression(
            '/\.ent-related__heading h2\s*\{[^}]*font-size:\s*clamp\(1\.2rem, 1\.5vw, 1\.4rem\);/s',
            $css
        );
        $this->assertSame(1, substr_count($css, '.ent-related h2'));

        foreach (range(2, 4) as $index) {
            $copy = $article->replicate();
            $copy->title = 'Related enterprise evidence '.$index;
            $copy->slug = 'related-enterprise-evidence-'.$index;
            $copy->is_hot = false;
            $copy->is_featured = false;
            $copy->save();
        }

        $this->get(route('site.article', ['slug' => $article->slug]))
            ->assertOk()
            ->assertSee('ent-related__heading', false)
            ->assertSee(__('site.article_related'))
            ->assertDontSee('全部洞察')
            ->assertDontSee('面向全球团队的 GEO 开源生态与企业知识工作流。')
            ->assertDontSee('参与开源生态')
            ->assertDontSee('ent-footer__lead', false);
    }

    public function test_category_pagination_uses_the_compact_theme_row(): void
    {
        $this->activateTheme();
        $article = $this->createPublishedArticle();

        foreach (range(2, 13) as $index) {
            $copy = $article->replicate();
            $copy->title = 'Enterprise GEO Evidence System '.$index;
            $copy->slug = 'enterprise-geo-evidence-system-'.$index;
            $copy->is_hot = false;
            $copy->is_featured = false;
            $copy->save();
        }

        $this->get(route('site.category', ['slug' => $article->category->slug]))
            ->assertOk()
            ->assertSee('ent-pagination__nav', false)
            ->assertSee('ent-pagination__summary', false)
            ->assertSee('下一页')
            ->assertSee('共 <strong>13</strong> 条结果', false)
            ->assertDontSee('sm:hidden', false);
    }

    public function test_article_excerpt_is_rendered_as_compact_plain_text(): void
    {
        $this->activateTheme();
        $article = $this->createPublishedArticle();
        $article->update([
            'excerpt' => "## Why GEO matters\n\nA clear answer for enterprise teams.",
        ]);

        $this->get(route('site.article', ['slug' => $article->slug]))
            ->assertOk()
            ->assertSee('A clear answer for enterprise teams.')
            ->assertDontSee('Why GEO matters A clear answer for enterprise teams.')
            ->assertDontSee('## Why GEO matters');
    }

    private function activateTheme(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'active_theme'],
            ['setting_value' => self::THEME_ID],
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'site_name'],
            ['setting_value' => 'GEOFlow'],
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'site_description'],
            ['setting_value' => 'Global GEO open-source ecosystem for enterprise teams.'],
        );

        SiteSettingsBag::forget();
    }

    private function selectHomepageLeadForm(string $slug): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'homepage_modules'],
            ['setting_value' => json_encode([[
                'id' => 'enterprise-contact',
                'type' => 'lead_form',
                'layout' => 'single',
                'enabled' => true,
                'sort_order' => 10,
                'title' => '预约企业 GEO 方案交流',
                'body' => '提交后将进入后台线索管理。',
                'lead_form_slug' => $slug,
            ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );

        SiteSettingsBag::forget();
    }

    private function createPublishedArticle(): Article
    {
        $category = Category::query()->create([
            'name' => 'Enterprise GEO',
            'slug' => 'enterprise-geo',
            'description' => 'Enterprise GEO knowledge and practice.',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow Research',
        ]);

        return Article::query()->create([
            'title' => 'Enterprise GEO Evidence System',
            'slug' => 'enterprise-geo-evidence-system',
            'excerpt' => 'A practical GEO evidence workflow for global teams.',
            'content' => "## Enterprise evidence body\n\nBuild a trusted and observable knowledge workflow.",
            'keywords' => 'GEO,enterprise,evidence',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_ai_generated' => 1,
            'is_hot' => true,
            'is_featured' => true,
            'published_at' => now(),
        ]);
    }
}
