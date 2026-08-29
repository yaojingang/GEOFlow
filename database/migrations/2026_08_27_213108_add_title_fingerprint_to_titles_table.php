<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('titles', 'title_fingerprint')) {
            Schema::table('titles', function (Blueprint $table): void {
                $table->string('title_fingerprint', 64)->nullable();
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            $this->createPostgresIndex(
                'titles_library_fingerprint_unique',
                'CREATE UNIQUE INDEX CONCURRENTLY "titles_library_fingerprint_unique" ON "titles" ("library_id", "title_fingerprint")',
            );
            $this->createPostgresIndex(
                'titles_library_title_idx',
                'CREATE INDEX CONCURRENTLY "titles_library_title_idx" ON "titles" ("library_id", "title")',
            );
            $this->createPostgresIndex(
                'titles_library_created_id_idx',
                'CREATE INDEX CONCURRENTLY "titles_library_created_id_idx" ON "titles" ("library_id", "created_at", "id")',
            );

            return;
        }

        Schema::table('titles', function (Blueprint $table): void {
            if (! Schema::hasIndex('titles', 'titles_library_fingerprint_unique')) {
                $table->unique(['library_id', 'title_fingerprint'], 'titles_library_fingerprint_unique');
            }
            if (! Schema::hasIndex('titles', 'titles_library_title_idx')) {
                $table->index(['library_id', 'title'], 'titles_library_title_idx');
            }
            if (! Schema::hasIndex('titles', 'titles_library_created_id_idx')) {
                $table->index(['library_id', 'created_at', 'id'], 'titles_library_created_id_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS "titles_library_created_id_idx"');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS "titles_library_title_idx"');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS "titles_library_fingerprint_unique"');
        } else {
            Schema::table('titles', function (Blueprint $table): void {
                if (Schema::hasIndex('titles', 'titles_library_created_id_idx')) {
                    $table->dropIndex('titles_library_created_id_idx');
                }
                if (Schema::hasIndex('titles', 'titles_library_title_idx')) {
                    $table->dropIndex('titles_library_title_idx');
                }
                if (Schema::hasIndex('titles', 'titles_library_fingerprint_unique')) {
                    $table->dropUnique('titles_library_fingerprint_unique');
                }
            });
        }

        if (Schema::hasColumn('titles', 'title_fingerprint')) {
            Schema::table('titles', function (Blueprint $table): void {
                $table->dropColumn('title_fingerprint');
            });
        }
    }

    private function createPostgresIndex(string $name, string $statement): void
    {
        $index = DB::selectOne(
            'SELECT i.indisvalid FROM pg_index i WHERE i.indexrelid = to_regclass(?)',
            [$name],
        );
        $isValid = $index !== null
            && in_array($index->indisvalid, [true, 1, '1', 't', 'true'], true);
        if ($index !== null && ! $isValid) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS "'.$name.'"');
            $index = null;
        }
        if ($index === null) {
            DB::statement($statement);
        }
    }
};
