<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->isComplete()) {
            return;
        }

        if (! Schema::hasTable('task_trash_state')) {
            Schema::create('task_trash_state', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->unsignedBigInteger('last_sequence')->default(0);
            });
        }

        DB::table('task_trash_state')->insertOrIgnore([
            'id' => 1,
            'last_sequence' => 0,
        ]);

        if (! Schema::hasColumn('task_trash_entries', 'sequence')) {
            Schema::table('task_trash_entries', function (Blueprint $table): void {
                $table->unsignedBigInteger('sequence')->nullable()->after('id');
            });
        } elseif (! $this->sequenceIsNullable()) {
            Schema::table('task_trash_entries', function (Blueprint $table): void {
                $table->unsignedBigInteger('sequence')->nullable()->change();
            });
        }

        DB::table('task_trash_entries')->update(['sequence' => null]);
        $lastSequence = 0;
        $lastDeletedAt = null;
        $lastId = 0;

        do {
            $entries = DB::table('task_trash_entries')
                ->when($lastDeletedAt !== null, function ($query) use ($lastDeletedAt, $lastId): void {
                    $query->where(function ($query) use ($lastDeletedAt, $lastId): void {
                        $query->where('deleted_at', '>', $lastDeletedAt)
                            ->orWhere(function ($query) use ($lastDeletedAt, $lastId): void {
                                $query->where('deleted_at', '=', $lastDeletedAt)
                                    ->where('id', '>', $lastId);
                            });
                    });
                })
                ->orderBy('deleted_at')
                ->orderBy('id')
                ->limit(500)
                ->get(['id', 'deleted_at']);

            foreach ($entries as $entry) {
                $lastSequence++;
                DB::table('task_trash_entries')->where('id', $entry->id)->update([
                    'sequence' => $lastSequence,
                ]);
                $lastDeletedAt = $entry->deleted_at;
                $lastId = (int) $entry->id;
            }
        } while ($entries->isNotEmpty());

        DB::table('task_trash_state')->where('id', 1)->update([
            'last_sequence' => $lastSequence,
        ]);

        if (DB::table('task_trash_entries')->whereNull('sequence')->exists()) {
            throw new RuntimeException('Task trash sequence backfill did not cover every entry.');
        }
        Schema::table('task_trash_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('sequence')->nullable(false)->change();
        });

        if (! Schema::hasIndex('task_trash_entries', ['sequence'])) {
            Schema::table('task_trash_entries', function (Blueprint $table): void {
                $table->unique('sequence');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('task_trash_entries') && DB::table('task_trash_entries')->exists()) {
            throw new RuntimeException(
                'Cannot roll back task trash migrations while deleted tasks exist. Restore or permanently remove them first.',
            );
        }

        if (Schema::hasIndex('task_trash_entries', ['sequence'])) {
            Schema::table('task_trash_entries', function (Blueprint $table): void {
                $table->dropUnique(['sequence']);
            });
        }
        if (Schema::hasColumn('task_trash_entries', 'sequence')) {
            Schema::table('task_trash_entries', function (Blueprint $table): void {
                $table->dropColumn('sequence');
            });
        }

        Schema::dropIfExists('task_trash_state');
    }

    private function isComplete(): bool
    {
        if (! Schema::hasTable('task_trash_entries')
            || ! Schema::hasTable('task_trash_state')
            || ! Schema::hasColumn('task_trash_entries', 'sequence')
            || ! Schema::hasIndex('task_trash_entries', ['sequence'])
            || $this->sequenceIsNullable()
            || DB::table('task_trash_entries')->whereNull('sequence')->exists()) {
            return false;
        }

        $count = DB::table('task_trash_entries')->count();
        $distinctCount = DB::table('task_trash_entries')->distinct()->count('sequence');
        $lastSequence = DB::table('task_trash_state')->where('id', 1)->value('last_sequence');
        $maxSequence = (int) (DB::table('task_trash_entries')->max('sequence') ?? 0);

        return $lastSequence !== null
            && $count === $distinctCount
            && (int) $lastSequence >= $maxSequence;
    }

    private function sequenceIsNullable(): bool
    {
        $column = collect(Schema::getColumns('task_trash_entries'))
            ->first(static fn (array $column): bool => (string) ($column['name'] ?? '') === 'sequence');

        return (bool) ($column['nullable'] ?? true);
    }
};
