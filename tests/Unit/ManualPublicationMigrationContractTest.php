<?php

namespace Tests\Unit;

use Tests\TestCase;

class ManualPublicationMigrationContractTest extends TestCase
{
    public function test_published_v230_manual_publication_migrations_remain_immutable(): void
    {
        $migrations = [
            'database/migrations/2026_08_01_000002_create_manual_publications_table.php' => '080fbd54c2d9e930aa6b21e724e7285f0297c750b52db2983e2be6ce88c0982a',
            'database/migrations/2026_08_01_000003_create_manual_publication_transitions_table.php' => 'c6609b251f6525804d5929238008e4a45e9b640173803edd852b6d862c43b757',
        ];

        foreach ($migrations as $path => $expectedHash) {
            $this->assertSame($expectedHash, hash_file('sha256', base_path($path)), $path);
        }
    }
}
