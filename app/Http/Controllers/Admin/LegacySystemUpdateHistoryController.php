<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemUpdateBackup;
use App\Models\SystemUpdateRun;
use Illuminate\View\View;

class LegacySystemUpdateHistoryController extends Controller
{
    public function run(string $runUuid): View
    {
        $this->ensureUpdateCenterEnabled();
        $run = SystemUpdateRun::query()
            ->with(['startedBy', 'backups'])
            ->where('run_uuid', $runUuid)
            ->firstOrFail();

        return view('admin.system-updates.run-show', [
            'pageTitle' => __('admin.system_updates.section.run_detail'),
            'activeMenu' => 'dashboard',
            'run' => $run,
        ]);
    }

    public function backup(string $backupUuid): View
    {
        $this->ensureUpdateCenterEnabled();
        $backup = SystemUpdateBackup::query()
            ->with(['createdBy', 'run'])
            ->where('backup_uuid', $backupUuid)
            ->firstOrFail();

        return view('admin.system-updates.backup-show', [
            'pageTitle' => __('admin.system_updates.section.backup_detail'),
            'activeMenu' => 'dashboard',
            'backup' => $backup,
        ]);
    }

    private function ensureUpdateCenterEnabled(): void
    {
        abort_unless((bool) config('geoflow.update_center_enabled', true), 404);
    }
}
