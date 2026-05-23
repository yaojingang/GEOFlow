# WordPress Distribution Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add WordPress REST API as a first-class distribution channel so GEOFlow can configure, test, publish, update, delete, and manage content on WordPress sites.

**Architecture:** Introduce a distribution publisher interface and route each channel through a publisher based on `distribution_channels.channel_type`. Keep the existing GEOFlow target-site Agent behavior behind a `geoflow_agent` publisher, and add a new `wordpress_rest` publisher that talks to WordPress Core REST API using Application Password authentication. WordPress-specific non-secret settings live in `distribution_channels.channel_config`, while the Application Password remains encrypted in `distribution_channel_secrets.secret_ciphertext`.

**Tech Stack:** Laravel 12, Blade admin UI, Tailwind classes used by the current admin, Eloquent, PostgreSQL JSON columns, Laravel HTTP client, Redis queue, PHPUnit feature/unit tests, WordPress Core REST API.

---

## Scope

### Included In First Implementation

- Add channel type selection in Distribution Management:
  - `geoflow_agent`: current target-site package and signed Agent protocol.
  - `wordpress_rest`: WordPress Core REST API integration.
- Add WordPress configuration fields:
  - WordPress site URL or REST API base URL.
  - WordPress username.
  - Application Password.
  - Default post status: `publish`, `draft`, `pending`, `private`.
  - Category strategy: `match_or_create`, `match_only`, `fixed`.
  - Fixed category ID/name when fixed strategy is selected.
  - Tag strategy: `keywords_to_tags`, `disabled`.
  - Image strategy: `upload_to_media`, `keep_original`.
  - Content format: `html`.
- Add WordPress health check:
  - Detect `/wp-json`.
  - Authenticate through Application Password.
  - Check current user and minimum content permissions.
- Add WordPress content operations:
  - Publish article to `/wp/v2/posts`.
  - Update article by saved WordPress `post_id`.
  - Delete remote article by saved WordPress `post_id`.
  - Upload embedded local images to `/wp/v2/media` when payload includes `content_base64`.
  - Match or create categories and tags.
  - Sync supported site settings to `/wp/v2/settings`.
- Adjust admin UI:
  - Hide target-site package and rewrite-rule modules for WordPress channels.
  - Show WordPress connection guide and Application Password instructions.
  - Keep distribution queue edit/delete/retry operations working for WordPress jobs.
- Add tests for channel creation, health checks, publishing, updating, deleting, image upload, category/tag behavior, and UI visibility.

### Deferred

- WordPress plugin-enhanced GEO/LLM features such as `llms.txt`, TXT maps, schema policy, SEO plugin fields, and remote template controls.
- WordPress.com OAuth flow. First implementation targets self-hosted WordPress or WordPress environments that expose standard `/wp-json/wp/v2/*` routes and Application Passwords.
- Full Gutenberg block generation. First implementation sends sanitized HTML generated from GEOFlow Markdown.
- Bidirectional WordPress-to-GEOFlow sync. First implementation is GEOFlow to WordPress.

---

## File Map

### Data Model

- Modify `database/migrations/2026_05_17_000000_create_distribution_management_tables.php`
  - Add `channel_config` JSON column when creating `distribution_channels`.
  - Add `remote_meta` JSON column when creating `article_distributions`.
- Create `database/migrations/2026_05_23_000000_add_wordpress_distribution_columns.php`
  - Add `distribution_channels.channel_config` if missing.
  - Add `article_distributions.remote_meta` if missing.
- Modify `app/Models/DistributionChannel.php`
  - Add `channel_config` to `$fillable`.
  - Cast `channel_config` to array.
  - Add helpers: `channelType()`, `isGeoFlowAgent()`, `isWordPressRest()`, `resolvedChannelConfig()`, `wordpressRestBaseUrl()`.
- Modify `app/Models/ArticleDistribution.php`
  - Add `remote_meta` to `$fillable`.
  - Cast `remote_meta` to array.
  - Add helpers for remote WordPress post ID.

### Publisher Layer

- Create `app/Services/GeoFlow/DistributionPublisherInterface.php`
  - Common publisher contract.
- Create `app/Services/GeoFlow/DistributionPublisherManager.php`
  - Selects publisher by `channel_type`.
- Create `app/Services/GeoFlow/GeoFlowAgentPublisher.php`
  - Wraps existing `DistributionHttpClient` behavior for current Agent channels.
- Create `app/Services/GeoFlow/WordPressRestPublisher.php`
  - Implements WordPress REST health, publish, update, delete, settings sync.
- Create `app/Services/GeoFlow/WordPressMediaSyncService.php`
  - Uploads payload images to WordPress media library and rewrites content HTML.
- Create `app/Services/GeoFlow/WordPressTaxonomySyncService.php`
  - Matches or creates categories and tags.
- Modify `app/Services/GeoFlow/DistributionOrchestrator.php`
  - Replace direct `DistributionHttpClient` calls with `DistributionPublisherManager`.
- Keep `app/Services/GeoFlow/DistributionHttpClient.php`
  - Existing signed Agent client remains available to `GeoFlowAgentPublisher`.

### Admin Controller And UI

- Modify `app/Http/Controllers/Admin/DistributionController.php`
  - Validate `channel_type`.
  - Validate WordPress-specific config.
  - Store WordPress username in `channel_config`.
  - Store Application Password encrypted in `DistributionChannelSecret`.
  - Do not generate GEOFlow `gfsec_*` secrets for WordPress.
  - Call publisher manager for health and site settings sync.
- Modify `resources/views/admin/distribution/create.blade.php`
  - Add channel type selector.
  - Add WordPress fieldset.
  - Hide front mode / target package fields when WordPress is selected.
- Modify `resources/views/admin/distribution/edit.blade.php`
  - Same conditional fieldsets.
  - Disable changing channel type after creation unless no jobs exist.
- Modify `resources/views/admin/distribution/show.blade.php`
  - Show WordPress connection summary.
  - Hide target-site package and rewrite-rule blocks for WordPress.
  - Show WordPress access guide.
