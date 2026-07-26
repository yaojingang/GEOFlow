<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiVisibilityRunSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_schema_uses_the_current_event_date_index_without_the_superseded_index(): void
    {
        $indexNames = collect(Schema::getIndexes('ai_visibility_runs'))
            ->pluck('name');

        $this->assertTrue($indexNames->contains('ai_visibility_runs_event_date_status_idx'));
        $this->assertFalse($indexNames->contains('ai_visibility_runs_status_completed_keyword_idx'));
    }
}
