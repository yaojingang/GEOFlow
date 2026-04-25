<?php

namespace App\Services\Admin;

use App\Models\Admin;

/**
 * 后台「欢迎使用 GEOFlow」弹窗：负责版本态判断、自动打开一次、以及关闭落库所需的数据。
 */
class AdminWelcomeModalService
{
    public function __construct(
        private readonly AdminUpdateMetadataService $updateMetadataService
    ) {}

    /**
     * 为 Blade 输出构造 JSON 载荷（多语言文案 + 运行态：是否自动打开、关闭地址、CSRF、外链）。
     *
     * @return array{copy: array<string, mixed>, state: array<string, mixed>}
     */
    public function buildModalPayload(Admin $admin): array
    {
        $welcomeState = $this->resolveWelcomeState();
        $shouldAutoOpen = $this->prepareAutoOpen($admin, $welcomeState);
        $admin->refresh();

        $copy = ($welcomeState['mode'] ?? 'intro') === 'update'
            ? $this->buildUpdateCopy($welcomeState)
            : $this->buildIntroCopy();

        return [
            'copy' => $copy,
            'state' => [
                'mode' => $welcomeState['mode'] ?? 'intro',
                'shouldAutoOpen' => $shouldAutoOpen,
                'dismissUrl' => route('admin.welcome.dismiss'),
                'csrfToken' => csrf_token(),
                'links' => [
                    'x' => 'https://x.com/yaojingang',
                    'github' => 'https://github.com/yaojingang/GEOFlow',
                    'changelog' => [
                        'zh-CN' => 'https://github.com/yaojingang/GEOFlow/blob/main/docs/CHANGELOG.md',
                        'en' => 'https://github.com/yaojingang/GEOFlow/blob/main/docs/CHANGELOG_en.md',
                    ],
                ],
            ],
        ];
    }

    /**
     * 关闭弹窗时写入的版本键，须与 {@see prepareAutoOpen} 使用的键一致。
     */
    public function currentWelcomeVersionKey(): string
    {
        return $this->welcomeVersionKey($this->resolveWelcomeState());
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveWelcomeState(): array
    {
        $introVersion = (string) config('geoflow.welcome_intro_version', '1.2.0');
        $updateState = $this->updateMetadataService->fetchState($introVersion);

        if (! empty($updateState['is_update_available']) && empty($updateState['is_ignored'])) {
            return [
                'mode' => 'update',
                'version' => 'update:'.(string) ($updateState['latest_version'] ?? ''),
                'update' => $updateState,
            ];
        }

        return [
            'mode' => 'intro',
            'version' => 'intro:'.$introVersion,
            'update' => $updateState,
        ];
    }

    /**
     * @param  array<string, mixed>  $welcomeState
     */
    private function welcomeVersionKey(array $welcomeState): string
    {
        return (string) ($welcomeState['version'] ?? ('intro:'.config('geoflow.welcome_intro_version', '1.2.0')));
    }

    /**
     * 当当前欢迎/更新版本键与库中已读不一致时：本请求应自动弹出，并写入 `welcome_seen_version` 以免重复打扰。
     *
     * @param  array<string, mixed>  $welcomeState
     */
    private function prepareAutoOpen(Admin $admin, array $welcomeState): bool
    {
        $versionKey = $this->welcomeVersionKey($welcomeState);
        $seen = (string) ($admin->welcome_seen_version ?? '');
        $shouldAutoOpen = $seen !== $versionKey;
        if ($shouldAutoOpen) {
            Admin::query()->whereKey($admin->id)->update([
                'welcome_seen_version' => $versionKey,
                'updated_at' => now(),
            ]);
        }

        return $shouldAutoOpen;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildIntroCopy(): array
    {
        /** @var array<string, mixed> $copy */
        $copy = require app_path('Support/AdminWelcome/intro_copy.php');

        return $copy;
    }

    /**
     * @param  array<string, mixed>  $welcomeState
     * @return array<string, mixed>
     */
    private function buildUpdateCopy(array $welcomeState): array
    {
        /** @var callable(array): array<string, mixed> $builder */
        $builder = require app_path('Support/AdminWelcome/update_welcome_copy.php');

        return $builder($welcomeState);
    }
}