- Modify `resources/views/admin/distribution/index.blade.php`
  - Show channel type badge.
- Modify `resources/views/admin/distribution/_jobs-table.blade.php`
  - Keep remote edit/delete/retry actions, but label remote ID and URL safely.
- Modify `lang/zh_CN/admin.php`, `lang/en/admin.php`, `lang/ja/admin.php`, `lang/es/admin.php`, `lang/ru/admin.php`, `lang/pt_BR/admin.php`
  - Add labels and messages for WordPress channel type and validation.

### Tests

- Modify `tests/Unit/DistributionSchemaMigrationTest.php`.
- Create `tests/Unit/DistributionPublisherManagerTest.php`.
- Create `tests/Unit/WordPressRestPublisherTest.php`.
- Create `tests/Unit/WordPressTaxonomySyncServiceTest.php`.
- Create `tests/Unit/WordPressMediaSyncServiceTest.php`.
- Extend `tests/Feature/AdminDistributionPageTest.php`.

### Documentation

- Modify `docs/distribution/unified-distribution-implementation-plan.md`.
- Modify `docs/CHANGELOG.md` and `docs/CHANGELOG_en.md`.

---

## WordPress REST API Contract

### Authentication

Use WordPress Application Passwords through HTTP Basic Auth:

```php
Http::withBasicAuth($username, $applicationPassword)
    ->acceptJson()
    ->asJson();
```

The Application Password is not the WordPress login password. It is created in the WordPress user profile and can be revoked independently.

### Endpoint Resolution

Accepted input examples:

- `https://example.com`
- `https://example.com/`
- `https://example.com/wp-json`
- `https://example.com/blog`
- `https://example.com/blog/wp-json`

Normalize to:

```php
$base = rtrim($channel->endpoint_url, '/');
$restBase = str_ends_with($base, '/wp-json') ? $base : $base.'/wp-json';
```

Posts endpoint:

```text
POST   {restBase}/wp/v2/posts
POST   {restBase}/wp/v2/posts/{post_id}
DELETE {restBase}/wp/v2/posts/{post_id}?force=false
```

Media endpoint:

```text
POST {restBase}/wp/v2/media
```

Categories and tags:

```text
GET  {restBase}/wp/v2/categories?slug={slug}
POST {restBase}/wp/v2/categories
GET  {restBase}/wp/v2/tags?slug={slug}
POST {restBase}/wp/v2/tags
```

Settings:

```text
GET  {restBase}/wp/v2/settings
POST {restBase}/wp/v2/settings
```

---

## Tasks

### Task 1: Add Schema Support For WordPress Channel Config And Remote Metadata

**Files:**

- Modify: `database/migrations/2026_05_17_000000_create_distribution_management_tables.php`
- Create: `database/migrations/2026_05_23_000000_add_wordpress_distribution_columns.php`
- Modify: `app/Models/DistributionChannel.php`
- Modify: `app/Models/ArticleDistribution.php`
- Modify: `tests/Unit/DistributionSchemaMigrationTest.php`

- [ ] **Step 1: Write schema test expectations**

Add assertions to `tests/Unit/DistributionSchemaMigrationTest.php`:

```php
public function test_distribution_schema_contains_wordpress_channel_columns(): void
{
    $this->assertTrue(Schema::hasColumn('distribution_channels', 'channel_config'));
    $this->assertTrue(Schema::hasColumn('article_distributions', 'remote_meta'));
}
```

- [ ] **Step 2: Run schema test and verify it fails before migration change**

Run:

```bash
php artisan test tests/Unit/DistributionSchemaMigrationTest.php --filter=wordpress_channel_columns
```

Expected before implementation:

```text
FAILED  Tests\Unit\DistributionSchemaMigrationTest
Failed asserting that false is true.
```

- [ ] **Step 3: Add missing columns to initial create migration**

In `database/migrations/2026_05_17_000000_create_distribution_management_tables.php`, add:

```php
$table->json('channel_config')->nullable();
```

after `site_settings` in `distribution_channels`.

Add:

```php
$table->json('remote_meta')->nullable();
```

after `remote_url` in `article_distributions`.

- [ ] **Step 4: Add forward-compatible migration**

Create `database/migrations/2026_05_23_000000_add_wordpress_distribution_columns.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_channels') && ! Schema::hasColumn('distribution_channels', 'channel_config')) {
            Schema::table('distribution_channels', function (Blueprint $table): void {
                $table->json('channel_config')->nullable()->after('site_settings');
            });
        }

        if (Schema::hasTable('article_distributions') && ! Schema::hasColumn('article_distributions', 'remote_meta')) {
            Schema::table('article_distributions', function (Blueprint $table): void {
                $table->json('remote_meta')->nullable()->after('remote_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('article_distributions') && Schema::hasColumn('article_distributions', 'remote_meta')) {
            Schema::table('article_distributions', function (Blueprint $table): void {
                $table->dropColumn('remote_meta');
            });
        }

        if (Schema::hasTable('distribution_channels') && Schema::hasColumn('distribution_channels', 'channel_config')) {
            Schema::table('distribution_channels', function (Blueprint $table): void {
                $table->dropColumn('channel_config');
            });
        }
    }
};
```

- [ ] **Step 5: Update models**

In `DistributionChannel`, add `channel_config` to `$fillable` and casts:

```php
'channel_config',
```

```php
'channel_config' => 'array',
```

Add helpers:

