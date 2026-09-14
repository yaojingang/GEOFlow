<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string,list<string>> */
    private array $watched = [
        'articles' => ['slug', 'category_id', 'created_at', 'status', 'review_status', 'deleted_at', 'task_id'],
        'categories' => ['slug'],
        'article_slug_histories' => ['slug', 'article_id'],
        'hosted_site_article_assignments' => ['article_id', 'hosted_site_profile_id', 'status'],
        'tasks' => ['publish_scope'],
        'hosted_site_profiles' => ['hostname', 'serving_status', 'distribution_channel_id'],
    ];

    public function up(): void
    {
        DB::table('url_change_scope_states')->insertOrIgnore(['scope_key' => 'primary']);
        foreach ($this->watched as $table => $columns) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                $name = 'url_revision_'.$table.'_'.strtolower($event);
                if (DB::getDriverName() === 'sqlite') {
                    $when = $event === 'UPDATE'
                        ? ' WHEN '.implode(' OR ', array_map(fn ($column) => "OLD.{$column} IS NOT NEW.{$column}", $columns)) : '';
                    $fields = array_unique(array_merge(['id'], $columns));
                    $select = fn (string $row): string => 'SELECT '.implode(', ', array_map(fn ($field) => "{$row}.{$field} AS {$field}", $fields));
                    $changed = match ($event) {
                        'INSERT' => $select('NEW'), 'DELETE' => $select('OLD'),
                        default => $select('OLD').' UNION ALL '.$select('NEW'),
                    };
                    $scopes = $this->scopes($table, "({$changed})");
                    DB::unprepared("CREATE TRIGGER {$name} AFTER {$event} ON {$table}{$when} BEGIN INSERT INTO url_change_scope_states (scope_key, data_revision) SELECT scope_key, 1 FROM ({$scopes}) WHERE scope_key IS NOT NULL ORDER BY scope_key ON CONFLICT(scope_key) DO UPDATE SET data_revision = data_revision + 1; END");
                } elseif (DB::getDriverName() === 'pgsql') {
                    $changed = match ($event) {
                        'INSERT' => 'SELECT * FROM new_url_rows',
                        'DELETE' => 'SELECT * FROM old_url_rows',
                        default => 'SELECT * FROM old_url_rows UNION ALL SELECT * FROM new_url_rows',
                    };
                    $condition = implode(' OR ', array_map(fn ($column) => "o.{$column} IS DISTINCT FROM n.{$column}", $columns));
                    $guard = $event === 'UPDATE' ? "IF NOT EXISTS (SELECT 1 FROM old_url_rows o JOIN new_url_rows n USING (id) WHERE {$condition}) THEN RETURN NULL; END IF;" : '';
                    $scopes = $this->scopes($table, 'changed');
                    // Statement-level transitions keep bulk imports from updating one revision row per article.
                    DB::unprepared("CREATE FUNCTION {$name}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN {$guard} WITH changed AS ({$changed}), scopes AS ({$scopes}) INSERT INTO url_change_scope_states (scope_key, data_revision) SELECT scope_key, 1 FROM scopes WHERE scope_key IS NOT NULL ORDER BY scope_key ON CONFLICT(scope_key) DO UPDATE SET data_revision = url_change_scope_states.data_revision + 1; RETURN NULL; END \$\$");
                    $reference = match ($event) {
                        'INSERT' => 'NEW TABLE AS new_url_rows',
                        'DELETE' => 'OLD TABLE AS old_url_rows',
                        default => 'OLD TABLE AS old_url_rows NEW TABLE AS new_url_rows',
                    };
                    DB::unprepared("CREATE TRIGGER {$name} AFTER {$event} ON {$table} REFERENCING {$reference} FOR EACH STATEMENT EXECUTE FUNCTION {$name}()");
                }
            }
        }
    }

    private function scopes(string $table, string $changed): string
    {
        if ($table === 'categories') {
            return "SELECT 'primary' AS scope_key WHERE EXISTS (SELECT 1 FROM {$changed} c)"
                ." UNION SELECT 'category:' || CAST(c.id AS TEXT) FROM {$changed} c"
                ." UNION SELECT 'hosted:' || CAST(p.distribution_channel_id AS TEXT) FROM hosted_site_profiles p WHERE EXISTS (SELECT 1 FROM hosted_site_article_assignments x JOIN articles a ON a.id = x.article_id WHERE x.hosted_site_profile_id = p.id AND a.category_id IN (SELECT id FROM {$changed} c))";
        }
        $articleIds = match ($table) {
            'articles' => "SELECT id FROM {$changed} c",
            'categories' => "SELECT a.id FROM articles a WHERE a.category_id IN (SELECT id FROM {$changed} c)",
            'article_slug_histories', 'hosted_site_article_assignments' => "SELECT article_id FROM {$changed} c",
            'tasks' => "SELECT a.id FROM articles a WHERE a.task_id IN (SELECT id FROM {$changed} c)",
            default => null,
        };
        $parts = ["SELECT 'primary' AS scope_key WHERE EXISTS (SELECT 1 FROM {$changed} c)"];
        if ($articleIds !== null) {
            $parts[] = "SELECT 'category:' || CAST(a.category_id AS TEXT) AS scope_key FROM articles a WHERE a.id IN ({$articleIds})";
            $parts[] = "SELECT 'hosted:' || CAST(p.distribution_channel_id AS TEXT) AS scope_key FROM hosted_site_profiles p JOIN hosted_site_article_assignments x ON x.hosted_site_profile_id = p.id WHERE x.article_id IN ({$articleIds})";
        }
        if ($table === 'articles') {
            $parts[] = "SELECT 'category:' || CAST(c.category_id AS TEXT) AS scope_key FROM {$changed} c";
        } elseif ($table === 'categories') {
            $parts[] = "SELECT 'category:' || CAST(c.id AS TEXT) AS scope_key FROM {$changed} c";
        } elseif ($table === 'hosted_site_article_assignments') {
            $parts[] = "SELECT 'hosted:' || CAST(p.distribution_channel_id AS TEXT) AS scope_key FROM hosted_site_profiles p WHERE p.id IN (SELECT hosted_site_profile_id FROM {$changed} c)";
        } elseif ($table === 'hosted_site_profiles') {
            $parts[] = "SELECT 'hosted:' || CAST(c.distribution_channel_id AS TEXT) AS scope_key FROM {$changed} c";
        }

        return implode(' UNION ', $parts);
    }

    public function down(): void
    {
        foreach ($this->watched as $table => $columns) {
            foreach (['insert', 'update', 'delete'] as $event) {
                $name = 'url_revision_'.$table.'_'.$event;
                if (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
                } elseif (DB::getDriverName() === 'pgsql') {
                    DB::unprepared("DROP TRIGGER IF EXISTS {$name} ON {$table}");
                    DB::unprepared("DROP FUNCTION IF EXISTS {$name}()");
                }
            }
        }
    }
};
