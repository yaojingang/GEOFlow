<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Task;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class WorkerExecutionServiceAuthorFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_uses_existing_author_when_task_author_is_empty(): void
    {
        $author = Author::query()->create(['name' => 'Existing Author']);
        $task = Task::query()->create(['name' => 'Task without author']);

        $picked = $this->pickAuthor($task);

        $this->assertSame($author->id, $picked->id);
        $this->assertSame(1, Author::query()->count());
    }

    public function test_worker_falls_back_when_configured_author_is_missing(): void
    {
        $author = Author::query()->create(['name' => 'Fallback Author']);
        $task = Task::query()->create([
            'name' => 'Task with missing author',
            'author_id' => 99999,
        ]);

        $picked = $this->pickAuthor($task);

        $this->assertSame($author->id, $picked->id);
    }

    public function test_worker_creates_default_author_when_no_author_exists(): void
    {
        $task = Task::query()->create(['name' => 'Task without any author']);

        $picked = $this->pickAuthor($task);

        $this->assertSame('GEOFlow', $picked->name);
        $this->assertDatabaseHas('authors', [
            'id' => $picked->id,
            'name' => 'GEOFlow',
        ]);
    }

    private function pickAuthor(Task $task): Author
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'pickAuthor');
        $method->setAccessible(true);

        return $method->invoke($service, $task);
    }
}