```php
public function channelType(): string
{
    $type = (string) ($this->channel_type ?? 'geoflow_agent');

    return in_array($type, ['geoflow_agent', 'wordpress_rest'], true) ? $type : 'geoflow_agent';
}

public function isGeoFlowAgent(): bool
{
    return $this->channelType() === 'geoflow_agent';
}

public function isWordPressRest(): bool
{
    return $this->channelType() === 'wordpress_rest';
}

/**
 * @return array<string,mixed>
 */
public function resolvedChannelConfig(): array
{
    $stored = is_array($this->channel_config) ? $this->channel_config : [];

    return [
        'wordpress_username' => trim((string) ($stored['wordpress_username'] ?? '')),
        'wordpress_post_status' => in_array((string) ($stored['wordpress_post_status'] ?? 'publish'), ['publish', 'draft', 'pending', 'private'], true)
            ? (string) $stored['wordpress_post_status']
            : 'publish',
        'wordpress_category_strategy' => in_array((string) ($stored['wordpress_category_strategy'] ?? 'match_or_create'), ['match_or_create', 'match_only', 'fixed'], true)
            ? (string) $stored['wordpress_category_strategy']
            : 'match_or_create',
        'wordpress_fixed_category' => trim((string) ($stored['wordpress_fixed_category'] ?? '')),
        'wordpress_tag_strategy' => in_array((string) ($stored['wordpress_tag_strategy'] ?? 'keywords_to_tags'), ['keywords_to_tags', 'disabled'], true)
            ? (string) $stored['wordpress_tag_strategy']
            : 'keywords_to_tags',
        'wordpress_image_strategy' => in_array((string) ($stored['wordpress_image_strategy'] ?? 'upload_to_media'), ['upload_to_media', 'keep_original'], true)
            ? (string) $stored['wordpress_image_strategy']
            : 'upload_to_media',
        'wordpress_content_format' => 'html',
    ];
}

public function wordpressRestBaseUrl(): string
{
    $base = rtrim((string) $this->endpoint_url, '/');

    return str_ends_with($base, '/wp-json') ? $base : $base.'/wp-json';
}
```

In `ArticleDistribution`, add `remote_meta` to `$fillable`, cast to array, and add:

```php
public function wordpressPostId(): ?int
{
    if ($this->remote_id !== null && ctype_digit((string) $this->remote_id)) {
        return (int) $this->remote_id;
    }

    $meta = is_array($this->remote_meta) ? $this->remote_meta : [];
    $postId = $meta['wordpress_post_id'] ?? null;

    return is_numeric($postId) ? (int) $postId : null;
}
```

- [ ] **Step 6: Run schema test**

Run:

```bash
php artisan test tests/Unit/DistributionSchemaMigrationTest.php
```

Expected:

```text
PASS
```

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_05_17_000000_create_distribution_management_tables.php database/migrations/2026_05_23_000000_add_wordpress_distribution_columns.php app/Models/DistributionChannel.php app/Models/ArticleDistribution.php tests/Unit/DistributionSchemaMigrationTest.php
git commit -m "Add WordPress distribution schema support"
```

### Task 2: Introduce Distribution Publisher Interface And Manager

**Files:**

- Create: `app/Services/GeoFlow/DistributionPublisherInterface.php`
- Create: `app/Services/GeoFlow/DistributionPublisherManager.php`
- Create: `app/Services/GeoFlow/GeoFlowAgentPublisher.php`
- Modify: `app/Services/GeoFlow/DistributionOrchestrator.php`
- Create: `tests/Unit/DistributionPublisherManagerTest.php`

- [ ] **Step 1: Write manager test**

Create `tests/Unit/DistributionPublisherManagerTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\DistributionChannel;
use App\Services\GeoFlow\DistributionPublisherManager;
use App\Services\GeoFlow\GeoFlowAgentPublisher;
use App\Services\GeoFlow\WordPressRestPublisher;
use Tests\TestCase;

class DistributionPublisherManagerTest extends TestCase
{
    public function test_it_resolves_geoflow_agent_publisher_by_default(): void
    {
        $channel = new DistributionChannel(['channel_type' => 'geoflow_agent']);
        $manager = app(DistributionPublisherManager::class);

        $this->assertInstanceOf(GeoFlowAgentPublisher::class, $manager->forChannel($channel));
    }

    public function test_it_resolves_wordpress_rest_publisher(): void
    {
        $channel = new DistributionChannel(['channel_type' => 'wordpress_rest']);
        $manager = app(DistributionPublisherManager::class);

        $this->assertInstanceOf(WordPressRestPublisher::class, $manager->forChannel($channel));
    }
}
```

This test references `WordPressRestPublisher`, which is created in Task 3. It can remain failing until Task 3 if this task is implemented first.

- [ ] **Step 2: Create publisher interface**

Create `app/Services/GeoFlow/DistributionPublisherInterface.php`:

```php
<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;

interface DistributionPublisherInterface
{
    /**
     * @return array<string,mixed>
     */
    public function health(DistributionChannel $channel): array;

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function publish(ArticleDistribution $distribution, array $payload): array;

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function update(ArticleDistribution $distribution, array $payload): array;

    /**
     * @return array<string,mixed>
     */
    public function delete(ArticleDistribution $distribution): array;

    /**
     * @return array<string,mixed>
     */
    public function syncSiteSettings(DistributionChannel $channel): array;
}
```

- [ ] **Step 3: Wrap existing Agent client**

Create `app/Services/GeoFlow/GeoFlowAgentPublisher.php`:

```php
<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;

class GeoFlowAgentPublisher implements DistributionPublisherInterface
{
    public function __construct(private readonly DistributionHttpClient $httpClient) {}

    public function health(DistributionChannel $channel): array
    {
        return $this->httpClient->health($channel);
    }

    public function publish(ArticleDistribution $distribution, array $payload): array
    {
        return $this->httpClient->send($distribution, $payload);
    }

    public function update(ArticleDistribution $distribution, array $payload): array
    {
        return $this->httpClient->updateArticle($distribution, $payload);
    }

    public function delete(ArticleDistribution $distribution): array
    {
        return $this->httpClient->deleteArticle($distribution);
    }

    public function syncSiteSettings(DistributionChannel $channel): array
    {
        return $this->httpClient->syncSiteSettings($channel);
    }
}
```

- [ ] **Step 4: Create manager**

Create `app/Services/GeoFlow/DistributionPublisherManager.php`:

```php
<?php

