<?php

use App\Support\GeoFlow\SecurityUpgradeMigrationGate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SecurityUpgradeMigrationGate::assertReady();
    }

    public function down(): void
    {
        // The preflight records no schema or data changes.
    }
};
