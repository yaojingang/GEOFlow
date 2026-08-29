<?php

namespace Tests\PostgreSQL;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ArticleViewLogForeignKeyMigrationTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    public function test_migration_replaces_the_legacy_restrictive_constraint_and_preserves_view_logs(): void
    {
        DB::statement('ALTER TABLE "view_logs" DROP CONSTRAINT "view_logs_article_id_foreign"');
        DB::statement(
            'ALTER TABLE "view_logs" ADD CONSTRAINT "view_logs_article_id_fkey" FOREIGN KEY ("article_id") REFERENCES "articles" ("id") ON DELETE NO ACTION',
        );

        $this->constraintMigration()->up();
        $this->cleanupMigration()->up();
        $this->validationMigration()->up();

        $foreignKey = $this->articleForeignKey();
        $constraint = DB::table('pg_constraint')
            ->where('conname', $foreignKey['name'])
            ->first(['confdeltype', 'convalidated']);

        $this->assertSame('view_logs_article_id_foreign', $foreignKey['name']);
        $this->assertSame('set null', strtolower((string) $foreignKey['on_delete']));
        $this->assertSame('n', $constraint->confdeltype);
        $this->assertTrue($constraint->convalidated);

        $category = Category::query()->create([
            'name' => 'PostgreSQL force delete category',
            'slug' => 'postgresql-force-delete-category',
        ]);
        $author = Author::query()->create(['name' => 'PostgreSQL force delete author']);
        $article = Article::query()->create([
            'title' => 'PostgreSQL article with retained view logs',
            'slug' => 'postgresql-article-with-retained-view-logs',
            'content' => 'PostgreSQL migration regression content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
        ]);
        $viewLogId = DB::table('view_logs')->insertGetId([
            'article_id' => $article->id,
            'source' => 'local',
            'method' => 'GET',
            'path' => '/article/'.$article->slug,
            'status_code' => 200,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        $article->delete();
        $article->forceDelete();

        $this->assertDatabaseHas('view_logs', [
            'id' => $viewLogId,
            'article_id' => null,
        ]);
    }

    public function test_rollback_restores_the_previous_no_action_constraint(): void
    {
        $migration = $this->constraintMigration();

        $migration->down();

        $this->assertSame('no action', strtolower((string) $this->articleForeignKey()['on_delete']));

        $migration->up();
        $this->cleanupMigration()->up();
        $this->validationMigration()->up();

        $this->assertSame('set null', strtolower((string) $this->articleForeignKey()['on_delete']));
    }

    /** @return array{name:string,columns:list<string>,foreign_table:string,on_delete:string} */
    private function articleForeignKey(): array
    {
        return collect(Schema::getForeignKeys('view_logs'))
            ->firstOrFail(static fn (array $key): bool => $key['columns'] === ['article_id']
                && $key['foreign_table'] === 'articles');
    }

    private function constraintMigration(): object
    {
        return require database_path(
            'migrations/2026_08_29_183521_set_null_on_article_delete_for_view_logs_table.php',
        );
    }

    private function cleanupMigration(): object
    {
        return require database_path(
            'migrations/2026_08_29_183522_null_orphaned_view_log_article_references_after_constraint.php',
        );
    }

    private function validationMigration(): object
    {
        return require database_path(
            'migrations/2026_08_29_183523_validate_set_null_on_article_delete_for_view_logs_table.php',
        );
    }
}