namespace App\Services\GeoFlow;

use App\Models\DistributionChannel;
use RuntimeException;

class DistributionPublisherManager
{
    public function __construct(
        private readonly GeoFlowAgentPublisher $geoFlowAgentPublisher,
        private readonly WordPressRestPublisher $wordPressRestPublisher,
    ) {}

    public function forChannel(DistributionChannel $channel): DistributionPublisherInterface
    {
        return match ($channel->channelType()) {
            'geoflow_agent' => $this->geoFlowAgentPublisher,
            'wordpress_rest' => $this->wordPressRestPublisher,
            default => throw new RuntimeException('不支持的分发渠道类型：'.(string) $channel->channel_type),
        };
    }
}
```

- [ ] **Step 5: Update orchestrator injection and calls**

Change `DistributionOrchestrator` constructor from:

```php
private readonly DistributionHttpClient $httpClient
```

to:

```php
private readonly DistributionPublisherManager $publisherManager
```

Change health:

```php
return $this->publisherManager->forChannel($channel)->health($channel);
```

Change process response selection:

```php
$publisher = $this->publisherManager->forChannel($channel);
$response = match ((string) $distribution->action) {
    'update' => $publisher->update($distribution, $payload),
    'delete' => $publisher->delete($distribution),
    default => $publisher->publish($distribution, $payload),
};
```

Change immediate action response:

```php
$publisher = $this->publisherManager->forChannel($channel);
$response = $action === 'delete'
    ? $publisher->delete($distribution)
    : $publisher->update($distribution, $payload);
