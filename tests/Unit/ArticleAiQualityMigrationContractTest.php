<?php

namespace Tests\Unit;

use Tests\TestCase;

class ArticleAiQualityMigrationContractTest extends TestCase
{
    public function test_deployed_quality_checks_expand_the_active_dedupe_key_for_sha256_values(): void
    {
        $migration = file_get_contents(database_path(
            'migrations/2026_08_29_122000_expand_article_ai_quality_active_dedupe_key.php'
        ));

        $this->assertIsString($migration);
        $this->assertStringContainsString("char('active_dedupe_key', 64)->nullable()->change()", $migration);
        $this->assertStringContainsString("whereRaw('CHAR_LENGTH(active_dedupe_key) > 40')", $migration);
    }

    public function test_deployed_quality_checks_expand_the_composite_algorithm_version(): void
    {
        $migration = file_get_contents(database_path(
            'migrations/2026_08_29_122100_expand_article_ai_quality_algorithm_version.php'
        ));

        $this->assertIsString($migration);
        $this->assertStringContainsString("string('algorithm_version', 100)->change()", $migration);
        $this->assertStringContainsString("whereRaw('CHAR_LENGTH(algorithm_version) > 40')", $migration);
    }
}
