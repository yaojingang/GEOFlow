<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->string('chunk_embedding_provider', 500)->nullable()->change();
        });

        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            $table->string('embedding_provider', 500)->default('')->change();
        });

        Schema::table('knowledge_chunk_sync_rows', function (Blueprint $table): void {
            $table->string('embedding_provider', 500)->default('')->change();
        });
    }

    public function down(): void
    {
        $this->assertProviderValuesFitLegacyWidth();

        Schema::table('knowledge_chunk_sync_rows', function (Blueprint $table): void {
            $table->string('embedding_provider', 255)->default('')->change();
        });

        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            $table->string('embedding_provider', 255)->default('')->change();
        });

        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->string('chunk_embedding_provider', 255)->nullable()->change();
        });
    }

    private function assertProviderValuesFitLegacyWidth(): void
    {
        $oversized = [];
        foreach ([
            'knowledge_bases' => 'chunk_embedding_provider',
            'knowledge_chunks' => 'embedding_provider',
            'knowledge_chunk_sync_rows' => 'embedding_provider',
        ] as $table => $column) {
            if (Schema::hasTable($table)
                && Schema::hasColumn($table, $column)
                && DB::table($table)->whereRaw('LENGTH('.$column.') > ?', [255])->exists()) {
                $oversized[] = $table.'.'.$column;
            }
        }

        if ($oversized !== []) {
            throw new RuntimeException(
                'knowledge_embedding_provider_downsize_blocked: values over 255 characters remain in '
                .implode(', ', $oversized)
                .'; shorten or clear them before rollback.',
            );
        }
    }
};
