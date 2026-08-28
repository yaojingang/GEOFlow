<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Compatibility marker only. Historical trace text is audit data and
        // must remain unchanged; the presentation layer hides log-only copy.
    }

    public function down(): void
    {
        // No data was changed.
    }
};
