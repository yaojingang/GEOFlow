<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GeoSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_geo_domain_tables_exist(): void
    {
        $tables = [
            'organizations',
            'brand_profiles',
            'geo_keywords',
            'geo_competitors',
            'geo_ai_platforms',
            'geo_tasks',
            'geo_task_questions',
            'geo_answers',
            'geo_scores',
            'geo_reports',
            'geo_writing_tasks',
            'geo_article_drafts',
            'geo_publish_targets',
            'geo_publish_records',
            'geo_citation_occurrences',
            'point_logs',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} table is missing");
        }

        $this->assertTrue(Schema::hasColumn('geo_publish_records', 'submitted_at'), 'geo_publish_records.submitted_at column is missing');
    }
}
