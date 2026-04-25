<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Support\GeoFlow\AdminLoginLockService;
use Illuminate\Console\Command;

/**
 * 手动解锁被连续登录失败锁定的管理员账号。
 */
class GeoFlowUnlockAdminCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'geoflow:admin-unlock {username : 管理员登录用户名}';

    /**
     * @var string
     */
    protected $description = 'Unlock a locked admin account and clear failed login attempts';

    public function __construct(
        private readonly AdminLoginLockService $adminLoginLockService
    ) {
        parent::__construct();
    }

    /**
     * 执行账号解锁。
     */
    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));
        if ($username === '') {
            $this->error('用户名不能为空');

            return self::INVALID;
        }

        /** @var Admin|null $admin */
        $admin = Admin::query()->where('username', $username)->first();
        if (! $admin) {
            $this->error('管理员不存在: '.$username);

            return self::FAILURE;
        }

        $admin->forceFill(['status' => 'active'])->save();
        $this->adminLoginLockService->clearFailedAttempts((string) $admin->username);

        $this->info('账号已解锁并恢复为 active: '.$username);

        return self::SUCCESS;
    }
}