```

- [ ] **Step 6: Run existing distribution tests**

Run:

```bash
php artisan test tests/Feature/AdminDistributionPageTest.php tests/Unit/DistributionQueueConfigurationTest.php tests/Unit/DistributionRetryPolicyTest.php
```

Expected:

```text
PASS
```

- [ ] **Step 7: Commit**

```bash
git add app/Services/GeoFlow/DistributionPublisherInterface.php app/Services/GeoFlow/DistributionPublisherManager.php app/Services/GeoFlow/GeoFlowAgentPublisher.php app/Services/GeoFlow/DistributionOrchestrator.php tests/Unit/DistributionPublisherManagerTest.php
git commit -m "Introduce distribution publisher abstraction"
```

### Task 3: Implement WordPress REST Publisher

**Files:**

- Create: `app/Services/GeoFlow/WordPressRestPublisher.php`
- Create: `tests/Unit/WordPressRestPublisherTest.php`
- Modify: `app/Services/GeoFlow/DistributionPublisherManager.php`

- [ ] **Step 1: Write publish/update/delete HTTP tests**

Create `tests/Unit/WordPressRestPublisherTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\WordPressRestPublisher;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WordPressRestPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_article_to_wordpress_posts_endpoint(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world/',
            ], 201),
        ]);

        [$channel, $distribution] = $this->makeDistribution();

        $result = app(WordPressRestPublisher::class)->publish($distribution, [
            'article' => [
                'title' => 'Hello World',
                'slug' => 'hello-world',
                'excerpt' => 'Short summary',
                'content_html' => '<p>Hello</p>',
                'keywords' => 'geo, ai',
                'meta_description' => 'Meta summary',
            ],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('123', (string) $result['remote_id']);
        $this->assertSame('https://wp.example.com/hello-world/', $result['remote_url']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://wp.example.com/wp-json/wp/v2/posts'
                && $request['title'] === 'Hello World'
                && $request['status'] === 'publish'
                && $request['content'] === '<p>Hello</p>';
        });
    }

    public function test_it_updates_existing_wordpress_post_id(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts/123' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world-updated/',
            ]),
        ]);

        [$channel, $distribution] = $this->makeDistribution(['remote_id' => '123']);

        $result = app(WordPressRestPublisher::class)->update($distribution, [
            'article' => [
                'title' => 'Hello Updated',
                'slug' => 'hello-world',
                'excerpt' => '',
                'content_html' => '<p>Updated</p>',
                'keywords' => '',
                'meta_description' => '',
            ],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('123', (string) $result['remote_id']);
        $this->assertSame('https://wp.example.com/hello-world-updated/', $result['remote_url']);
    }

    public function test_it_deletes_existing_wordpress_post_id(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts/123*' => Http::response([
                'deleted' => false,
                'previous' => ['id' => 123],
            ]),
        ]);

        [$channel, $distribution] = $this->makeDistribution(['remote_id' => '123']);

        $result = app(WordPressRestPublisher::class)->delete($distribution);

        $this->assertSame('123', (string) $result['remote_id']);
        $this->assertTrue($result['deleted']);
    }

    public function test_health_uses_authenticated_current_user_endpoint(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/' => Http::response(['name' => 'WordPress']),
            'https://wp.example.com/wp-json/wp/v2/users/me*' => Http::response([
                'id' => 7,
                'name' => 'Editor',
                'capabilities' => ['edit_posts' => true, 'publish_posts' => true, 'upload_files' => true],
            ]),
        ]);

        [$channel] = $this->makeDistribution();

        $result = app(WordPressRestPublisher::class)->health($channel);

        $this->assertTrue($result['ok']);
        $this->assertSame('wordpress_rest', $result['channel_type']);
        $this->assertSame(7, $result['user_id']);
    }

    /**
     * @param  array<string,mixed>  $distributionOverrides
     * @return array{0:DistributionChannel,1:ArticleDistribution}
     */
    private function makeDistribution(array $distributionOverrides = []): array
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'WP',
            'domain' => 'wp.example.com',
            'endpoint_url' => 'https://wp.example.com',
            'channel_type' => 'wordpress_rest',
            'channel_config' => [
                'wordpress_username' => 'editor',
                'wordpress_post_status' => 'publish',
                'wordpress_category_strategy' => 'match_or_create',
                'wordpress_tag_strategy' => 'keywords_to_tags',
                'wordpress_image_strategy' => 'keep_original',
            ],
            'status' => 'active',
        ]);

        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'wp_test',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('app password'),
            'status' => 'active',
            'scopes' => ['wordpress.rest'],
        ]);

        $article = Article::factory()->create([
            'title' => 'Hello World',
            'slug' => 'hello-world',
            'status' => 'published',
        ]);

        $distribution = ArticleDistribution::query()->create(array_merge([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'wp-test-key',
        ], $distributionOverrides));

        return [$channel, $distribution];
    }
}
```

- [ ] **Step 2: Create publisher implementation**

Create `app/Services/GeoFlow/WordPressRestPublisher.php`:

```php
<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WordPressRestPublisher implements DistributionPublisherInterface
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly WordPressMediaSyncService $mediaSyncService,
        private readonly WordPressTaxonomySyncService $taxonomySyncService,
    ) {}

    public function health(DistributionChannel $channel): array
    {
        $this->request($channel)->get($channel->wordpressRestBaseUrl())->throw();

        $response = $this->request($channel)
            ->get($channel->wordpressRestBaseUrl().'/wp/v2/users/me', ['context' => 'edit']);
        $this->throwIfFailed($response, 'WordPress 健康检查');
        $user = $response->json();
        $capabilities = is_array($user['capabilities'] ?? null) ? $user['capabilities'] : [];

        return [
            'ok' => true,
            'channel_type' => 'wordpress_rest',
            'rest_base_url' => $channel->wordpressRestBaseUrl(),
            'user_id' => (int) ($user['id'] ?? 0),
            'user_name' => (string) ($user['name'] ?? ''),
            'can_edit_posts' => (bool) ($capabilities['edit_posts'] ?? false),
            'can_publish_posts' => (bool) ($capabilities['publish_posts'] ?? false),
            'can_upload_files' => (bool) ($capabilities['upload_files'] ?? false),
        ];
    }

    public function publish(ArticleDistribution $distribution, array $payload): array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $postPayload = $this->postPayload($channel, $distribution, $payload);

        $response = $this->request($channel)->post($channel->wordpressRestBaseUrl().'/wp/v2/posts', $postPayload);
        $this->throwIfFailed($response, 'WordPress 文章发布');

        return $this->postResult($response);
    }

    public function update(ArticleDistribution $distribution, array $payload): array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $postId = $distribution->wordpressPostId();
        if (! $postId) {
            return $this->publish($distribution, $payload);
        }

        $response = $this->request($channel)
            ->post($channel->wordpressRestBaseUrl().'/wp/v2/posts/'.$postId, $this->postPayload($channel, $distribution, $payload));
        $this->throwIfFailed($response, 'WordPress 文章更新');

        return $this->postResult($response);
    }

    public function delete(ArticleDistribution $distribution): array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $postId = $distribution->wordpressPostId();
        if (! $postId) {
            return ['deleted' => true, 'remote_id' => null, 'remote_url' => null, 'message' => 'missing_remote_post_id'];
        }

        $response = $this->request($channel)
            ->delete($channel->wordpressRestBaseUrl().'/wp/v2/posts/'.$postId, ['force' => false]);
        $this->throwIfFailed($response, 'WordPress 文章删除');

        return ['deleted' => true, 'remote_id' => (string) $postId, 'remote_url' => null];
    }

    public function syncSiteSettings(DistributionChannel $channel): array
    {
        $settings = $channel->resolvedSiteSettings();
        $payload = [
            'title' => $settings['site_name'],
            'description' => $settings['site_description'],
            'posts_per_page' => $settings['per_page'],
        ];

        $response = $this->request($channel)->post($channel->wordpressRestBaseUrl().'/wp/v2/settings', $payload);
        $this->throwIfFailed($response, 'WordPress 站点设置同步');

        return ['ok' => true, 'settings' => $payload];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function postPayload(DistributionChannel $channel, ArticleDistribution $distribution, array $payload): array
    {
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $config = $channel->resolvedChannelConfig();
        $contentHtml = (string) ($article['content_html'] ?? '');

        if ($config['wordpress_image_strategy'] === 'upload_to_media') {
            $contentHtml = $this->mediaSyncService->rewriteContentImages($channel, $payload, $contentHtml);
        }

        $postPayload = [
            'title' => (string) ($article['title'] ?? ''),
            'slug' => (string) ($article['slug'] ?? ''),
            'status' => (string) $config['wordpress_post_status'],
            'content' => $contentHtml,
            'excerpt' => (string) ($article['excerpt'] ?? ''),
        ];

        $categoryIds = $this->taxonomySyncService->categoryIds($channel, $payload);
        if ($categoryIds !== []) {
            $postPayload['categories'] = $categoryIds;
        }

        $tagIds = $this->taxonomySyncService->tagIds($channel, $payload);
        if ($tagIds !== []) {
            $postPayload['tags'] = $tagIds;
        }

        return $postPayload;
    }

    private function request(DistributionChannel $channel): PendingRequest
    {
        $channel->loadMissing('activeSecret');
        $config = $channel->resolvedChannelConfig();
        $username = (string) $config['wordpress_username'];
        $secret = $channel->activeSecret;
        if (! $secret instanceof DistributionChannelSecret || $username === '') {
            throw new RuntimeException('WordPress 渠道缺少用户名或 Application Password。');
        }

        $applicationPassword = $this->apiKeyCrypto->decrypt((string) $secret->secret_ciphertext);
        if ($applicationPassword === '') {
            throw new RuntimeException('WordPress Application Password 解密失败。');
        }

        return Http::timeout(30)->acceptJson()->asJson()->withBasicAuth($username, $applicationPassword);
    }

    private function channel(ArticleDistribution $distribution): DistributionChannel
    {
        if (! $distribution->channel instanceof DistributionChannel) {
            throw new RuntimeException('分发记录缺少 WordPress 渠道。');
        }

        return $distribution->channel;
    }

    private function throwIfFailed(Response $response, string $operation): void
    {
        if (! $response->failed()) {
            return;
        }

        $body = trim(strip_tags((string) $response->body()));
        $summary = mb_strlen($body) > 300 ? mb_substr($body, 0, 300).'...' : $body;

        throw new RuntimeException($operation.'失败：HTTP '.$response->status().($summary !== '' ? ' '.$summary : ''));
    }

    /**
     * @return array<string,mixed>
     */
    private function postResult(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('WordPress 返回内容不是有效 JSON。');
        }

        return [
            'remote_id' => (string) ($json['id'] ?? ''),
            'remote_url' => (string) ($json['link'] ?? ''),
            'remote_meta' => [
                'wordpress_post_id' => (int) ($json['id'] ?? 0),
            ],
        ];
    }
}
```

- [ ] **Step 3: Run tests**

Run:

```bash
php artisan test tests/Unit/WordPressRestPublisherTest.php tests/Unit/DistributionPublisherManagerTest.php
```

Expected:

```text
PASS
```

- [ ] **Step 4: Commit**

```bash
git add app/Services/GeoFlow/WordPressRestPublisher.php tests/Unit/WordPressRestPublisherTest.php app/Services/GeoFlow/DistributionPublisherManager.php
git commit -m "Add WordPress REST distribution publisher"
```

### Task 4: Persist Remote Metadata From Publisher Responses

**Files:**

- Modify: `app/Services/GeoFlow/DistributionOrchestrator.php`
- Modify: `tests/Unit/WordPressRestPublisherTest.php`

- [ ] **Step 1: Add assertion to publish test**

In the WordPress publisher publish integration path or a new orchestrator test, assert that `remote_meta.wordpress_post_id` is stored after a successful process:

```php
$distribution->refresh();
$this->assertSame(123, $distribution->remote_meta['wordpress_post_id'] ?? null);
```

- [ ] **Step 2: Update orchestrator save blocks**

In `DistributionOrchestrator::process()` and `sendImmediateAction()`, merge `remote_meta` from response:

```php
$existingMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
$responseMeta = is_array($response['remote_meta'] ?? null) ? $response['remote_meta'] : [];
```

Then add to `forceFill`:

```php
'remote_meta' => array_replace($existingMeta, $responseMeta),
```

- [ ] **Step 3: Run distribution tests**

Run:

```bash
php artisan test tests/Feature/AdminDistributionPageTest.php tests/Unit/WordPressRestPublisherTest.php
```

Expected:

```text
PASS
```

- [ ] **Step 4: Commit**

```bash
git add app/Services/GeoFlow/DistributionOrchestrator.php tests/Unit/WordPressRestPublisherTest.php
git commit -m "Persist distribution remote metadata"
```

### Task 5: Add WordPress Taxonomy Sync

**Files:**

- Create: `app/Services/GeoFlow/WordPressTaxonomySyncService.php`
- Create: `tests/Unit/WordPressTaxonomySyncServiceTest.php`
- Modify: `app/Services/GeoFlow/WordPressRestPublisher.php`

- [ ] **Step 1: Write taxonomy tests**

Create tests that cover:

```php
public function test_it_matches_existing_category_by_slug(): void
public function test_it_creates_category_when_strategy_is_match_or_create(): void
public function test_it_returns_empty_categories_when_match_only_misses(): void
public function test_it_converts_keywords_to_wordpress_tags(): void
public function test_it_skips_tags_when_tag_strategy_disabled(): void
```

Use `Http::fake()` for:

```text
GET  https://wp.example.com/wp-json/wp/v2/categories?slug=geo
POST https://wp.example.com/wp-json/wp/v2/categories
GET  https://wp.example.com/wp-json/wp/v2/tags?slug=ai
POST https://wp.example.com/wp-json/wp/v2/tags
```

- [ ] **Step 2: Implement taxonomy service**

Create `WordPressTaxonomySyncService` with:

```php
public function categoryIds(DistributionChannel $channel, array $payload): array
public function tagIds(DistributionChannel $channel, array $payload): array
private function findTermId(DistributionChannel $channel, string $taxonomy, string $slug): ?int
private function createTermId(DistributionChannel $channel, string $taxonomy, string $name, string $slug): ?int
```

Slug normalization:

```php
private function slug(string $value): string
{
    $slug = Str::slug($value);

    return $slug !== '' ? $slug : substr(hash('sha256', $value), 0, 12);
}
```

Keyword splitting:

```php
preg_split('/[,，;；\n]+/u', $keywords)
```

- [ ] **Step 3: Inject service into publisher**

`WordPressRestPublisher::postPayload()` already calls:

```php
$this->taxonomySyncService->categoryIds($channel, $payload);
$this->taxonomySyncService->tagIds($channel, $payload);
```

Ensure the service uses the same Basic Auth request pattern as the publisher. If duplication becomes noticeable, extract `WordPressRestRequestFactory` in this task instead of copying credential resolution.

- [ ] **Step 4: Run tests**

```bash
php artisan test tests/Unit/WordPressTaxonomySyncServiceTest.php tests/Unit/WordPressRestPublisherTest.php
```

Expected:

```text
PASS
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/GeoFlow/WordPressTaxonomySyncService.php app/Services/GeoFlow/WordPressRestPublisher.php tests/Unit/WordPressTaxonomySyncServiceTest.php tests/Unit/WordPressRestPublisherTest.php
git commit -m "Sync WordPress categories and tags"
```

### Task 6: Add WordPress Media Upload And Content Image Rewriting

**Files:**

- Create: `app/Services/GeoFlow/WordPressMediaSyncService.php`
- Create: `tests/Unit/WordPressMediaSyncServiceTest.php`
- Modify: `app/Services/GeoFlow/WordPressRestPublisher.php`

- [ ] **Step 1: Write media sync tests**

Cover:

```php
public function test_it_uploads_base64_image_asset_to_wordpress_media(): void
public function test_it_rewrites_content_html_image_src_to_wordpress_media_url(): void
public function test_it_keeps_original_src_when_asset_has_no_base64_content(): void
public function test_it_skips_upload_when_image_strategy_is_keep_original(): void
```

Expected fake upload:

```php
Http::fake([
    'https://wp.example.com/wp-json/wp/v2/media' => Http::response([
        'id' => 456,
        'source_url' => 'https://wp.example.com/wp-content/uploads/image.jpg',
    ], 201),
]);
```

- [ ] **Step 2: Implement media sync service**

The service should:

- Read `assets.images`.
- Only upload images with `content_base64`.
- Send binary body to `/wp/v2/media`.
- Set headers:

```php
[
    'Content-Disposition' => 'attachment; filename="'.$filename.'"',
    'Content-Type' => $mimeType,
]
```

- Replace exact original `source_url` occurrences in `content_html`.
- Return unchanged HTML if upload fails for a non-critical image, but include warning context in the publisher response when possible.

- [ ] **Step 3: Run tests**

```bash
php artisan test tests/Unit/WordPressMediaSyncServiceTest.php tests/Unit/WordPressRestPublisherTest.php
```

Expected:

```text
PASS
```

- [ ] **Step 4: Commit**

```bash
git add app/Services/GeoFlow/WordPressMediaSyncService.php app/Services/GeoFlow/WordPressRestPublisher.php tests/Unit/WordPressMediaSyncServiceTest.php tests/Unit/WordPressRestPublisherTest.php
git commit -m "Upload WordPress media during distribution"
```

### Task 7: Add Admin Forms For WordPress Channels

**Files:**

- Modify: `app/Http/Controllers/Admin/DistributionController.php`
- Modify: `resources/views/admin/distribution/create.blade.php`
- Modify: `resources/views/admin/distribution/edit.blade.php`
- Modify: `resources/views/admin/distribution/show.blade.php`
- Modify: `resources/views/admin/distribution/index.blade.php`
- Modify: `lang/zh_CN/admin.php`
- Modify: `lang/en/admin.php`
- Modify: `lang/ja/admin.php`
- Modify: `lang/es/admin.php`
- Modify: `lang/ru/admin.php`
- Modify: `lang/pt_BR/admin.php`
- Extend: `tests/Feature/AdminDistributionPageTest.php`

- [ ] **Step 1: Write feature tests**

Add tests:

```php
public function test_admin_can_create_wordpress_distribution_channel(): void
public function test_wordpress_distribution_channel_form_shows_wordpress_fields(): void
public function test_wordpress_distribution_detail_hides_target_site_package(): void
public function test_wordpress_distribution_detail_shows_connection_guide(): void
public function test_wordpress_distribution_channel_requires_application_password_on_create(): void
```

Expected database assertion:

```php
$this->assertDatabaseHas('distribution_channels', [
    'name' => 'WordPress 站点',
    'channel_type' => 'wordpress_rest',
    'endpoint_url' => 'https://wp.example.com',
]);
```

Expected config assertion:

```php
$channel = DistributionChannel::query()->where('name', 'WordPress 站点')->firstOrFail();
$this->assertSame('editor', $channel->resolvedChannelConfig()['wordpress_username']);
```

- [ ] **Step 2: Validate and persist channel type**

In `validateChannel()`, add:

```php
'channel_type' => ['nullable', 'string', 'in:geoflow_agent,wordpress_rest'],
'wordpress_username' => ['nullable', 'string', 'max:120'],
'wordpress_application_password' => ['nullable', 'string', 'max:255'],
'wordpress_post_status' => ['nullable', 'string', 'in:publish,draft,pending,private'],
'wordpress_category_strategy' => ['nullable', 'string', 'in:match_or_create,match_only,fixed'],
'wordpress_fixed_category' => ['nullable', 'string', 'max:120'],
'wordpress_tag_strategy' => ['nullable', 'string', 'in:keywords_to_tags,disabled'],
'wordpress_image_strategy' => ['nullable', 'string', 'in:upload_to_media,keep_original'],
```

Require username and application password on WordPress create:

```php
if (($payload['channel_type'] ?? 'geoflow_agent') === 'wordpress_rest') {
    if (! filled($payload['wordpress_username'] ?? null)) {
        throw ValidationException::withMessages(['wordpress_username' => __('admin.distribution.validation.wordpress_username')]);
    }
    if ($request->isMethod('post') && ! filled($payload['wordpress_application_password'] ?? null)) {
        throw ValidationException::withMessages(['wordpress_application_password' => __('admin.distribution.validation.wordpress_application_password')]);
    }
}
```

- [ ] **Step 3: Store WordPress config and secret**

Set `channel_type` from payload in `store()` and `update()`.

For WordPress create, create secret with:

```php
DistributionChannelSecret::query()->create([
    'distribution_channel_id' => (int) $channel->id,
    'key_id' => 'wp_'.Str::lower(Str::random(18)),
    'secret_ciphertext' => $this->apiKeyCrypto->encrypt((string) $payload['wordpress_application_password']),
    'status' => 'active',
    'scopes' => ['wordpress.rest'],
]);
```

For WordPress update, only replace secret if `wordpress_application_password` is filled.

- [ ] **Step 4: Add conditional Blade fieldsets**

In create/edit forms:

- Channel type segmented radio.
- Existing GEOFlow Agent fields visible for `geoflow_agent`.
- WordPress fields visible for `wordpress_rest`.

Use simple data attributes and a small inline script:

```html
data-channel-type-panel="geoflow_agent"
data-channel-type-panel="wordpress_rest"
```

```js
document.addEventListener('change', function (event) {
  if (!event.target.matches('[name="channel_type"]')) return;
  document.querySelectorAll('[data-channel-type-panel]').forEach(function (panel) {
    panel.classList.toggle('hidden', panel.dataset.channelTypePanel !== event.target.value);
  });
});
```

- [ ] **Step 5: Adjust detail page modules**

In `show.blade.php`:

```blade
@if ($channel->isGeoFlowAgent())
    {{-- target package, rewrite rules, Agent guide --}}
