<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Models\Admin;
use App\Models\KnowledgeFactLibrary;
use App\Models\KnowledgeFactLibraryRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KnowledgeFactPublisher
{
    public function __construct(private readonly KnowledgeFactValuePolicy $valuePolicy) {}

    public function publish(KnowledgeFactLibrary $library, Admin $admin): KnowledgeFactLibraryRevision
    {
        return DB::transaction(function () use ($library, $admin): KnowledgeFactLibraryRevision {
            $locked = KnowledgeFactLibrary::query()->whereKey($library->getKey())->lockForUpdate()->firstOrFail();
            $locked->load([
                'knowledgeBase',
                'facts' => fn ($query) => $query->where('is_enabled', true)->orderBy('id'),
                'facts.values' => fn ($query) => $query->where('review_status', '!=', 'rejected')->orderBy('id'),
                'facts.values.evidences.knowledgeChunk',
            ]);
            $manifest = $this->validatedManifest($locked);
            $encoded = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $hash = hash('sha256', $encoded);
            $revision = $locked->revisions()->create([
                'version' => ((int) $locked->revisions()->max('version')) + 1,
                'library_hash' => $hash,
                'source_hash' => $manifest['source_hash'],
                'manifest_json' => $manifest,
                'published_by_admin_id' => $admin->id,
                'published_at' => now(),
            ]);
            $locked->forceFill(['active_revision_id' => $revision->id, 'active_hash' => $hash, 'source_hash' => $manifest['source_hash'], 'serving_status' => 'ready', 'workflow_status' => 'idle'])->save();
            $library->setRawAttributes($locked->getAttributes(), true);

            return $revision;
        }, 3);
    }

    public function restore(KnowledgeFactLibrary $library, KnowledgeFactLibraryRevision $source, Admin $admin): KnowledgeFactLibraryRevision
    {
        abort_unless((int) $source->library_id === (int) $library->id, 404);

        return DB::transaction(function () use ($library, $source, $admin): KnowledgeFactLibraryRevision {
            $locked = KnowledgeFactLibrary::query()->whereKey($library->id)->lockForUpdate()->firstOrFail();
            $version = ((int) $locked->revisions()->max('version')) + 1;
            $manifest = (array) $source->manifest_json;
            $hash = hash('sha256', json_encode(['restored_from' => $source->id, 'version' => $version, 'manifest' => $manifest], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $revision = $locked->revisions()->create([
                'version' => $version,
                'library_hash' => $hash,
                'source_hash' => (string) $source->source_hash,
                'manifest_json' => $manifest,
                'published_by_admin_id' => $admin->id,
                'published_at' => now(),
                'restored_from_revision_id' => $source->id,
            ]);
            $currentSourceHash = (string) $locked->knowledgeBase()->value('chunk_source_hash');
            $servingStatus = hash_equals($currentSourceHash, (string) $source->source_hash) && $this->manifestEvidenceIsCurrent($locked, $manifest) ? 'ready' : 'stale';
            $locked->forceFill(['active_revision_id' => $revision->id, 'active_hash' => $hash, 'source_hash' => $source->source_hash, 'serving_status' => $servingStatus])->save();
            $library->setRawAttributes($locked->getAttributes(), true);

            return $revision;
        }, 3);
    }

    /** @return array<string,mixed> */
    private function validatedManifest(KnowledgeFactLibrary $library): array
    {
        $knowledgeBase = $library->knowledgeBase;
        if ($knowledgeBase->chunk_sync_status !== 'ready' || trim((string) $knowledgeBase->chunk_source_hash) === '') {
            throw ValidationException::withMessages(['library' => __('admin.knowledge_facts.validation.chunks_not_ready')]);
        }
        if ($library->facts->isEmpty()) {
            throw ValidationException::withMessages(['library' => __('admin.knowledge_facts.validation.no_facts')]);
        }

        $facts = [];
        foreach ($library->facts as $fact) {
            if ($fact->review_status !== 'reviewed' || $fact->values->isEmpty()) {
                throw ValidationException::withMessages(['library' => __('admin.knowledge_facts.validation.review_required')]);
            }
            $values = [];
            foreach ($fact->values->sortBy('id') as $value) {
                if ($value->review_status !== 'reviewed' || $value->conflict_status !== 'clear' || $value->evidences->isEmpty()) {
                    throw ValidationException::withMessages(['library' => __('admin.knowledge_facts.validation.review_required')]);
                }
                if ($fact->importance === 'critical' && in_array($fact->value_type, ['integer', 'decimal', 'number'], true) && ! $value->evidences->contains('is_primary', true)) {
                    throw ValidationException::withMessages(['library' => __('admin.knowledge_facts.validation.primary_evidence_required')]);
                }
                $this->valuePolicy->normalizeAndValidate($fact, $value->toArray(), $value);
                foreach ($value->evidences as $evidence) {
                    $chunk = $evidence->knowledgeChunk;
                    $excerpt = trim((string) $evidence->excerpt);
                    if ($chunk === null
                        || (int) $chunk->knowledge_base_id !== (int) $library->knowledge_base_id
                        || ! hash_equals((string) $chunk->source_hash, (string) $evidence->source_hash)
                        || ! hash_equals((string) $chunk->content_hash, (string) $evidence->content_hash)
                        || ! hash_equals(hash('sha256', $excerpt), (string) $evidence->excerpt_hash)
                        || ! str_contains((string) $chunk->content, $excerpt)) {
                        throw ValidationException::withMessages(['library' => 'knowledge_fact_evidence_stale']);
                    }
                }
                $values[] = [
                    'canonical_value' => $value->canonical_value_json,
                    'canonical_answer' => $value->canonical_answer,
                    'temporal_kind' => $value->temporal_kind,
                    'scope' => $value->scope_json ?? [],
                    'valid_from' => $value->valid_from?->toDateString(),
                    'valid_to' => $value->valid_to?->toDateString(),
                    'observed_at' => $value->observed_at?->toIso8601String(),
                    'comparison_policy' => $value->comparison_policy_json ?? [],
                    'evidence' => $value->evidences->map(fn ($evidence) => [
                        'source_hash' => $evidence->source_hash,
                        'content_hash' => $evidence->content_hash,
                        'locator' => $evidence->source_locator_json ?? [],
                        'excerpt_hash' => $evidence->excerpt_hash,
                        'is_primary' => $evidence->is_primary,
                    ])->values()->all(),
                ];
            }
            $facts[] = [
                'stable_key' => $fact->stable_key,
                'label' => $fact->label,
                'subject' => $fact->subject,
                'predicate' => $fact->predicate,
                'value_type' => $fact->value_type,
                'locale' => $fact->locale,
                'aliases' => $fact->aliases_json ?? [],
                'importance' => $fact->importance,
                'usage_scope' => $fact->usage_scope,
                'values' => $values,
            ];
        }

        return ['schema_version' => 1, 'source_hash' => (string) $knowledgeBase->chunk_source_hash, 'facts' => $facts];
    }

    /** @param array<string,mixed> $manifest */
    private function manifestEvidenceIsCurrent(KnowledgeFactLibrary $library, array $manifest): bool
    {
        $current = $library->knowledgeBase()->firstOrFail()->chunks()->get(['source_hash', 'content_hash'])
            ->mapWithKeys(fn ($chunk): array => [$chunk->source_hash.'|'.$chunk->content_hash => true]);
        foreach ((array) ($manifest['facts'] ?? []) as $fact) {
            foreach ((array) data_get($fact, 'values', []) as $value) {
                foreach ((array) data_get($value, 'evidence', []) as $evidence) {
                    if (! $current->has((string) ($evidence['source_hash'] ?? '').'|'.(string) ($evidence['content_hash'] ?? ''))) {
                        return false;
                    }
                }
            }
        }

        return true;
    }
}
