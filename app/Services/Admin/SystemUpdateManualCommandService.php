<?php

namespace App\Services\Admin;

use App\Models\KnowledgeMediaAsset;
use App\Models\SystemKnowledgeBase;
use App\Services\AiWorkspace\SystemKnowledgeBaseManager;
use Illuminate\Support\Facades\Schema;

final class SystemUpdateManualCommandService
{
    public function __construct(private readonly SystemKnowledgeBaseManager $systemKnowledgeBases) {}

    /**
     * @return list<array{id:string,label:string,command:string,required:bool,status:string,description:string,status_description:string}>
     */
    public function manualCommands(): array
    {
        $binding = Schema::hasTable('system_knowledge_bases')
            ? $this->systemKnowledgeBases->binding()
            : null;
        $installed = Schema::hasTable('system_knowledge_bases')
            && Schema::hasTable('knowledge_media_assets')
            && $binding instanceof SystemKnowledgeBase
            && $this->hasCurrentBundledKnowledge($binding)
            && $this->hasAllBundledMedia($binding);

        return [[
            'id' => 'sync_ai_workspace_system_knowledge',
            'label' => (string) __('admin.system_updates.manual_commands.sync_system_knowledge'),
            'command' => 'php artisan geoflow:sync-system-knowledge --key=ai_workspace_manual --media',
            'required' => true,
            'status' => $installed ? 'complete' : 'pending',
            'description' => (string) __('admin.system_updates.manual_commands.sync_system_knowledge_desc'),
            'status_description' => (string) __(
                'admin.system_updates.manual_commands.sync_system_knowledge_'.($installed ? 'complete' : 'pending').'_desc'
            ),
        ]];
    }

    private function hasCurrentBundledKnowledge(SystemKnowledgeBase $binding): bool
    {
        if (! $binding->knowledgeBase) {
            return false;
        }

        $definition = $this->systemKnowledgeBases->definition((string) $binding->system_key);

        return hash_equals(
            (string) ($definition['official_version'] ?? ''),
            (string) $binding->official_version,
        ) && hash_equals(
            (string) ($definition['content_hash'] ?? ''),
            (string) $binding->official_content_hash,
        );
    }

    private function hasAllBundledMedia(SystemKnowledgeBase $binding): bool
    {
        $manifestPath = resource_path('knowledge/ai-workspace/media/manifest.json');
        if (! is_file($manifestPath) || ! is_readable($manifestPath)) {
            return false;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)
            || ($manifest['knowledge_key'] ?? null) !== $binding->system_key
            || ! is_array($manifest['assets'] ?? null)) {
            return false;
        }

        $required = collect($manifest['assets'])
            ->filter(static fn (mixed $asset): bool => is_array($asset))
            ->map(static fn (array $asset): string => trim((string) ($asset['asset_key'] ?? ''))
                .'|'.trim((string) ($asset['locale'] ?? 'zh_CN'))
                .'|'.str_replace('sha256:', '', trim((string) ($asset['content_hash'] ?? ''))))
            ->filter(static fn (string $identity): bool => ! str_starts_with($identity, '|'))
            ->unique()
            ->values();
        if ($required->count() !== count($manifest['assets']) || $required->isEmpty()) {
            return false;
        }

        $available = KnowledgeMediaAsset::query()
            ->where('knowledge_base_id', $binding->knowledge_base_id)
            ->where('is_active', true)
            ->where('needs_review', false)
            ->get(['asset_key', 'locale', 'content_hash'])
            ->map(static fn (KnowledgeMediaAsset $asset): string => $asset->asset_key.'|'.$asset->locale.'|'.$asset->content_hash)
            ->unique();

        return $required->diff($available)->isEmpty();
    }
}
