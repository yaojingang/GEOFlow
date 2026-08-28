<?php

namespace Tests\Unit;

use Tests\TestCase;

class TitleFingerprintMigrationContractTest extends TestCase
{
    public function test_title_fingerprint_migrations_are_online_safe_and_rerunnable(): void
    {
        $indexMigration = file_get_contents(database_path('migrations/2026_08_27_213108_add_title_fingerprint_to_titles_table.php'));
        $backfillMigration = file_get_contents(database_path('migrations/2026_08_28_000100_backfill_title_fingerprints.php'));

        $this->assertIsString($indexMigration);
        $this->assertIsString($backfillMigration);
        $this->assertStringContainsString('public $withinTransaction = false;', $indexMigration);
        $this->assertStringContainsString('CREATE UNIQUE INDEX CONCURRENTLY', $indexMigration);
        $this->assertStringContainsString('CREATE INDEX CONCURRENTLY', $indexMigration);
        $this->assertStringContainsString('public $withinTransaction = false;', $backfillMigration);
        $this->assertStringContainsString('chunkById', $backfillMigration);
        $this->assertStringContainsString('DB::transaction', $backfillMigration);
        $this->assertStringContainsString('QueryException', $backfillMigration);
        $this->assertStringNotContainsString('foreach ($candidates as $candidate)', $backfillMigration);
        $this->assertStringContainsString('DB::update(', $backfillMigration);
        $this->assertStringContainsString('throw $lastUniqueConflict;', $backfillMigration);
    }
}
