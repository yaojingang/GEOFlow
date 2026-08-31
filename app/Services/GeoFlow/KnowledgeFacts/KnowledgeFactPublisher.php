<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Models\Admin;
use App\Models\KnowledgeFactLibrary;
use App\Models\KnowledgeFactLibraryRevision;
use App\Services\GeoFlow\ArticleAiQualityInvalidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class KnowledgeFactPublisher
{
    public function __construct(
        private readonly KnowledgeFactValuePolicy $valuePolicy,
        private readonly ArticleAiQualityInvalidationService $invalidation,
    ) {}

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
            $this->assertManifestShape($manifest);
            $encoded = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertManifestBudget($manifest, $encoded);
            $hash = hash('sha256', $encoded);
            if (trim((string) $locked->active_hash) !== ''
                && hash_equals((string) $locked->active_hash, $hash)
                && (int) $locked->active_revision_id > 0) {
                return KnowledgeFactLibraryRevision::query()->findOrFail((int) $locked->active_revision_id);
            }
            $revision = $locked->revisions()->create([
                'version' => ((int) $locked->revisions()->max('version')) + 1,
                'library_hash' => $hash,
                'source_hash' => $manifest['source_hash'],
                'manifest_json' => $manifest,
                'published_by_admin_id' => $admin->id,
                'published_at' => now(),
            ]);
            $locked->forceFill(['active_revision_id' => $revision->id, 'active_hash' => $hash, 'active_fact_count' => count($manifest['facts']), 'source_hash' => $manifest['source_hash'], 'serving_status' => 'ready', 'workflow_status' => 'idle'])->save();
            DB::afterCommit(fn () => $this->invalidation->invalidateKnowledgeBase(
                (int) $locked->knowledge_base_id,
                'atomic_fact_revision_published',
                ['atomic'],
                'atomic_revision_changed',
            ));
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
            $this->assertManifestShape($manifest);
            $this->assertManifestBudget(
                $manifest,
                json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
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
            $currentSourceHash = $locked->knowledgeBase()->firstOrFail()->servingChunkSourceHash();
            $servingStatus = hash_equals($currentSourceHash, (string) $source->source_hash) && $this->manifestEvidenceIsCurrent($locked, $manifest) ? 'ready' : 'stale';
            $locked->forceFill(['active_revision_id' => $revision->id, 'active_hash' => $hash, 'active_fact_count' => count((array) ($manifest['facts'] ?? [])), 'source_hash' => $source->source_hash, 'serving_status' => $servingStatus])->save();
            DB::afterCommit(fn () => $this->invalidation->invalidateKnowledgeBase(
                (int) $locked->knowledge_base_id,
                'atomic_fact_revision_restored',
                ['atomic'],
                'atomic_revision_changed',
            ));
            $library->setRawAttributes($locked->getAttributes(), true);

            return $revision;
        }, 3);
    }

    /** @return array<string,mixed> */
    private function validatedManifest(KnowledgeFactLibrary $library): array
    {
        $knowledgeBase = $library->knowledgeBase;
        $servingSourceHash = $knowledgeBase->servingChunkSourceHash();
        if ($knowledgeBase->chunk_sync_status !== 'ready'
            || $servingSourceHash === '') {
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
                        || ($knowledgeBase->chunk_serving_generation !== null
                            && (string) $chunk->generation_key !== (string) $knowledgeBase->chunk_serving_generation)
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
                        'excerpt' => $evidence->excerpt,
                        'knowledge_chunk_id' => $evidence->knowledge_chunk_id,
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

        return ['schema_version' => 1, 'source_hash' => $servingSourceHash, 'facts' => $facts];
    }

    /** @param array<string,mixed> $manifest */
    private function manifestEvidenceIsCurrent(KnowledgeFactLibrary $library, array $manifest): bool
    {
        $knowledgeBase = $library->knowledgeBase()->firstOrFail();
        $servingGeneration = trim((string) $knowledgeBase->chunk_serving_generation);
        $current = $knowledgeBase->chunks()
            ->when(
                $servingGeneration !== '',
                fn ($query) => $query->where('generation_key', $servingGeneration),
                fn ($query) => $query->whereNull('generation_key'),
            )
            ->get(['source_hash', 'content_hash'])
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

    /** @param array<string,mixed> $manifest */
    private function assertManifestShape(array $manifest): void
    {
        Validator::make($manifest, [
            'schema_version' => ['required', 'integer', 'min:1'],
            'source_hash' => ['required', 'string', 'size:64'],
            'facts' => ['required', 'array', 'max:10000'],
            'facts.*.stable_key' => ['required', 'string', 'max:160'],
            'facts.*.label' => ['required', 'string', 'max:255'],
            'facts.*.subject' => ['required', 'string', 'max:255'],
            'facts.*.predicate' => ['required', 'string', 'max:255'],
            'facts.*.value_type' => ['required', 'string', 'in:string,integer,decimal,number,percentage,date,range,boolean,url,path,version'],
            'facts.*.aliases' => ['present', 'array', 'max:50'],
            'facts.*.aliases.*' => ['string', 'max:255'],
            'facts.*.importance' => ['required', 'string', 'in:critical,high,normal'],
            'facts.*.values' => ['required', 'array', 'min:1', 'max:1000'],
            'facts.*.values.*.canonical_value' => ['required', 'array:value,unit'],
            'facts.*.values.*.canonical_value.value' => ['required', 'string', 'max:5000'],
            'facts.*.values.*.canonical_value.unit' => ['nullable', 'string', 'max:64'],
            'facts.*.values.*.canonical_answer' => ['required', 'string', 'max:5000'],
            'facts.*.values.*.scope' => ['present', 'array', 'max:20'],
            'facts.*.values.*.scope.*' => ['nullable', 'string', 'max:255'],
            'facts.*.values.*.comparison_policy' => ['present', 'array:tolerance'],
            'facts.*.values.*.comparison_policy.tolerance' => ['nullable', 'numeric', 'min:0'],
            'facts.*.values.*.evidence' => ['required', 'array', 'min:1', 'max:1000'],
            'facts.*.values.*.evidence.*.source_hash' => ['required', 'string', 'size:64'],
            'facts.*.values.*.evidence.*.content_hash' => ['required', 'string', 'size:64'],
            'facts.*.values.*.evidence.*.excerpt' => ['required', 'string', 'max:5000'],
            'facts.*.values.*.evidence.*.knowledge_chunk_id' => ['required', 'integer', 'min:1'],
        ])->validate();
    }

    /** @param array<string,mixed> $manifest */
    private function assertManifestBudget(array $manifest, string $encoded): void
    {
        $facts = (array) ($manifest['facts'] ?? []);
        $valueCount = 0;
        $evidenceCount = 0;
        foreach ($facts as $fact) {
            $values = is_array($fact) ? (array) ($fact['values'] ?? []) : [];
            $valueCount += count($values);
            foreach ($values as $value) {
                $evidenceCount += is_array($value) ? count((array) ($value['evidence'] ?? [])) : 0;
            }
        }
        if (strlen($encoded) > (int) config('geoflow.ai_quality_atomic_manifest_max_bytes', 10_000_000)
            || count($facts) > (int) config('geoflow.ai_quality_atomic_manifest_max_facts', 10_000)
            || $valueCount > (int) config('geoflow.ai_quality_atomic_manifest_max_values', 20_000)
            || $evidenceCount > (int) config('geoflow.ai_quality_atomic_manifest_max_evidence', 50_000)) {
            throw ValidationException::withMessages(['library' => 'knowledge_fact_manifest_budget_exceeded']);
        }
    }
}
