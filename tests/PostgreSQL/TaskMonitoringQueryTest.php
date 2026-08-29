<?php

namespace Tests\PostgreSQL;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaskMonitoringQueryTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    public function test_postgresql_task_page_counts_non_overridden_reviews_without_boolean_type_errors(): void
    {
        $admin = Admin::query()->create([
            'username' => 'postgres_task_monitoring_admin',
            'password' => 'secret-123',
            'email' => 'postgres-task-monitoring@example.test',
            'display_name' => 'PostgreSQL Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $aiModelId = DB::table('ai_models')->insertGetId([
            'name' => 'PostgreSQL monitoring model',
            'api_key' => 'test-key',
            'model_id' => 'test-model',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $promptId = DB::table('prompts')->insertGetId([
            'name' => 'PostgreSQL monitoring prompt',
            'type' => 'content',
            'content' => 'Write content.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $titleLibraryId = DB::table('title_libraries')->insertGetId([
            'name' => 'PostgreSQL monitoring title library',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $task = Task::query()->create([
            'name' => 'PostgreSQL quality monitoring task',
            'title_library_id' => $titleLibraryId,
            'prompt_id' => $promptId,
            'ai_model_id' => $aiModelId,
            'status' => 'paused',
        ]);
        $category = Category::query()->create([
            'name' => 'PostgreSQL monitoring category',
            'slug' => 'postgresql-monitoring-category',
        ]);
        $author = Author::query()->create([
            'name' => 'PostgreSQL Monitoring Author',
            'email' => 'postgres-monitoring-author@example.test',
            'bio' => '',
            'avatar' => '',
            'website' => '',
        ]);
        $article = Article::query()->create([
            'title' => 'PostgreSQL monitoring article',
            'slug' => 'postgresql-monitoring-article',
            'content' => 'PostgreSQL monitoring content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
        ArticleAiQualityCheck::query()->create([
            'article_id' => $article->id,
            'task_id' => $task->id,
            'request_key' => (string) Str::uuid(),
            'status' => 'completed',
            'decision' => 'needs_review',
            'input_fingerprint' => hash('sha256', 'postgresql-task-monitoring'),
            'algorithm_version' => 'test-v1',
            'is_overridden' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession([Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version])
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertViewHas('legacyError', static fn ($error): bool => $error === null)
            ->assertViewHas('tasks', static function (array $tasks) use ($task): bool {
                $row = collect($tasks)->firstWhere('id', (int) $task->id);

                return is_array($row)
                    && ($row['ai_quality_stats']['inspected'] ?? null) === 1
                    && ($row['ai_quality_stats']['needs_review'] ?? null) === 1;
            });
    }
}
