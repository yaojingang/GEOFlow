<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('ai_workspace_runs', function (Blueprint $table): void {
            foreach ($this->columns() as $column) {
                $table->timestamp($column, 6)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('ai_workspace_runs', function (Blueprint $table): void {
            foreach ($this->columns() as $column) {
                $table->timestamp($column)->nullable()->change();
            }
        });
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'resolution_started_at',
            'resolution_finished_at',
            'queued_at',
            'first_token_at',
        ];
    }
};
