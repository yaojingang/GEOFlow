<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sensitive_words', function (Blueprint $table): void {
            $table->string('word', 255)->change();
            $table->string('severity', 20)->default('warning')->index();
            $table->string('category', 100)->default('sensitive');
            $table->boolean('is_enabled')->default(true)->index();
            $table->string('suggestion', 255)->nullable();
            $table->json('applies_to')->nullable();
        });
    }

    public function down(): void
    {
        $hasOversizedWord = DB::table('sensitive_words')
            ->pluck('word')
            ->contains(static fn (mixed $word): bool => mb_strlen((string) $word, 'UTF-8') > 100);

        if ($hasOversizedWord) {
            throw new RuntimeException('Cannot roll back sensitive word length while values longer than 100 characters exist.');
        }

        Schema::table('sensitive_words', function (Blueprint $table): void {
            $table->dropIndex(['severity']);
            $table->dropIndex(['is_enabled']);
            $table->dropColumn([
                'severity',
                'category',
                'is_enabled',
                'suggestion',
                'applies_to',
            ]);
            $table->string('word', 100)->change();
        });
    }
};