@endif

@if ($channel->isWordPressRest())
    {{-- WordPress guide --}}
@endif
```

WordPress guide copy:

- Create Application Password in WordPress user profile.
- Paste username and Application Password into GEOFlow.
- Click health check.
- Publish a test article as draft first.

- [ ] **Step 6: Run admin tests**

```bash
php artisan test tests/Feature/AdminDistributionPageTest.php
```

Expected:

```text
PASS
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/DistributionController.php resources/views/admin/distribution/create.blade.php resources/views/admin/distribution/edit.blade.php resources/views/admin/distribution/show.blade.php resources/views/admin/distribution/index.blade.php lang/zh_CN/admin.php lang/en/admin.php lang/ja/admin.php lang/es/admin.php lang/ru/admin.php lang/pt_BR/admin.php tests/Feature/AdminDistributionPageTest.php
git commit -m "Add WordPress distribution admin UI"
```

### Task 8: Add WordPress Health, Settings Sync, And Queue Integration Tests

**Files:**

- Extend: `tests/Feature/AdminDistributionPageTest.php`
- Extend: `tests/Unit/WordPressRestPublisherTest.php`
- Modify: `app/Http/Controllers/Admin/DistributionController.php`

- [ ] **Step 1: Add health feature test**

Feature test should:

- Create WordPress channel with active secret.
- Fake `/wp-json/` and `/wp/v2/users/me`.
- POST `admin.distribution.health`.
- Assert `last_health_status = ok`.

- [ ] **Step 2: Add settings sync test**

Fake:

```text
POST https://wp.example.com/wp-json/wp/v2/settings
```

Assert request contains:

```php
title
description
posts_per_page
```

- [ ] **Step 3: Add queue processing test**

Create a task bound to WordPress channel, publish article, call orchestrator process, assert:

```php
$this->assertDatabaseHas('article_distributions', [
    'status' => 'synced',
    'remote_id' => '123',
    'remote_url' => 'https://wp.example.com/article/',
]);
```

- [ ] **Step 4: Run tests**

```bash
php artisan test tests/Feature/AdminDistributionPageTest.php tests/Unit/WordPressRestPublisherTest.php
```

Expected:

```text
PASS
```

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/AdminDistributionPageTest.php tests/Unit/WordPressRestPublisherTest.php app/Http/Controllers/Admin/DistributionController.php
git commit -m "Cover WordPress distribution workflows"
```

