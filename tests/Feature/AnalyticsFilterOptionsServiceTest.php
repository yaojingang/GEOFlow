<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Services\Admin\Analytics\AnalyticsFilter;
use App\Services\Admin\Analytics\AnalyticsFilterOptionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnalyticsFilterOptionsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_old_task_and_article_remain_visible_beyond_the_latest_one_hundred_options(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');
        $author = Author::query()->create(['name' => '筛选作者', 'slug' => 'filter-author', 'status' => 'active']);
        $category = Category::query()->create(['name' => '筛选分类', 'slug' => 'filter-category', 'status' => 'active']);
        $oldTask = Task::query()->create(['name' => '最早任务', 'status' => 'active']);
        $oldTask->forceFill(['created_at' => Carbon::parse('2020-01-01')])->saveQuietly();
        $oldArticle = Article::query()->create([
            'title' => '最早文章',
            'slug' => 'oldest-filter-article',
            'content' => '正文',
            'author_id' => $author->id,
            'category_id' => $category->id,
            'task_id' => $oldTask->id,
            'status' => 'published',
        ]);
        $oldArticle->forceFill(['created_at' => Carbon::parse('2020-01-01')])->saveQuietly();

        foreach (range(1, 100) as $index) {
            $task = Task::query()->create(['name' => '近期任务 '.$index, 'status' => 'active']);
            Article::query()->create([
                'title' => '近期文章 '.$index,
                'slug' => 'recent-filter-article-'.$index,
                'content' => '正文',
                'author_id' => $author->id,
                'category_id' => $category->id,
                'task_id' => $task->id,
                'status' => 'published',
            ]);
        }

        $filter = AnalyticsFilter::fromRequest([
            'task_id' => $oldTask->id,
            'article_id' => $oldArticle->id,
        ]);
        $options = app(AnalyticsFilterOptionsService::class)->get(filter: $filter);

        $this->assertContains($oldTask->id, $options['tasks']->pluck('id')->all());
        $this->assertContains($oldArticle->id, $options['articles']->pluck('id')->all());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
