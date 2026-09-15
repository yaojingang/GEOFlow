<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\ThemeWorkspace;
use App\Services\Api\ThemeWorkspacePreview;
use App\Support\Site\ArticlePermalinkPolicy;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ThemeWorkspacePreviewContractTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private function workspace(array $files): string
    {
        Storage::fake('local');
        $admin = Admin::query()->create(['username' => 'preview-editor', 'password' => 'preview-password', 'role' => 'super_admin', 'status' => 'active']);
        $this->token = $admin->createToken('preview-test', ['themes:read', 'themes:write', 'themes:code'])->plainTextToken;
        $id = $this->withToken($this->token)->postJson('/api/v1/management/theme-workspaces', ['site' => 'primary', 'theme' => 'default'])->assertCreated()->json('data.id');
        $base = '/api/v1/management/theme-workspaces/'.$id;
        $this->withToken($this->token)->postJson($base.'/code-authorizations', ['password' => 'preview-password'])->assertOk();
        $changes = [];
        foreach ($files as $path => $content) {
            $changes[] = ['path' => $path, 'content' => $content, 'action' => 'put', 'expected_sha256' => null];
        }
        $this->withToken($this->token)->postJson($base.'/changes', ['expected_version' => 1, 'changes' => $changes])->assertOk();

        return $base;
    }

    private function attribute(string $html, string $id, string $attribute = 'href'): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $node = (new \DOMXPath($dom))->query('//*[@id="'.$id.'"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);

        return $node->getAttribute($attribute);
    }

    public function test_navigation_assets_and_stylesheet_dependencies_keep_preview_authorization(): void
    {
        $base = $this->workspace([
            'resources/views/theme/default/home.blade.php' => '<a id="about" href="{{ route(\'site.about\') }}">About</a><a id="hardcoded" href="/about">About</a><a id="next" href="?page=2">Next</a><link id="style" href="{{ asset(\'themes/default/css/theme.css\') }}?v=1">',
            'resources/views/theme/default/about.blade.php' => '<h1>Draft about</h1>',
            'public/themes/default/css/theme.css' => '@import "extra.css";body { background: url("../image.svg#icon"); }',
            'public/themes/default/css/extra.css' => 'body { color: navy; }',
            'public/themes/default/image.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
        ]);
        $pages = $this->withToken($this->token)->postJson($base.'/previews')->assertOk()->json('data.pages');
        $html = $this->get($pages['home']['url'])->assertOk()->getContent();
        $this->get($this->attribute($html, 'about'))->assertOk()->assertSee('Draft about');
        $this->get($this->attribute($html, 'hardcoded'))->assertOk()->assertSee('Draft about');
        $next = $this->attribute($html, 'next');
        $this->assertStringContainsString('page=2', $next);
        $this->get($next)->assertOk();
        $this->get(str_replace('page=2', 'page=3', $next))->assertForbidden();
        $css = $this->get($this->attribute($html, 'style'))->assertOk()->getContent();
        preg_match_all('~https?://[^"\s)]+~', $css, $urls);
        $this->assertCount(2, $urls[0]);
        foreach ($urls[0] as $url) {
            $this->get(explode('#', $url, 2)[0])->assertOk();
        }
        $this->travel(16)->minutes();
        $this->get($pages['home']['url'])->assertForbidden();
    }

    public function test_configured_article_permalink_renders_real_content_without_counting_a_view(): void
    {
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Author']);
        $article = Article::query()->create(['title' => 'Canonical story', 'slug' => 'canonical-story', 'content' => 'Published body', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now(), 'view_count' => 0]);
        SiteSetting::query()->create(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY, 'setting_value' => json_encode(['schema_version' => 1, 'revision' => 1, 'current_pattern' => '/{slug}.html'])]);
        SiteSettingsBag::forget();
        $base = $this->workspace([
            'resources/views/theme/default/home.blade.php' => '@foreach($articles as $article)<a id="article" href="{{ app(\\App\\Services\\Site\\SiteUrlGenerator::class)->article($article) }}">Story</a><a id="legacy-article" href="/article/{{ $article->slug }}">Story</a>@endforeach',
            'resources/views/theme/default/article.blade.php' => '<h1>{{ $article->title }}</h1>{!! $contentHtml !!}',
        ]);
        $pages = $this->withToken($this->token)->postJson($base.'/previews')->assertOk()->json('data.pages');
        $this->assertSame('canonical-story.html', $pages['article']['path']);
        $this->assertCount(8, $pages);
        foreach ($pages as $page) {
            $this->assertTrue($page['available']);
            $this->get($page['url'])->assertOk();
        }
        $this->get($pages['article']['url'])->assertOk()->assertSee('Published body');
        $html = $this->get($pages['home']['url'])->assertOk()->getContent();
        $this->get($this->attribute($html, 'article'))->assertOk()->assertSee('Canonical story');
        $this->get($this->attribute($html, 'legacy-article'))->assertOk()->assertSee('Canonical story');
        $this->assertSame(0, $article->fresh()->view_count);
    }

    public function test_preview_uses_an_ephemeral_session_and_restores_the_callers_session(): void
    {
        $base = $this->workspace([
            'resources/views/theme/default/home.blade.php' => '{{ session("private_message", "isolated") }}@php(session(["preview_write" => "temporary"]))',
        ]);
        $url = $this->withToken($this->token)->postJson($base.'/previews')->assertOk()->json('data.pages.home.url');
        Session::put('private_message', 'caller secret');
        $previous = Session::getFacadeRoot();
        $this->get($url)->assertOk()->assertSee('isolated')->assertDontSee('caller secret');

        $this->assertSame($previous, Session::getFacadeRoot());
        $this->assertSame('caller secret', Session::get('private_message'));
        $this->assertNull(Session::get('preview_write'));
    }

    public function test_signed_preview_rejects_a_revision_changed_after_signature_validation(): void
    {
        $base = $this->workspace([
            'resources/views/theme/default/home.blade.php' => '<h1>Initial draft</h1><a id="about" href="/about">About</a><link id="style" href="{{ asset(\'themes/default/theme.css\') }}">',
            'resources/views/theme/default/about.blade.php' => '<h1>Initial about</h1>',
            'public/themes/default/theme.css' => 'body { color: navy; }',
        ]);
        $initial = $this->getJson($base)->assertOk()->json('data');
        $oldUrl = $this->postJson($base.'/previews')->assertOk()->json('data.pages.home.url');
        $changes = [];
        foreach ([
            'resources/views/theme/default/home.blade.php' => '<h1>Advanced draft</h1><a id="about" href="/about">About</a><link id="style" href="{{ asset(\'themes/default/theme.css\') }}">',
            'resources/views/theme/default/about.blade.php' => '<h1>Advanced about</h1>',
            'public/themes/default/theme.css' => 'body { color: green; }',
        ] as $path => $content) {
            $changes[] = ['path' => $path, 'content' => $content, 'action' => 'put', 'expected_sha256' => $initial['files'][$path]['sha256']];
        }
        $advanced = $this->postJson($base.'/changes', ['expected_version' => $initial['lock_version'], 'changes' => $changes])->assertOk()->json('data');
        ThemeWorkspace::query()->whereKey($initial['id'])->update(['revision_id' => $initial['revision_id'], 'lock_version' => $initial['lock_version']]);

        $advancedDuringValidation = false;
        $event = 'eloquent.retrieved: '.ThemeWorkspace::class;
        Event::listen($event, function (ThemeWorkspace $workspace) use ($initial, $advanced, &$advancedDuringValidation): void {
            $methods = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function');
            if (! $advancedDuringValidation && $workspace->id === $initial['id']
                && in_array('fromSignedRequest', $methods, true) && in_array('refresh', $methods, true)) {
                $advancedDuringValidation = true;
                ThemeWorkspace::query()->whereKey($workspace->id)->update(['revision_id' => $advanced['revision_id'], 'lock_version' => $advanced['lock_version']]);
            }
        });
        try {
            $response = $this->get($oldUrl);
        } finally {
            Event::forget($event);
        }

        $this->assertTrue($advancedDuringValidation);
        $this->assertDatabaseHas('theme_workspaces', ['id' => $initial['id'], 'revision_id' => $advanced['revision_id']]);
        $response->assertConflict()->assertDontSee('Advanced draft');
        $newUrl = $this->postJson($base.'/previews')->assertOk()->json('data.pages.home.url');
        $html = $this->get($newUrl)->assertOk()->assertSee('Advanced draft')->getContent();
        $about = $this->attribute($html, 'about');
        $style = $this->attribute($html, 'style');
        $this->assertStringContainsString('/'.$advanced['revision_id'].'/', $about);
        $this->assertStringContainsString('/'.$advanced['revision_id'].'/', $style);
        $this->get($about)->assertOk()->assertSee('Advanced about');
        $this->get($style)->assertOk()->assertSee('color: green', false);

        $preview = app(ThemeWorkspacePreview::class);
        $auth = $preview->fromSignedRequest(Request::create($newUrl), $initial['id'], $advanced['revision_id']);
        $directHtml = $preview->render($auth, $initial['id']);
        $this->assertStringContainsString('Advanced draft', $directHtml);
        $this->assertStringContainsString('/'.$advanced['revision_id'].'/', $this->attribute($directHtml, 'about'));
        $this->assertStringContainsString('/'.$advanced['revision_id'].'/', $this->attribute($directHtml, 'style'));
    }
}