### Task 9: Documentation And Final Regression

**Files:**

- Modify: `docs/distribution/unified-distribution-implementation-plan.md`
- Modify: `docs/CHANGELOG.md`
- Modify: `docs/CHANGELOG_en.md`

- [ ] **Step 1: Update docs**

Add a WordPress section:

```markdown
## WordPress 渠道

GEOFlow 2.x 支持通过 WordPress Core REST API 将文章分发到 WordPress 站点。管理员需要在 WordPress 用户资料页创建 Application Password，并在 GEOFlow 分发渠道中选择“WordPress 站点”后填写站点地址、用户名和 Application Password。

首版支持发布、更新、删除、图片上传到媒体库、分类/标签同步和基础站点设置同步。`llms.txt`、TXT 地图和 Schema 深度控制属于后续 WordPress Connector 插件增强能力。
```

- [ ] **Step 2: Update changelog**

`docs/CHANGELOG.md`:

```markdown
## 2026-05-23

### 分发管理

- 新增 WordPress REST API 分发渠道设计与实现：
  - 支持 Application Password 鉴权。
  - 支持文章发布、更新、删除、图片上传、分类/标签同步和基础站点设置同步。
  - 分发渠道后台按 GEOFlow Agent 与 WordPress 站点展示不同配置和接入引导。
```

