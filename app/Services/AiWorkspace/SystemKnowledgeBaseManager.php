<?php

namespace App\Services\AiWorkspace;

use App\Data\Ai\SystemAiIdentity;
use App\Exceptions\SystemKnowledgeBaseDeletionException;
use App\Models\Admin;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseRevision;
use App\Models\SystemKnowledgeBase;
use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class SystemKnowledgeBaseManager
{
    public const AI_WORKSPACE_MANUAL_KEY = 'ai_workspace_manual';

    public function __construct(
        private readonly KnowledgeChunkSyncCoordinator $chunkSyncCoordinator,
        private readonly AdminHelpFeatureRegistry $features,
    ) {}

    /**
     * @return array{binding:SystemKnowledgeBase,knowledge_base:KnowledgeBase,created:bool,updated:bool,customized:bool,index_requested:bool}
     *
     * @throws LockTimeoutException
     */
    public function sync(string $systemKey = self::AI_WORKSPACE_MANUAL_KEY): array
    {
        $definition = $this->definition($systemKey);
        $content = $this->bundledContent($definition);
        $this->validateBundledContent($definition, $content);

        return Cache::lock('system-knowledge-sync:'.$systemKey, 30)->block(5, function () use ($systemKey, $definition, $content): array {
            $result = DB::transaction(function () use ($systemKey, $definition, $content): array {
                $binding = SystemKnowledgeBase::query()
                    ->with('knowledgeBase')
                    ->whereKey($systemKey)
                    ->lockForUpdate()
                    ->first();
                $created = false;
                $updated = false;
                $customized = false;

                if (! $binding instanceof SystemKnowledgeBase) {
                    $knowledgeBase = KnowledgeBase::query()->create($this->officialKnowledgePayload($definition, $content));
                    $binding = SystemKnowledgeBase::query()->create([
                        'system_key' => $systemKey,
                        'knowledge_base_id' => $knowledgeBase->getKey(),
                        'official_version' => $definition['official_version'],
                        'official_content_hash' => $definition['content_hash'],
                        'customized_at' => null,
                        'last_synced_at' => now(),
                    ]);
                    $this->createRevision($knowledgeBase, $content, KnowledgeBaseRevision::SOURCE_OFFICIAL);
                    $created = true;
                } else {
                    $knowledgeBase = $binding->knowledgeBase;
                    if (! $knowledgeBase instanceof KnowledgeBase) {
                        throw new RuntimeException('The system knowledge binding points to a missing knowledge base.');
                    }

                    $currentHash = hash('sha256', (string) $knowledgeBase->content);
                    $matchesBundledOfficial = hash_equals((string) $definition['content_hash'], $currentHash);
                    $matchesRecordedOfficial = hash_equals((string) $binding->official_content_hash, $currentHash);
                    if ($matchesBundledOfficial) {
                        $binding->forceFill([
                            'official_version' => $definition['official_version'],
                            'official_content_hash' => $definition['content_hash'],
                            'customized_at' => null,
                        ]);
                    } elseif (! $matchesRecordedOfficial) {
                        $customized = true;
                        if ($binding->customized_at === null) {
                            $binding->forceFill(['customized_at' => now()]);
                        }
                        $binding->forceFill([
                            'official_version' => $definition['official_version'],
                            'official_content_hash' => $definition['content_hash'],
                        ]);
                    } else {
                        $knowledgeBase->forceFill($this->officialKnowledgePayload($definition, $content))->save();
                        $this->createRevision($knowledgeBase, $content, KnowledgeBaseRevision::SOURCE_OFFICIAL);
                        $binding->forceFill([
                            'official_version' => $definition['official_version'],
                            'official_content_hash' => $definition['content_hash'],
                            'customized_at' => null,
                        ]);
                        $updated = true;
                    }

                    $binding->last_synced_at = now();
                    $binding->save();
                }

                return compact('binding', 'knowledgeBase', 'created', 'updated', 'customized');
            }, 3);

            $knowledgeBase = $result['knowledgeBase'];
            $contentHash = hash('sha256', (string) $knowledgeBase->content);
            $needsIndex = $result['created']
                || $result['updated']
                || ! hash_equals((string) $knowledgeBase->chunk_source_hash, $contentHash)
                || ! in_array((string) $knowledgeBase->chunk_sync_status, ['pending', 'processing', 'ready'], true);
            $indexRequested = $needsIndex && $this->chunkSyncCoordinator->request(
                (int) $knowledgeBase->getKey(),
                SystemAiIdentity::knowledgeIndex(),
                requireRealEmbedding: false,
                force: $result['updated'],
            );

            return [
                'binding' => $result['binding']->fresh('knowledgeBase'),
                'knowledge_base' => $knowledgeBase->fresh(),
                'created' => $result['created'],
                'updated' => $result['updated'],
                'customized' => $result['customized'],
                'index_requested' => $indexRequested,
            ];
        });
    }

    public function binding(string $systemKey = self::AI_WORKSPACE_MANUAL_KEY): ?SystemKnowledgeBase
    {
        return SystemKnowledgeBase::query()->with('knowledgeBase')->whereKey($systemKey)->first();
    }

    public function assertDeletable(KnowledgeBase $knowledgeBase): void
    {
        if ($knowledgeBase->isSystemManaged()) {
            throw new SystemKnowledgeBaseDeletionException(__('admin.knowledge_bases.error.system_delete_forbidden'));
        }
    }

    /**
     * @param  array{name:string,description?:string|null,content:string}  $payload
     *
     * @throws AuthorizationException
     */
    public function update(KnowledgeBase $knowledgeBase, Admin $admin, array $payload): KnowledgeBaseRevision
    {
        $this->authorizeProtectedEdit($knowledgeBase, $admin);
        $content = trim((string) $payload['content']);
        $definition = $this->definition((string) $knowledgeBase->systemBinding?->system_key);
        $this->validateEditableContent($definition, $content);

        $result = DB::transaction(function () use ($knowledgeBase, $admin, $definition, $content): array {
            $locked = KnowledgeBase::query()->with('systemBinding')->lockForUpdate()->findOrFail($knowledgeBase->getKey());
            $binding = $locked->systemBinding;
            if (! $binding instanceof SystemKnowledgeBase) {
                throw new RuntimeException('The knowledge base is no longer bound as system knowledge.');
            }

            $contentChanged = ! hash_equals(
                hash('sha256', trim((string) $locked->content)),
                hash('sha256', $content),
            );
            $persistedContent = $contentChanged ? $content : (string) $locked->content;
            $locked->forceFill([
                'name' => (string) $definition['name'],
                'description' => (string) $definition['description'],
                'content' => $persistedContent,
                'file_type' => 'markdown',
                'character_count' => Str::length($persistedContent),
                'word_count' => Str::length(strip_tags($persistedContent)),
                'source_name' => 'GEOFlow official system knowledge',
                'source_type' => 'system',
                'business_line' => 'GEOFlow Admin',
                'risk_level' => 'low',
                'review_status' => 'reviewed',
            ])->save();

            $binding->forceFill([
                'customized_at' => hash_equals((string) $binding->official_content_hash, hash('sha256', $persistedContent)) ? null : now(),
            ])->save();

            $revision = $contentChanged
                ? $this->createRevision($locked, $persistedContent, KnowledgeBaseRevision::SOURCE_MANUAL, $admin)
                : $locked->revisions()->first();

            return [
                'content_changed' => $contentChanged,
                'revision' => $revision instanceof KnowledgeBaseRevision
                    ? $revision
                    : $this->createRevision($locked, $persistedContent, KnowledgeBaseRevision::SOURCE_MANUAL, $admin),
            ];
        }, 3);

        if ($result['content_changed']) {
            $this->requestFallbackIndex((int) $knowledgeBase->getKey());
        }

        return $result['revision'];
    }

    /**
     * @throws AuthorizationException
     */
    public function restore(KnowledgeBase $knowledgeBase, KnowledgeBaseRevision $revision, Admin $admin): KnowledgeBaseRevision
    {
        $this->authorizeProtectedEdit($knowledgeBase, $admin);
        if ((int) $revision->knowledge_base_id !== (int) $knowledgeBase->getKey()) {
            throw new RuntimeException('The selected revision does not belong to this knowledge base.');
        }

        $definition = $this->definition((string) $knowledgeBase->systemBinding?->system_key);
        $this->validateEditableContent($definition, (string) $revision->content);

        $restored = DB::transaction(function () use ($knowledgeBase, $revision, $admin): KnowledgeBaseRevision {
            $locked = KnowledgeBase::query()->with('systemBinding')->lockForUpdate()->findOrFail($knowledgeBase->getKey());
            $binding = $locked->systemBinding;
            if (! $binding instanceof SystemKnowledgeBase) {
                throw new RuntimeException('The knowledge base is no longer bound as system knowledge.');
            }

            $content = (string) $revision->content;
            $locked->forceFill([
                'content' => $content,
                'file_type' => 'markdown',
                'character_count' => Str::length($content),
                'word_count' => Str::length(strip_tags($content)),
                'risk_level' => 'low',
                'review_status' => 'reviewed',
            ])->save();
            $binding->forceFill([
                'customized_at' => hash_equals((string) $binding->official_content_hash, hash('sha256', $content)) ? null : now(),
            ])->save();

            return $this->createRevision(
                $locked,
                $content,
                KnowledgeBaseRevision::SOURCE_RESTORE,
                $admin,
                $revision,
            );
        }, 3);

        $this->requestFallbackIndex((int) $knowledgeBase->getKey());

        return $restored;
    }

    /**
     * @throws AuthorizationException
     */
    public function adoptOfficial(KnowledgeBase $knowledgeBase, Admin $admin): KnowledgeBaseRevision
    {
        $this->authorizeProtectedEdit($knowledgeBase, $admin);
        $binding = $knowledgeBase->systemBinding;
        if (! $binding instanceof SystemKnowledgeBase) {
            throw new RuntimeException('The knowledge base is not bound as system knowledge.');
        }

        $definition = $this->definition((string) $binding->system_key);
        $content = $this->bundledContent($definition);
        $this->validateBundledContent($definition, $content);

        $result = DB::transaction(function () use ($knowledgeBase, $admin, $definition, $content): array {
            $locked = KnowledgeBase::query()->with('systemBinding')->lockForUpdate()->findOrFail($knowledgeBase->getKey());
            $binding = $locked->systemBinding;
            if (! $binding instanceof SystemKnowledgeBase) {
                throw new RuntimeException('The knowledge base is no longer bound as system knowledge.');
            }

            $contentChanged = ! hash_equals(
                hash('sha256', (string) $locked->content),
                (string) $definition['content_hash'],
            );
            $locked->forceFill($this->officialKnowledgePayload($definition, $content))->save();
            $binding->forceFill([
                'official_version' => $definition['official_version'],
                'official_content_hash' => $definition['content_hash'],
                'customized_at' => null,
                'last_synced_at' => now(),
            ])->save();

            $revision = $contentChanged
                ? $this->createRevision($locked, $content, KnowledgeBaseRevision::SOURCE_OFFICIAL, $admin)
                : $locked->revisions()->where('content_hash', (string) $definition['content_hash'])->first();

            return [
                'content_changed' => $contentChanged,
                'revision' => $revision instanceof KnowledgeBaseRevision
                    ? $revision
                    : $this->createRevision($locked, $content, KnowledgeBaseRevision::SOURCE_OFFICIAL, $admin),
            ];
        }, 3);

        if ($result['content_changed']) {
            $this->requestFallbackIndex((int) $knowledgeBase->getKey());
        }

        return $result['revision'];
    }

    /** @return array{status:string,is_customized:bool,official_update_available:bool,index_ready:bool,message:string} */
    public function health(KnowledgeBase $knowledgeBase): array
    {
        $binding = $knowledgeBase->systemBinding;
        if (! $binding instanceof SystemKnowledgeBase) {
            return [
                'status' => 'failed',
                'is_customized' => false,
                'official_update_available' => false,
                'index_ready' => false,
                'message' => 'System knowledge binding is missing.',
            ];
        }

        $currentHash = hash('sha256', (string) $knowledgeBase->content);
        $isCustomized = ! hash_equals((string) $binding->official_content_hash, $currentHash);
        $indexReady = $knowledgeBase->chunk_sync_status === 'ready'
            && hash_equals((string) $knowledgeBase->chunk_source_hash, $currentHash);
        $status = match (true) {
            $knowledgeBase->chunk_sync_status === 'failed' => 'fallback',
            in_array($knowledgeBase->chunk_sync_status, ['pending', 'processing'], true) => 'indexing',
            $isCustomized => 'customized',
            $indexReady => 'healthy',
            default => 'fallback',
        };

        return [
            'status' => $status,
            'is_customized' => $isCustomized,
            'official_update_available' => $isCustomized,
            'index_ready' => $indexReady,
            'message' => (string) ($knowledgeBase->chunk_sync_error ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    public function definition(string $systemKey = self::AI_WORKSPACE_MANUAL_KEY): array
    {
        $manifest = require resource_path('knowledge/ai-workspace/manifest.php');
        $definition = $manifest[$systemKey] ?? null;
        if (! is_array($definition)) {
            throw new RuntimeException("Unknown system knowledge key [{$systemKey}].");
        }

        return $definition;
    }

    /** @param array<string, mixed> $definition */
    public function bundledContent(array $definition): string
    {
        $file = basename((string) ($definition['content_file'] ?? ''));
        if ($file === '') {
            throw new RuntimeException('The system knowledge content file is not configured.');
        }

        $path = resource_path('knowledge/ai-workspace/'.$file);
        $content = @file_get_contents($path);
        if (! is_string($content) || $content === '') {
            throw new RuntimeException("Unable to read the system knowledge content file [{$file}].");
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function officialKnowledgePayload(array $definition, string $content): array
    {
        return [
            'name' => (string) $definition['name'],
            'description' => (string) $definition['description'],
            'content' => $content,
            'character_count' => Str::length($content),
            'used_task_count' => 0,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => Str::length(strip_tags($content)),
            'usage_count' => 0,
            'source_name' => 'GEOFlow official system knowledge',
            'source_url' => null,
            'source_type' => 'system',
            'business_line' => 'GEOFlow Admin',
            'effective_date' => now()->toDateString(),
            'risk_level' => 'low',
            'review_status' => 'reviewed',
        ];
    }

    private function createRevision(
        KnowledgeBase $knowledgeBase,
        string $content,
        string $source,
        ?Admin $admin = null,
        ?KnowledgeBaseRevision $restoredFrom = null,
    ): KnowledgeBaseRevision {
        $revisionNumber = (int) KnowledgeBaseRevision::query()
            ->whereBelongsTo($knowledgeBase)
            ->max('revision_number') + 1;

        $revision = KnowledgeBaseRevision::query()->create([
            'knowledge_base_id' => $knowledgeBase->getKey(),
            'revision_number' => $revisionNumber,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'source' => $source,
            'created_by_admin_id' => $admin?->getKey(),
            'restored_from_revision_id' => $restoredFrom?->getKey(),
        ]);

        $this->pruneRevisions($knowledgeBase);

        return $revision;
    }

    private function pruneRevisions(KnowledgeBase $knowledgeBase): void
    {
        $latestIds = KnowledgeBaseRevision::query()
            ->whereBelongsTo($knowledgeBase)
            ->orderByDesc('revision_number')
            ->limit(30)
            ->pluck('id');
        $initialOfficialId = KnowledgeBaseRevision::query()
            ->whereBelongsTo($knowledgeBase)
            ->where('source', KnowledgeBaseRevision::SOURCE_OFFICIAL)
            ->oldest('revision_number')
            ->value('id');
        $keepIds = $latestIds
            ->when($initialOfficialId !== null, static fn ($ids) => $ids->push((int) $initialOfficialId))
            ->unique()
            ->values()
            ->all();
        $frontier = $keepIds;
        while ($frontier !== []) {
            $restoredFromIds = KnowledgeBaseRevision::query()
                ->whereBelongsTo($knowledgeBase)
                ->whereIn('id', $frontier)
                ->whereNotNull('restored_from_revision_id')
                ->pluck('restored_from_revision_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $frontier = array_values(array_diff($restoredFromIds, $keepIds));
            $keepIds = array_values(array_unique([...$keepIds, ...$frontier]));
        }

        KnowledgeBaseRevision::query()
            ->whereBelongsTo($knowledgeBase)
            ->when($keepIds !== [], static fn ($query) => $query->whereNotIn('id', $keepIds))
            ->delete();
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeProtectedEdit(KnowledgeBase $knowledgeBase, Admin $admin): void
    {
        if (! $knowledgeBase->isSystemManaged()) {
            throw new RuntimeException('The knowledge base is not system managed.');
        }

        if (! $admin->canManageProtectedWorkflows()) {
            throw new AuthorizationException('Only protected workflow administrators can edit system knowledge.');
        }
    }

    /** @param array<string, mixed> $definition */
    private function validateEditableContent(array $definition, string $content): void
    {
        $hanCharacters = preg_match_all('/\p{Han}/u', $content);
        if ($hanCharacters === false || $hanCharacters < (int) ($definition['minimum_han_characters'] ?? 0)) {
            throw new RuntimeException('系统知识正文至少需要 '.(int) ($definition['minimum_han_characters'] ?? 0).'个中文字符。');
        }

        foreach ((array) ($definition['required_sections'] ?? []) as $section) {
            if (! Str::contains($content, (string) $section)) {
                throw new RuntimeException("系统知识缺少必需章节：{$section}");
            }
        }

        $directiveCount = substr_count($content, '[[route:');
        preg_match_all('/\[\[route:([^|\]]+)\|[^\]]+\]\]/u', $content, $matches);
        if ($directiveCount !== count($matches[0] ?? [])) {
            throw new RuntimeException('系统知识中存在格式不完整的 route 指令。');
        }

        $routeNames = array_values(array_unique($matches[1] ?? []));
        foreach ((array) ($definition['required_route_names'] ?? []) as $requiredRouteName) {
            if (! in_array((string) $requiredRouteName, $routeNames, true)) {
                throw new RuntimeException("系统知识缺少必需入口：{$requiredRouteName}");
            }
        }

        foreach ($routeNames as $routeName) {
            $route = app('router')->getRoutes()->getByName((string) $routeName);
            if ($this->features->featureForRoute((string) $routeName) === null
                || $route === null
                || ! in_array('GET', $route->methods(), true)
                || $route->parameterNames() !== []) {
                throw new RuntimeException("系统知识包含不安全的 route 指令：{$routeName}");
            }
        }

        if (preg_match('/(?:sk|ghp|xox[baprs])-?[A-Za-z0-9_-]{16,}/', $content) === 1
            || preg_match('/AKIA[A-Z0-9]{16}/', $content) === 1
            || preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $content) === 1
            || preg_match('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/', $content) === 1
            || preg_match('#/(?:Users|home|root)/#', $content) === 1
            || preg_match('/[A-Za-z]:\\\\(?:Users|Documents and Settings)\\\\/i', $content) === 1) {
            throw new RuntimeException('系统知识正文疑似包含密钥、邮箱或服务器绝对路径。');
        }
        if (preg_match('/!\[[^\]]*\]\((?:[^)]*)\)/u', $content) === 1
            || preg_match('/\[[^\]]+\]\((?:https?:|data:|javascript:)[^)]*\)/iu', $content) === 1
            || preg_match('/<(?:img|iframe)\b/iu', $content) === 1
            || preg_match('/<a\b[^>]*\bhref\s*=\s*["\']?(?:https?:|data:|javascript:)/iu', $content) === 1) {
            throw new RuntimeException('系统知识正文中的图片和入口必须使用受控媒体与 route 指令。');
        }
    }

    private function requestFallbackIndex(int $knowledgeBaseId): void
    {
        $this->chunkSyncCoordinator->request(
            $knowledgeBaseId,
            SystemAiIdentity::knowledgeIndex(),
            requireRealEmbedding: false,
            force: true,
        );
    }

    /** @param array<string, mixed> $definition */
    private function validateBundledContent(array $definition, string $content): void
    {
        $contentHash = hash('sha256', $content);
        if (! hash_equals((string) ($definition['content_hash'] ?? ''), $contentHash)) {
            throw new RuntimeException('The system knowledge content hash does not match its manifest.');
        }

        $this->validateEditableContent($definition, $content);
    }
}
