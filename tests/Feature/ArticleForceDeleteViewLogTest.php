<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ArticleForceDeleteViewLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_force_delete_preserves_view_logs_and_nulls_the_article_reference(): void
    {
        $admin = $this->createAdmin();
        $article = $this->createArticle('article-with-retained-view-logs');
        $viewLogId = DB::table('view_logs')->insertGetId([
            'article_id' => $article->id,
            'source' => 'local',
            'method' => 'GET',
            'path' => '/article/'.$article->slug,
            'route_name' => 'site.article',
            'status_code' => 200,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);
        $article->delete();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.articles.index', ['trashed' => 1]))
            ->post(route('admin.articles.batch.force-delete'), [
                'article_ids' => [$article->id],
            ])
            ->assertRedirect(route('admin.articles.index', ['trashed' => 1]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('message', __('admin.articles.trash.message.delete_success', ['count' => 1]));

        $this->assertNull(Article::withTrashed()->find($article->id));
        $this->assertDatabaseHas('view_logs', [
            'id' => $viewLogId,
            'article_id' => null,
        ]);

        $foreignKey = collect(Schema::getForeignKeys('view_logs'))
            ->first(static fn (array $key): bool => $key['columns'] === ['article_id']);

        $this->assertSame('set null', strtolower((string) ($foreignKey['on_delete'] ?? '')));
    }

    public function test_batch_force_delete_rolls_back_the_whole_batch_and_hides_database_details_on_failure(): void
    {
        $admin = $this->createAdmin();
        $firstArticle = $this->createArticle('first-article-in-failed-batch');
        $blockedArticle = $this->createArticle('blocked-article-in-failed-batch');
        $firstArticle->delete();
        $blockedArticle->delete();

        Schema::create('article_delete_blockers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->noActionOnDelete();
        });
        DB::table('article_delete_blockers')->insert(['article_id' => $blockedArticle->id]);

        $response = $this->actingAs($admin, 'admin')
            ->from(route('admin.articles.index', ['trashed' => 1]))
            ->post(route('admin.articles.batch.force-delete'), [
                'article_ids' => [$firstArticle->id, $blockedArticle->id],
            ]);

        $response
            ->assertRedirect(route('admin.articles.index', ['trashed' => 1]))
            ->assertSessionHasErrors();

        $errors = implode(' ', session('errors')->all());

        $this->assertSame(__('admin.articles.message.delete_failed_refresh'), $errors);
        $this->assertStringNotContainsString('SQLSTATE', $errors);
        $this->assertStringNotContainsString('Database:', $errors);
        $this->assertNotNull(Article::onlyTrashed()->find($firstArticle->id));
        $this->assertNotNull(Article::onlyTrashed()->find($blockedArticle->id));
    }

    public function test_batch_force_delete_rejects_oversized_id_lists(): void
    {
        $admin = $this->createAdmin();
        $article = $this->createArticle('article-in-oversized-delete-request');
        $article->delete();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.articles.index', ['trashed' => 1]))
            ->post(route('admin.articles.batch.force-delete'), [
                'article_ids' => range(1, 501),
            ])
            ->assertRedirect(route('admin.articles.index', ['trashed' => 1]))
            ->assertSessionHasErrors('article_ids');

        $this->assertNotNull(Article::onlyTrashed()->find($article->id));
    }

    public function test_empty_trash_deletes_all_articles_and_preserves_view_logs(): void
    {
        $admin = $this->createAdmin();
        $firstArticle = $this->createArticle('first-article-in-trash');
        $secondArticle = $this->createArticle('second-article-in-trash');
        $viewLogId = DB::table('view_logs')->insertGetId([
            'article_id' => $firstArticle->id,
            'source' => 'local',
            'method' => 'GET',
            'path' => '/article/'.$firstArticle->slug,
            'route_name' => 'site.article',
            'status_code' => 200,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);
        $firstArticle->delete();
        $secondArticle->delete();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.articles.index', ['trashed' => 1]))
            ->post(route('admin.articles.trash.empty'))
            ->assertRedirect(route('admin.articles.index', ['trashed' => 1]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('message', __('admin.articles.trash.message.empty_success', ['count' => 2]));

        $this->assertNull(Article::withTrashed()->find($firstArticle->id));
        $this->assertNull(Article::withTrashed()->find($secondArticle->id));
        $this->assertDatabaseHas('view_logs', [
            'id' => $viewLogId,
            'article_id' => null,
        ]);
    }

    public function test_permanent_delete_routes_are_rate_limited(): void
    {
        foreach ([
            'admin.articles.batch.force-delete',
            'admin.articles.trash.empty',
            'admin.articles.force-delete',
        ] as $routeName) {
            $this->assertContains(
                'throttle:admin-sensitive',
                Route::getRoutes()->getByName($routeName)->gatherMiddleware(),
            );
        }
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'article_force_delete_admin',
            'password' => 'secret-123',
            'email' => 'article-force-delete@example.test',
            'display_name' => 'Article Force Delete Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function createArticle(string $slug): Article
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'force-delete-category'],
            ['name' => 'Force delete category'],
        );
        $author = Author::query()->firstOrCreate(['name' => 'Force delete author']);

        return Article::query()->create([
            'title' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'content' => 'Content retained until permanent deletion.',
            'category_id' => $category->id,
            'author_id' => $author->id,
        ]);
    }
}