`docs/CHANGELOG_en.md`:

```markdown
## 2026-05-23

### Distribution Management

- Added WordPress REST API distribution channel support:
  - Supports Application Password authentication.
  - Supports post publish, update, delete, media upload, category/tag sync, and basic site settings sync.
  - Shows different configuration and onboarding guidance for GEOFlow Agent and WordPress channels.
```

- [ ] **Step 3: Run focused regression**

```bash
php artisan test tests/Feature/AdminDistributionPageTest.php tests/Unit/DistributionQueueConfigurationTest.php tests/Unit/DistributionRetryPolicyTest.php tests/Unit/DistributionSchemaMigrationTest.php tests/Unit/DistributionPublisherManagerTest.php tests/Unit/WordPressRestPublisherTest.php tests/Unit/WordPressTaxonomySyncServiceTest.php tests/Unit/WordPressMediaSyncServiceTest.php
```

Expected:

```text
PASS
```

- [ ] **Step 4: Run code style and diff checks**

```bash
git diff --check
```

Expected: no output.

- [ ] **Step 5: Commit**

```bash
git add docs/distribution/unified-distribution-implementation-plan.md docs/CHANGELOG.md docs/CHANGELOG_en.md
git commit -m "Document WordPress distribution support"
```

---

## Rollout Notes

- Existing `geoflow_agent` channels must continue to publish exactly as before.
- WordPress channels should not show target-site package download, rewrite rules, or GEOFlow Agent secret instructions.
- WordPress Application Password should never be displayed after save. If reveal is needed later, reuse the current super-admin password confirmation flow.
- On first real WordPress test, use default post status `draft` to avoid publishing accidental public content.
- If WordPress returns `401` or `403`, show a specific message about Application Password, username, HTTPS, and user capabilities.
- If WordPress returns `rest_cannot_create`, show a message that the user role lacks `edit_posts` or `publish_posts`.

## Self-Review

- Spec coverage: Channel type, configuration, communication, content management, media, categories/tags, settings, UI, tests, and docs are covered.
- Placeholder scan: No implementation step relies on an unspecified file or unknown route.
- Type consistency: `channel_type=wordpress_rest`, `channel_config`, `remote_meta`, and `wordpressPostId()` names are consistent across schema, model, publisher, and tests.
