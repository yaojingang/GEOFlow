<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeMediaAsset;
use App\Services\AiWorkspace\SystemKnowledgeMediaManager;
use App\Support\AdminActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class KnowledgeBaseMediaController extends Controller
{
    public function store(Request $request, int $knowledgeBaseId, SystemKnowledgeMediaManager $media): RedirectResponse
    {
        $admin = $this->admin($request);
        $knowledgeBase = KnowledgeBase::query()->with('systemBinding')->findOrFail($knowledgeBaseId);
        $payload = $this->validatePayload($request, true);

        try {
            $asset = $media->replace($knowledgeBase, $admin, $request->file('image'), $payload);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['image' => $exception->getMessage()]);
        }
        AdminActivityLogger::logFromRequest($request, $admin, 'system_knowledge.media_imported', [
            'knowledge_base_id' => $knowledgeBaseId,
            'media_asset_id' => $asset->getKey(),
            'asset_key' => $asset->asset_key,
            'content_hash' => $asset->content_hash,
        ]);

        return $this->backToDetail($knowledgeBaseId);
    }

    public function update(
        Request $request,
        int $knowledgeBaseId,
        int $mediaAsset,
        SystemKnowledgeMediaManager $media,
    ): RedirectResponse {
        $admin = $this->admin($request);
        $mediaAsset = KnowledgeMediaAsset::query()->findOrFail($mediaAsset);
        $this->assertBelongsTo($mediaAsset, $knowledgeBaseId);
        $payload = $this->validatePayload($request, false);

        try {
            $media->updateMetadata($mediaAsset, $admin, $payload);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['media' => $exception->getMessage()]);
        }
        AdminActivityLogger::logFromRequest($request, $admin, 'system_knowledge.media_updated', [
            'knowledge_base_id' => $knowledgeBaseId,
            'media_asset_id' => $mediaAsset->getKey(),
        ]);

        return $this->backToDetail($knowledgeBaseId);
    }

    public function replace(
        Request $request,
        int $knowledgeBaseId,
        int $mediaAsset,
        SystemKnowledgeMediaManager $media,
    ): RedirectResponse {
        $admin = $this->admin($request);
        $mediaAsset = KnowledgeMediaAsset::query()->findOrFail($mediaAsset);
        $this->assertBelongsTo($mediaAsset, $knowledgeBaseId);
        $knowledgeBase = KnowledgeBase::query()->with('systemBinding')->findOrFail($knowledgeBaseId);
        $payload = $this->validatePayload($request, true, $mediaAsset);
        $payload['asset_key'] = (string) $mediaAsset->asset_key;
        $payload['locale'] = (string) $mediaAsset->locale;

        try {
            $replacement = $media->replace($knowledgeBase, $admin, $request->file('image'), $payload);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['image' => $exception->getMessage()]);
        }
        AdminActivityLogger::logFromRequest($request, $admin, 'system_knowledge.media_replaced', [
            'knowledge_base_id' => $knowledgeBaseId,
            'media_asset_id' => $replacement->getKey(),
            'supersedes_id' => $replacement->supersedes_id,
            'content_hash' => $replacement->content_hash,
        ]);

        return $this->backToDetail($knowledgeBaseId);
    }

    public function toggle(
        Request $request,
        int $knowledgeBaseId,
        int $mediaAsset,
        SystemKnowledgeMediaManager $media,
    ): RedirectResponse {
        $admin = $this->admin($request);
        $mediaAsset = KnowledgeMediaAsset::query()->findOrFail($mediaAsset);
        $this->assertBelongsTo($mediaAsset, $knowledgeBaseId);
        $active = $request->boolean('active');

        try {
            $media->setActive($mediaAsset, $admin, $active);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['media' => $exception->getMessage()]);
        }
        AdminActivityLogger::logFromRequest($request, $admin, 'system_knowledge.media_status_changed', [
            'knowledge_base_id' => $knowledgeBaseId,
            'media_asset_id' => $mediaAsset->getKey(),
            'active' => $active,
        ]);

        return $this->backToDetail($knowledgeBaseId);
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request, bool $requiresImage, ?KnowledgeMediaAsset $defaults = null): array
    {
        $payload = $request->validate([
            'image' => [$requiresImage ? 'required' : 'nullable', 'file', 'max:8192', 'mimetypes:image/png,image/webp'],
            'asset_key' => [$defaults || ! $requiresImage ? 'nullable' : 'required', 'string', 'max:120', 'regex:/\A[a-z0-9._-]+\z/'],
            'section_key' => [$defaults ? 'nullable' : 'required', 'string', 'max:160'],
            'route_name' => [$defaults ? 'nullable' : 'required', 'string', 'max:180'],
            'title' => [$defaults ? 'nullable' : 'required', 'string', 'max:180'],
            'alt_text' => [$defaults ? 'nullable' : 'required', 'string', 'max:500'],
            'caption' => ['nullable', 'string', 'max:1000'],
            'keywords' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'locale' => ['nullable', 'in:zh_CN'],
            'needs_review' => ['nullable', 'boolean'],
        ]);
        if ($defaults instanceof KnowledgeMediaAsset) {
            foreach (['asset_key', 'section_key', 'route_name', 'title', 'alt_text', 'caption', 'sort_order', 'locale'] as $field) {
                if (! isset($payload[$field]) || trim((string) $payload[$field]) === '') {
                    $payload[$field] = $defaults->{$field};
                }
            }
            if (! isset($payload['keywords'])) {
                $payload['keywords'] = implode(', ', (array) $defaults->keywords_json);
            }
            if (! array_key_exists('needs_review', $payload)) {
                $payload['needs_review'] = (bool) $defaults->needs_review;
            }
        }

        return $payload;
    }

    private function admin(Request $request): Admin
    {
        /** @var Admin|null $admin */
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $admin->canManageProtectedWorkflows(), 403);

        return $admin;
    }

    private function assertBelongsTo(KnowledgeMediaAsset $asset, int $knowledgeBaseId): void
    {
        abort_unless((int) $asset->knowledge_base_id === $knowledgeBaseId, 404);
    }

    private function backToDetail(int $knowledgeBaseId): RedirectResponse
    {
        return redirect()
            ->route('admin.knowledge-bases.detail', ['knowledgeBaseId' => $knowledgeBaseId])
            ->with('message', __('admin.knowledge_detail.media_saved'));
    }
}
