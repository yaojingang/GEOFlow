<?php

namespace App\Services\Api;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Models\ThemeRevision;
use App\Models\ThemeWorkspace;
use App\Services\Admin\SiteThemePackageGuard;
use App\Services\Site\ArticlePermalinkService;
use App\Support\Site\HomepageModuleBuilder;
use App\Support\Site\InstalledSiteThemeRepository;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ThemeWorkspaceService
{
    public function __construct(
        private ThemeWorkspaceAuthorization $authorization,
        private ThemeRevisionStorage $revisions,
        private SiteThemeCatalog $catalog,
        private InstalledSiteThemeRepository $installed,
        private ManagementInstance $instance,
        private ManagementSiteQuery $sites,
        private SiteThemePackageGuard $guard,
    ) {}

    public function themes(ApiAuthContext $auth): array
    {
        $this->authorization->actor($auth, 'themes:read');

        return ['items' => array_map(fn (array $theme): array => array_intersect_key($theme, array_flip(['id', 'name', 'version', 'description', 'source'])), $this->catalog->all())];
    }

    public function contract(ApiAuthContext $auth, ?string $id = null): array
    {
        $this->authorization->actor($auth, 'themes:read');
        $workspace = $id === null ? null : $this->authorization->workspace($auth, $id, 'themes:read');
        $revision = $workspace === null ? null : ThemeRevision::query()->findOrFail($workspace->revision_id);

        return [
            'version' => 1, 'site_kinds' => ['primary'], 'renderer' => 'trusted-native-blade',
            'workspace_id' => $workspace?->id, 'revision_id' => $revision?->id,
            'pages' => SiteThemePackageGuard::PAGES, 'fallback' => 'site.*',
            'paths' => ['resources/views/theme/{theme_id}/', 'public/themes/{theme_id}/', 'resources/views/site/'],
            'page_variables' => [
                'common' => ['siteTitle' => 'string', 'siteDescription' => 'string', 'siteKeywords' => 'string', 'pageTitle' => 'string', 'pageDescription' => 'string', 'pageKeywords' => 'string', 'pageOgType' => 'string', 'canonicalUrl' => 'string', 'activeNav' => 'string'],
                'home' => ['articles' => 'LengthAwarePaginator<Article>', 'category' => 'Category|null', 'categoryMissing' => 'bool', 'categoryId' => 'int', 'search' => 'string', 'featuredArticles' => 'Collection<Article>', 'hotArticles' => 'Collection<Article>', 'cardSummaries' => 'array', 'siteSubtitle' => 'string', 'homepageCarouselSlides' => 'list<array>', 'homepageModules' => 'array', 'homepageStyle' => 'array', 'leadForms' => 'Collection<string,LeadForm>', 'showHomepageModules' => 'bool', 'friendLinks' => 'list<array>', 'viewTitle' => 'string', 'perPage' => 'int'],
                'category' => ['category' => 'Category', 'articles' => 'LengthAwarePaginator<Article>', 'hotArticles' => 'Collection<Article>', 'cardSummaries' => 'array'],
                'article' => ['article' => 'Article', 'contentHtml' => 'string', 'excerptPlain' => 'string', 'tags' => 'array<string>', 'relatedArticles' => 'Collection<Article>', 'stickyAd' => 'array|null'],
                'about' => ['aboutTitle' => 'string', 'aboutContent' => 'string', 'contactEmail' => 'string', 'repositoryUrl' => 'string', 'isHostedAbout' => 'bool'],
                'archive-index' => ['archives' => 'list<array{year:string,month:string,count:int}>'],
                'archive-month' => ['articles' => 'LengthAwarePaginator<Article>', 'year' => 'string', 'month' => 'string', 'periodLabel' => 'string', 'cardSummaries' => 'array'],
            ],
            'urls' => ['article_pattern' => app(ArticlePermalinkService::class)->policy()->currentPattern, 'pagination_query' => 'page', 'search_query' => 'search', 'helper' => 'App\\Services\\Site\\SiteUrlGenerator', 'preview_navigation' => 'Use the signed links returned by preview; preserve their complete query string.'],
            'empty_state' => 'Render an empty collection; preview never inserts demonstration articles.',
            'configuration' => ['editable' => false, 'reason' => 'remote_configuration_adapter_pending', 'homepage_module_types' => HomepageModuleBuilder::TYPES, 'style_keys' => ['accent_color', 'background_color', 'surface_color', 'text_color', 'muted_color', 'container_width', 'section_spacing', 'radius']],
            'limits' => ['file_bytes' => $this->guard->limit('max_file_bytes'), 'video_file_bytes' => $this->guard->limit('max_video_file_bytes'), 'total_bytes' => $this->guard->limit('max_total_bytes'), 'files' => $this->guard->limit('max_files'), 'changes_bytes' => 1048576, 'read_chunk_bytes' => 262144],
            'code_authorization' => ['required' => true, 'lifetime_seconds' => 1800, 'password_reauthentication' => true],
            'dependencies' => $revision?->dependencies ?? $this->revisions->dependencies(),
            'current_dependencies' => $this->revisions->dependencies(),
            'publication' => ThemeReleaseService::availability(),
        ];
    }

    public function create(ApiAuthContext $auth, string $site, string $themeId): array
    {
        $this->authorization->actor($auth, 'themes:write');
        $settings = $this->sites->show($site)['settings'];
        $theme = collect($this->catalog->all())->firstWhere('id', $themeId);
        if (! $theme) {
            throw new ApiException('theme_not_found', '主题不存在', 404);
        }

        return $this->revisions->storage->lock('theme-workspaces-admin-'.$auth->auditAdminId, function () use ($auth, $site, $themeId, $settings, $theme): array {
            if (ThemeWorkspace::query()->where('admin_id', $auth->auditAdminId)->where('state', 'draft')->count() >= 10) {
                throw new ApiException('workspace_quota', '每个管理员最多保留 10 个活动草稿', 409);
            }
            $workspace = ThemeWorkspace::query()->create([
                'id' => (string) Str::uuid(), 'instance_id' => $this->instance->id(), 'admin_id' => $auth->auditAdminId,
                'site_key' => $site, 'theme_id' => $themeId, 'source' => $theme['source'],
            ]);
            try {
                $contents = $this->sourceContents($themeId, $theme['source']);
                $revision = $this->revisions->create($workspace->id, $themeId, $contents, $settings);
                $workspace->update(['revision_id' => $revision->id]);
            } catch (\Throwable $exception) {
                $workspace->update(['state' => 'failed']);
                throw $exception;
            }

            return $this->describe($workspace->refresh());
        });
    }

    public function show(ApiAuthContext $auth, string $id): array
    {
        return $this->describe($this->authorization->workspace($auth, $id, 'themes:read'));
    }

    public function discard(ApiAuthContext $auth, string $id, int $expectedVersion): array
    {
        $this->authorization->actor($auth, 'themes:write');
        $owned = ThemeWorkspace::query()->whereKey($id)->where('admin_id', $auth->auditAdminId)->where('instance_id', $this->instance->id())->first();
        if (! $owned) {
            throw new ApiException('workspace_not_found', '当前账号没有此草稿', 404);
        }

        $this->revisions->storage->lock('workspace-'.$id, function () use ($auth, $owned, $expectedVersion): void {
            $this->authorization->actor($auth, 'themes:write');
            $owned->refresh();
            if ($owned->state !== 'discarded') {
                if ($owned->lock_version !== $expectedVersion) {
                    throw new ApiException('workspace_conflict', '草稿已更新，请先重新读取', 409);
                }
                $owned->update(['state' => 'discarded', 'lock_version' => $expectedVersion + 1, 'code_token_id' => null, 'code_authorized_until' => null, 'plan' => null]);
            }
        });
        $this->authorization->actor($auth, 'themes:write');
        try {
            $result = app(ThemeWorkspaceRetention::class)->collect($owned->id);
            $cleanup = ['state' => isset($result['grace_until']) ? 'deferred' : 'completed'] + $result;
        } catch (\RuntimeException) {
            $cleanup = ['state' => 'pending', 'reason' => 'storage_cleanup_failed_retry_discard'];
        }

        return ['workspace_id' => $owned->id, 'state' => 'discarded', 'cleanup' => $cleanup];
    }

    public function file(ApiAuthContext $auth, string $id, string $path, int $offset = 0, int $length = 262144): array
    {
        $workspace = $this->authorization->workspace($auth, $id, 'themes:read');
        $revision = ThemeRevision::query()->findOrFail($workspace->revision_id);
        if ($offset < 0 || $length < 1 || $length > 262144) {
            throw new ApiException('invalid_range', '读取分块应在 1 至 262144 字节之间', 422);
        }
        $absolute = $this->revisions->path($revision, $path);
        $bytes = $this->revisions->read($absolute, $this->guard->fileLimit($path));
        if (! hash_equals($revision->files[$path]['sha256'], hash('sha256', $bytes))) {
            throw new ApiException('revision_integrity_failed', '文件与版本清单不符', 409);
        }

        return ['revision_id' => $revision->id, 'path' => $path, 'sha256' => hash('sha256', $bytes), 'total_bytes' => strlen($bytes), 'offset' => $offset, 'encoding' => 'base64', 'content' => base64_encode(substr($bytes, $offset, $length))];
    }

    /** @param list<array<string, mixed>> $changes */
    public function change(ApiAuthContext $auth, string $id, int $expectedVersion, array $changes): array
    {
        $workspace = $this->authorization->workspace($auth, $id, 'themes:write');
        $this->authorization->assertCode($auth, $workspace);

        return $this->revisions->storage->lock('workspace-'.$id, function () use ($auth, $id, $expectedVersion, $changes): array {
            $workspace = $this->authorization->workspace($auth, $id, 'themes:write');
            $this->authorization->assertCode($auth, $workspace);
            if ($workspace->lock_version !== $expectedVersion) {
                throw new ApiException('workspace_conflict', '草稿已被更新，请重新读取差异', 409);
            }
            if ($changes === [] || count($changes) > 100 || strlen(json_encode($changes, JSON_THROW_ON_ERROR)) > 1048576) {
                throw new ApiException('invalid_changes', '修改批次为空或超过 1 MiB 限额', 422);
            }
            $base = ThemeRevision::query()->findOrFail($workspace->revision_id);
            $contents = $this->revisions->contents($base);
            $seen = [];
            foreach ($changes as $change) {
                $path = $change['path'] ?? null;
                if (! is_string($path) || isset($seen[$path]) || ! array_key_exists('expected_sha256', $change)) {
                    throw new ApiException('invalid_change', '每个文件需提供唯一逻辑路径和基线摘要', 422);
                }
                $seen[$path] = true;
                $this->revisions->validatePath($path, $workspace->theme_id);
                $hash = isset($contents[$path]) ? hash('sha256', $contents[$path]) : null;
                if ($hash !== $change['expected_sha256']) {
                    throw new ApiException('file_conflict', '文件内容已变化，请重新读取', 409, ['path' => $path]);
                }
                if (($change['action'] ?? '') === 'delete') {
                    unset($contents[$path]);
                } elseif (($change['action'] ?? '') === 'put' && is_string($change['content'] ?? null)) {
                    $contents[$path] = $change['content'];
                } else {
                    throw new ApiException('invalid_change', '文件修改仅接受 put 或 delete', 422);
                }
            }
            $revision = $this->revisions->create($workspace->id, $workspace->theme_id, $contents, $base->settings, $base->id);
            DB::transaction(function () use ($auth, $workspace, $expectedVersion, $revision): void {
                $this->authorization->assertCode($auth, $workspace);
                $updated = ThemeWorkspace::query()->whereKey($workspace->id)->where('lock_version', $expectedVersion)
                    ->update(['revision_id' => $revision->id, 'lock_version' => $expectedVersion + 1, 'plan' => null, 'updated_at' => now()]);
                if ($updated !== 1) {
                    throw new ApiException('workspace_conflict', '草稿已被更新', 409);
                }
            });

            return $this->describe($workspace->refresh());
        });
    }

    private function describe(ThemeWorkspace $workspace): array
    {
        $revision = ThemeRevision::query()->findOrFail($workspace->revision_id);

        return $workspace->only(['id', 'site_key', 'theme_id', 'source', 'revision_id', 'lock_version', 'state']) + [
            'files' => $revision->files, 'content_sha256' => $revision->content_sha256, 'settings' => $revision->settings,
            'code_authorized_until' => $workspace->code_authorized_until?->toIso8601String(),
        ];
    }

    /** @return array<string, string> */
    private function sourceContents(string $theme, string $source): array
    {
        $root = $source === 'installed' ? $this->revisions->storage->path('installed/'.$theme) : base_path();
        $contents = [];
        if ($source === 'installed') {
            $installed = $this->installed->find($theme);
            foreach ($installed['package']['files'] ?? [] as $record) {
                $bytes = $this->revisions->read($root.'/'.$record['path'], $this->guard->fileLimit($record['path']));
                if (! hash_equals($record['sha256'], hash('sha256', $bytes))) {
                    throw new ApiException('theme_integrity_failed', '安装主题已偏离安装记录', 409);
                }
                $contents[$record['path']] = $bytes;
            }
        } else {
            foreach (['resources/views/theme/'.$theme, 'public/themes/'.$theme] as $prefix) {
                if (! is_dir($root.'/'.$prefix)) {
                    continue;
                }
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$prefix, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isDir()) {
                        continue;
                    }
                    $path = substr($file->getPathname(), strlen($root) + 1);
                    $this->revisions->validatePath($path, $theme);
                    $contents[$path] = $this->revisions->read($file->getPathname(), $this->guard->fileLimit($path));
                    if (count($contents) > $this->guard->limit('max_files') || array_sum(array_map('strlen', $contents)) > $this->guard->limit('max_total_bytes')) {
                        throw new ApiException('theme_limit_exceeded', '主题超过工作区配额', 422);
                    }
                }
            }
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views/site'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isDir() || ! str_ends_with($file->getPathname(), '.blade.php')) {
                continue;
            }
            $path = 'resources/views/site/'.substr($file->getPathname(), strlen(resource_path('views/site')) + 1);
            $contents[$path] = $this->revisions->read($file->getPathname(), $this->guard->fileLimit($path));
            if (count($contents) > $this->guard->limit('max_files') || array_sum(array_map('strlen', $contents)) > $this->guard->limit('max_total_bytes')) {
                throw new ApiException('theme_limit_exceeded', '主题及回退页面超过工作区配额', 422);
            }
        }

        return $contents;
    }
}
