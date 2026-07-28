<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 手动解除管理员业务锁定，并撤销旧会话和 Token。
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
    protected $description = 'Unlock a locked admin account and revoke existing credentials';

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

        DB::transaction(function () use ($admin): void {
            $admin->forceFill(['status' => 'active'])->save();
            $admin->revokeAuthenticationCredentials();
        });
        $this->info('账号已解锁并恢复为 active: '.$username);

        return self::SUCCESS;
    }
}
