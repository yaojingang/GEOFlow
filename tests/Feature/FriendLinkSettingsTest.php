<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\LeadForm;
use App\Models\SiteSetting;
use App\Support\Site\FriendLinkSettings;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FriendLinkSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_edit_disable_sort_and_clear_links_without_changing_other_settings(): void
    {
        $this->login();
        SiteSetting::query()->create(['setting_key' => 'site_name', 'setting_value' => 'Unchanged']);
        $this->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertSee(route('admin.site-settings.friend-links.edit'), false)
            ->assertDontSee('friend-links-form', false);
        $this->get(route('admin.site-settings.friend-links.edit'))
            ->assertOk()
            ->assertSee('friend-links-form', false)
            ->assertSee(route('admin.site-settings.index'), false);
        $this->submit([
            $this->link(['name' => ' Later ', 'url' => ' https://example.com/later ', 'sort_order' => '9']),
            $this->link(['name' => 'First', 'url' => 'https://example.com/first']),
            $this->link(['name' => 'Disabled', 'url' => 'https://example.com/hidden', 'enabled' => '0']),
        ])->assertSessionHasNoErrors()->assertSessionHas('friend_links_saved', true)->assertRedirect($this->destination());
        $this->assertSame(['First', 'Later'], array_column(app(FriendLinkSettings::class)->visibleLinks(), 'name'));
        $this->assertSame('https://example.com/later', $this->config()['links'][0]['url']);
        $this->assertFalse($this->config()['links'][2]['enabled']);
        $this->assertIsInt($this->config()['links'][0]['sort_order']);
        $this->assertSame(['enabled', 'links'], array_keys($this->config()));
        $this->submit($this->config()['links'], ['enabled' => '0'])->assertSessionHasNoErrors();
        $this->get('/')->assertOk()->assertDontSee('class="site-friend-links"', false);
        $this->submit($this->config()['links'])->assertSessionHasNoErrors();
        $this->get('/')->assertOk()->assertSee('class="site-friend-links"', false);
        $this->submit([])->assertSessionHasNoErrors();
        $this->assertSame(['enabled' => true, 'links' => []], $this->config());
        $this->get('/')->assertOk()->assertDontSee('assets/css/friend-links.css');
        $this->assertDatabaseHas('site_settings', ['setting_key' => 'site_name', 'setting_value' => 'Unchanged']);
    }

    public function test_stale_save_preserves_the_winners_config_draft_and_original_revision(): void
    {
        $this->login();
        foreach ([false, true] as $existing) {
            if ($existing) {
                $this->submit([$this->link(['name' => 'Before'])])->assertSessionHasNoErrors();
            }
            $revision = app(FriendLinkSettings::class)->snapshot()['revision'];
            $this->submit([$this->link(['name' => 'Winner'])])->assertSessionHasNoErrors();
            $payload = $this->payload([$this->link(['name' => 'Draft'])], ['expected_revision' => $revision]);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this->post(route('admin.site-settings.friend-links.update'), ['friend_links' => $payload])
                    ->assertRedirect($this->destination())->assertSessionHasErrorsIn('friend_links', 'friend_links.expected_revision')
                    ->assertSessionHasInput('friend_links.expected_revision', $revision);
                $this->get($this->destination())->assertOk()->assertSee('value="Draft"', false)
                    ->assertSee('value="'.$revision.'"', false)->assertSee('data-friend-reload', false);
            }
            $this->assertSame('Winner', $this->config()['links'][0]['name']);
        }
    }

    public function test_unchanged_save_is_successful_and_equal_sort_values_keep_saved_order(): void
    {
        $this->login();
        $links = [$this->link(['name' => 'B', 'url' => 'https://example.com/b']), $this->link(['name' => 'A'])];
        $this->submit($links)->assertSessionHasNoErrors();
        $revision = app(FriendLinkSettings::class)->snapshot()['revision'];
        $this->submit($links)->assertSessionHasNoErrors();
        $this->assertSame($revision, app(FriendLinkSettings::class)->snapshot()['revision']);
        $this->assertSame(['B', 'A'], array_column(app(FriendLinkSettings::class)->visibleLinks(), 'name'));
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_rows_are_rejected_atomically_even_when_disabled(array $overrides): void
    {
        $this->login();
        $this->submit([$this->link()])->assertSessionHasNoErrors();
        $raw = SiteSetting::query()->where('setting_key', 'friend_links')->value('setting_value');
        $this->submit([$this->link(), $this->link(array_replace(['url' => 'https://example.com/second', 'enabled' => '0'], $overrides))])
            ->assertSessionHasErrorsIn('friend_links')->assertRedirect($this->destination());
        $this->assertDatabaseHas('site_settings', ['setting_key' => 'friend_links', 'setting_value' => $raw]);
    }

    public static function invalidRows(): array
    {
        return [
            'blank name' => [['name' => '   ']], 'long name' => [['name' => str_repeat('名', 81)]],
            'empty URL' => [['url' => '']], 'long URL' => [['url' => 'https://example.com/'.str_repeat('a', 2048)]],
            'script' => [['url' => 'javascript:alert(1)']], 'relative' => [['url' => '/test']],
            'protocol relative' => [['url' => '//example.com']], 'ftp' => [['url' => 'ftp://example.com']],
            'credentials' => [['url' => 'https://user:secret@example.com']], 'username only' => [['url' => 'https://user@example.com']],
            'backslash' => [['url' => 'https://example.com/\\evil']], 'embedded control' => [['url' => "https://example.com/a\nb"]],
            'leading control' => [['url' => "\nhttps://example.com"]], 'null control' => [['url' => "https://example.com/\0"]],
            'negative order' => [['sort_order' => '-1']], 'large order' => [['sort_order' => '10000']], 'decimal order' => [['sort_order' => '1.2']],
            'invalid boolean' => [['enabled' => 'yes']], 'array name' => [['name' => ['bad']]],
            'bad target' => [['target' => '_parent']], 'bad relationship' => [['relationship' => 'dofollow']],
            'unknown field' => [['extra' => 'bad']],
        ];
    }

    public function test_missing_groups_truncated_rows_counts_and_non_list_indices_never_clear_settings(): void
    {
        $this->login();
        $this->submit([$this->link()])->assertSessionHasNoErrors();
        $valid = $this->payload([$this->link()]);
        $partial = $valid;
        unset($partial['links'][0]['target']);
        $cases = [null, [], ['enabled' => 1], $partial, array_replace($valid, ['link_count' => 2]),
            array_replace($valid, ['links' => [2 => $this->link()]]), array_replace($valid, ['links' => null]),
            array_diff_key($valid, ['links' => 1]), array_diff_key($valid, ['enabled' => 1]),
            array_diff_key($valid, ['expected_revision' => 1]), array_diff_key($valid, ['link_count' => 1]),
        ];
        foreach ($cases as $group) {
            $this->post(route('admin.site-settings.friend-links.update'), $group === null ? [] : ['friend_links' => $group])
                ->assertSessionHasErrorsIn('friend_links');
            $this->assertCount(1, $this->config()['links']);
            $this->get($this->destination())->assertOk();
        }
    }

    public function test_incomplete_draft_cannot_turn_into_a_clear_or_partial_replacement_on_retry(): void
    {
        $this->login();
        $links = [$this->link(), $this->link(['url' => 'https://example.com/second'])];
        $this->submit($links)->assertSessionHasNoErrors();
        $original = $this->config();
        $missingFields = $links;
        unset($missingFields[0]['enabled'], $missingFields[0]['target'], $missingFields[0]['relationship']);
        foreach ([[], [$links[0]], $missingFields] as $received) {
            $payload = $this->payload($received, ['link_count' => 2]);
            $this->post(route('admin.site-settings.friend-links.update'), ['friend_links' => $payload])
                ->assertSessionHasErrorsIn('friend_links')->assertSessionHas('friend_links_reload_required', true);
            $html = $this->get($this->destination())->assertOk()->assertSee('data-friend-reload', false)->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $this->assertSame('2', $xpath->query('//input[@name="friend_links[link_count]"]')->item(0)->getAttribute('value'));
            $this->assertTrue($xpath->query('//form[@id="friend-links-form"]//button[@type="submit"]')->item(0)->hasAttribute('disabled'));
            $this->assertSame($original, $this->config());
        }
    }

    public function test_fifty_links_are_supported_and_fifty_one_are_rejected(): void
    {
        $this->login();
        $links = array_map(fn ($i) => $this->link(['url' => 'https://example.com/'.$i]), range(1, 50));
        $this->submit($links)->assertSessionHasNoErrors();
        $this->assertCount(50, app(FriendLinkSettings::class)->visibleLinks());
        $this->submit([...$links, $this->link()])->assertSessionHasErrorsIn('friend_links', 'friend_links.link_count');
        $this->assertCount(50, $this->config()['links']);
    }

    public function test_validation_marks_last_row_fields_and_opens_advanced_errors(): void
    {
        $this->login();
        $links = array_map(fn ($i) => $this->link(['url' => 'https://example.com/'.$i]), range(1, 50));
        $this->submit($links)->assertSessionHasNoErrors();
        $saved = $this->config();
        foreach (['url' => $links[0]['url'], 'target' => '_parent', 'relationship' => 'dofollow'] as $field => $value) {
            $draft = $links;
            $draft[49][$field] = $value;
            $this->submit($draft)->assertSessionHasErrorsIn('friend_links', 'friend_links.links.49.'.$field);
            $html = $this->get($this->destination())->assertOk()->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $control = $xpath->query('//*[@name="friend_links[links][49]['.$field.']"]')->item(0);
            $this->assertNotNull($control);
            $this->assertSame('true', $control->getAttribute('aria-invalid'));
            if ($field !== 'url') {
                $this->assertTrue($xpath->query('ancestor::details[1]', $control)->item(0)->hasAttribute('open'));
            }
            $this->assertSame($saved, $this->config());
        }
    }

    #[DataProvider('equivalentUrls')]
    public function test_url_duplicates_normalize_scheme_host_root_and_default_port(string $first, string $second): void
    {
        $this->login();
        $this->submit([$this->link(['url' => $first]), $this->link(['url' => $second])])
            ->assertSessionHasErrorsIn('friend_links', 'friend_links.links.1.url');
        $this->assertDatabaseMissing('site_settings', ['setting_key' => 'friend_links']);
    }

    public static function equivalentUrls(): array
    {
        return [['https://Ä.example/', 'https://ä.example/'], ['HTTPS://EXAMPLE.COM', 'https://example.com/'], ['http://example.com:80/', 'http://example.com'],
            ['https://example.com:443/a?b=1#c', 'https://example.com/a?b=1#c']];
    }

    public function test_distinct_paths_ports_queries_and_fragments_remain_distinct(): void
    {
        $this->login();
        $urls = ['https://example.com/A', 'https://example.com/a', 'https://example.com:444/a',
            'https://example.com/a?a=1&b=2', 'https://example.com/a?b=2&a=1', 'https://example.com/a#first', 'https://example.com/a#second'];
        $this->submit(array_map(fn ($url) => $this->link(['url' => $url]), $urls))->assertSessionHasNoErrors();
        $this->assertSame($urls, array_column($this->config()['links'], 'url'));
    }

    #[DataProvider('invalidStoredValues')]
    public function test_invalid_saved_values_are_hidden_and_require_explicit_recovery(?string $raw): void
    {
        $this->login();
        SiteSetting::query()->create(['setting_key' => 'friend_links', 'setting_value' => $raw]);
        $this->assertSame('invalid', app(FriendLinkSettings::class)->snapshot()['state']);
        $this->get('/')->assertOk()->assertDontSee('class="site-friend-links"', false);
        $this->get(route('admin.site-settings.friend-links.edit'))->assertOk()->assertSee(__('friend_links.invalid'));
        $this->submit([$this->link()])->assertSessionHasErrorsIn('friend_links', 'friend_links.replace_invalid');
        $this->assertDatabaseHas('site_settings', ['setting_key' => 'friend_links', 'setting_value' => $raw]);
        $this->submit([$this->link()], ['replace_invalid' => '1'])->assertSessionHasNoErrors();
        $this->get('/')->assertOk()->assertSee('https://example.com', false);
    }

    public static function invalidStoredValues(): array
    {
        $row = ['name' => 'Example', 'url' => 'https://example.com', 'sort_order' => 0, 'enabled' => true, 'target' => '_blank', 'relationship' => 'regular'];

        return [[null], [''], ['<script>alert(1)</script>'], ['[]'], ['{}'], ['{"enabled":true,"links":{}}'],
            ['{"enabled":"1","links":[]}'], [json_encode(['enabled' => true, 'links' => [array_replace($row, ['url' => 'javascript:alert(1)'])]])],
            [json_encode(['enabled' => true, 'links' => [array_replace($row, ['sort_order' => '0'])]])],
            [json_encode(['enabled' => true, 'links' => [array_replace($row, ['relationship' => 'unsafe'])]])],
        ];
    }

    public function test_missing_null_empty_and_raw_values_have_distinct_revisions(): void
    {
        $revisions = [app(FriendLinkSettings::class)->snapshot()['revision']];
        foreach ([null, '', '{}'] as $value) {
            SiteSetting::query()->updateOrCreate(['setting_key' => 'friend_links'], ['setting_value' => $value]);
            $revisions[] = app(FriendLinkSettings::class)->snapshot()['revision'];
        }
        $this->assertCount(4, array_unique($revisions));
    }

    public function test_audit_records_success_validation_failure_and_conflict_without_raw_urls(): void
    {
        Schema::create('admin_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->nullable();
            $table->string('admin_username', 50);
            $table->string('admin_role', 20)->default('admin');
            $table->string('action', 120);
            $table->string('request_method', 10)->default('POST');
            $table->string('page')->default('');
            $table->string('target_type', 50)->default('');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip_address', 64)->default('');
            $table->text('details')->default('');
            $table->timestamp('created_at')->nullable();
        });

        $this->login();
        $oldRevision = app(FriendLinkSettings::class)->snapshot()['revision'];
        $this->submit([$this->link()])->assertSessionHasNoErrors();
        $this->submit([$this->link(['url' => 'https://user:SECRET-FRIEND-LINK@example.com'])])->assertSessionHasErrorsIn('friend_links');
        $this->submit([$this->link()], ['expected_revision' => $oldRevision])->assertSessionHasErrorsIn('friend_links');
        $logs = AdminActivityLog::query()->where('action', 'like', 'admin.site-settings.friend-links.update:%')->orderBy('id')->pluck('details')
            ->map(fn ($raw) => json_decode($raw, true));
        $this->assertSame([true, false, false], $logs->pluck('success')->all());
        $this->assertSame(['saved', 'invalid', 'conflict'], $logs->pluck('friend_links.result')->all());
        $this->assertStringNotContainsString('SECRET-FRIEND-LINK', $logs->toJson());
    }

    public function test_guests_inactive_admins_and_revoked_sessions_cannot_save(): void
    {
        $payload = ['friend_links' => $this->payload([])];
        $this->post(route('admin.site-settings.friend-links.update'), $payload)->assertRedirect();
        $admin = $this->login();
        $admin->update(['status' => 'inactive']);
        $this->post(route('admin.site-settings.friend-links.update'), $payload)->assertRedirect();
        $admin->update(['status' => 'active']);
        $this->actingAs($admin->fresh(), 'admin');
        $admin->increment('auth_version');
        $this->post(route('admin.site-settings.friend-links.update'), $payload)->assertRedirect();
        $this->assertDatabaseMissing('site_settings', ['setting_key' => 'friend_links']);
    }

    public function test_csrf_check_really_rejects_missing_token_and_accepts_valid_token(): void
    {
        $this->login();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $payload = ['friend_links' => $this->payload([])];
        $this->withSession(['_token' => 'friend-links-csrf-test'])->post(route('admin.site-settings.friend-links.update'), $payload)->assertStatus(419);
        $this->assertDatabaseMissing('site_settings', ['setting_key' => 'friend_links']);
        $this->post(route('admin.site-settings.friend-links.update'), $payload + ['_token' => 'friend-links-csrf-test'])
            ->assertRedirect($this->destination())->assertSessionHasNoErrors();
    }

    public function test_latest_write_bypasses_stale_public_settings_cache_and_home_reads_only_once(): void
    {
        $this->login();
        $this->submit([$this->link(['name' => 'Old name'])])->assertSessionHasNoErrors();
        SiteSettingsBag::forget();
        $staleSettings = SiteSettingsBag::all();
        $this->submit([$this->link(['name' => 'New name'])])->assertSessionHasNoErrors();
        Cache::put('geoflow.site_settings.public_map', $staleSettings, 60);
        $this->assertStringContainsString('Old name', SiteSettingsBag::all()['friend_links']);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (in_array('friend_links', $query->bindings, true) && str_starts_with($query->sql, 'select')) {
                $queries[] = $query->sql;
            }
        });
        $this->get('/?utm_source=partner')->assertOk()->assertSee('New name')->assertDontSee('Old name');
        $this->assertCount(1, $queries);
        $queries = [];
        foreach (['/?search=example', '/?page=2', '/?category=99999', '/about', '/archive'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('class="site-friend-links"', false)->assertDontSee('assets/css/friend-links.css');
        }
        $this->assertSame([], $queries);
    }

    public function test_links_are_absent_on_article_category_and_form_pages(): void
    {
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Editor']);
        Article::query()->create(['title' => 'Article', 'slug' => 'sample', 'content' => 'Content', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now()]);
        LeadForm::query()->create(['name' => 'Contact', 'slug' => 'contact', 'status' => 'active', 'fields' => []]);
        $this->login();
        $this->submit([$this->link()])->assertSessionHasNoErrors();
        foreach (['/article/sample', '/category/news', '/?category='.$category->id, '/forms/contact'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('class="site-friend-links"', false);
        }
    }

    public function test_all_themes_show_escaped_links_once_with_correct_target_and_relationship(): void
    {
        $this->login();
        $this->submit([
            $this->link(['name' => '<b>Quoted "site"</b>', 'url' => 'https://example.com/?a=1&b=2']),
            $this->link(['name' => 'Self', 'url' => 'https://example.com/self', 'target' => '_self']),
            $this->link(['name' => 'Restricted', 'url' => 'https://example.com/restricted', 'relationship' => 'nofollow']),
            $this->link(['name' => 'Sponsored', 'url' => 'https://example.com/sponsor', 'relationship' => 'sponsored']),
        ])->assertSessionHasNoErrors();
        $this->get(route('admin.site-settings.friend-links.edit'))->assertOk()->assertSee('&lt;b&gt;Quoted', false)->assertDontSee('<b>Quoted', false);
        $themes = app(SiteThemeCatalog::class)->all();
        $this->assertGreaterThanOrEqual(27, count($themes));
        foreach ($themes as $theme) {
            SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => $theme['id']]);
            SiteSettingsBag::forget();
            $html = $this->get('/')->assertOk()->assertSee('assets/css/friend-links.css')->assertSee('&lt;b&gt;Quoted', false)
                ->assertDontSee('<b>Quoted', false)->assertSee('rel="nofollow noopener"', false)->assertSee('rel="sponsored noopener"', false)->getContent();
            $this->assertSame(1, substr_count($html, 'class="site-friend-links"'), $theme['id']);
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($dom);
            $links = $xpath->query('//a[@class="site-friend-links__link"]');
            $this->assertCount(4, $links, $theme['id']);
            $this->assertSame('noopener', $links[0]->getAttribute('rel'));
            $this->assertSame('_self', $links[1]->getAttribute('target'));
            $this->assertFalse($links[1]->hasAttribute('rel'));
            $this->assertSame('https://example.com/?a=1&b=2', $links[0]->getAttribute('href'));
        }
    }

    private function login(): Admin
    {
        $admin = Admin::query()->create(['username' => 'friend-links-admin', 'password' => 'test-password', 'role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function link(array $overrides = []): array
    {
        return array_replace(['name' => 'Example', 'url' => 'https://example.com', 'sort_order' => '0', 'enabled' => '1', 'target' => '_blank', 'relationship' => 'regular'], $overrides);
    }

    private function payload(array $links, array $overrides = []): array
    {
        return array_replace(['enabled' => '1', 'link_count' => count($links), 'expected_revision' => app(FriendLinkSettings::class)->snapshot()['revision']],
            $links === [] ? [] : ['links' => $links], $overrides);
    }

    private function submit(array $links, array $overrides = []): TestResponse
    {
        return $this->post(route('admin.site-settings.friend-links.update'), ['friend_links' => $this->payload($links, $overrides)]);
    }

    private function destination(): string
    {
        return route('admin.site-settings.friend-links.edit');
    }

    private function config(): array
    {
        return json_decode(SiteSetting::query()->where('setting_key', 'friend_links')->value('setting_value'), true);
    }
}
