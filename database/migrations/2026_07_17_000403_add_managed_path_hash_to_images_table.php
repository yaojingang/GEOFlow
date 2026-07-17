<?php

use App\Services\GeoFlow\ManagedImagePathHasherV1;
use App\Support\GeoFlow\SecurityUpgradeMigrationGate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SecurityUpgradeMigrationGate::assertReady();

        if (! Schema::hasColumn('images', 'managed_path_hash')) {
            Schema::table('images', function (Blueprint $table) {
                $table->char('managed_path_hash', 64)->nullable()->index();
            });
        }

        $hasher = new ManagedImagePathHasherV1;
        DB::table('images')
            ->whereNull('managed_path_hash')
            ->select(['id', 'file_path'])
            ->orderBy('id')
            ->chunkById(200, function ($images) use ($hasher): void {
                foreach ($images as $image) {
                    $filePath = (string) $image->file_path;
                    try {
                        $pathHash = $hasher->hashManagedPathV1($filePath);
                    } catch (InvalidArgumentException) {
                        $pathHash = $hasher->terminalHashV1($filePath);
                    }

                    DB::table('images')
                        ->where('id', $image->id)
                        ->whereNull('managed_path_hash')
                        ->update(['managed_path_hash' => $pathHash]);
                }
            }, 'id');

        if (DB::table('images')->whereNull('managed_path_hash')->exists()) {
            throw new RuntimeException('Managed image path hash backfill did not complete.');
        }

        Schema::table('images', function (Blueprint $table) {
            // The preflight gate guarantees that every writer now supplies this identity.
            $table->char('managed_path_hash', 64)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->dropIndex(['managed_path_hash']);
            $table->dropColumn('managed_path_hash');
        });
    }
};
