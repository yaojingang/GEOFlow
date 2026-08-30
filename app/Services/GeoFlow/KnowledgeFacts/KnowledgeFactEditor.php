<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Models\Admin;
use App\Models\KnowledgeFact;
use App\Models\KnowledgeFactEvidence;
use App\Models\KnowledgeFactLibrary;
use App\Models\KnowledgeFactValue;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class KnowledgeFactEditor
{
    /** @param array<string,mixed> $data */
    public function createFact(KnowledgeFactLibrary $library, array $data, Admin $admin): KnowledgeFact
    {
        return DB::transaction(function () use ($library, $data, $admin): KnowledgeFact {
            $locked = KnowledgeFactLibrary::query()->whereKey($library->id)->lockForUpdate()->firstOrFail();
            $fact = $locked->facts()->create($data + ['created_by_admin_id' => $admin->id, 'updated_by_admin_id' => $admin->id]);
            $locked->increment('working_version');

            return $fact;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function updateFact(KnowledgeFactLibrary $library, KnowledgeFact $fact, array $data, Admin $admin): KnowledgeFact
    {
        return DB::transaction(function () use ($library, $fact, $data, $admin): KnowledgeFact {
            KnowledgeFactLibrary::query()->whereKey($library->id)->lockForUpdate()->firstOrFail();
            $expected = (int) $data['lock_version'];
            unset($data['lock_version']);
            $updated = KnowledgeFact::query()->whereKey($fact->id)->where('library_id', $library->id)->where('lock_version', $expected)
                ->update($data + ['updated_by_admin_id' => $admin->id, 'lock_version' => $expected + 1, 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new ConflictHttpException('knowledge_fact_revision_conflict');
            }
            $library->increment('working_version');

            return $fact->fresh();
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function createValue(KnowledgeFactLibrary $library, KnowledgeFact $fact, array $data, Admin $admin): KnowledgeFactValue
    {
        return DB::transaction(function () use ($library, $fact, $data, $admin): KnowledgeFactValue {
            KnowledgeFactLibrary::query()->whereKey($library->id)->lockForUpdate()->firstOrFail();
            $scope = is_array($data['scope_json'] ?? null) ? $data['scope_json'] : [];
            $data['scope_hash'] = hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->guardInterval($fact, $data);
            $value = $fact->values()->create($data + ['created_by_admin_id' => $admin->id, 'updated_by_admin_id' => $admin->id]);
            $library->increment('working_version');

            return $value;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function updateValue(KnowledgeFactLibrary $library, KnowledgeFactValue $value, array $data, Admin $admin): KnowledgeFactValue
    {
        return DB::transaction(function () use ($library, $value, $data, $admin): KnowledgeFactValue {
            KnowledgeFactLibrary::query()->whereKey($library->id)->lockForUpdate()->firstOrFail();
            $expected = (int) $data['lock_version'];
            unset($data['lock_version']);
            if (array_key_exists('scope_json', $data)) {
                $data['scope_hash'] = hash('sha256', json_encode((array) $data['scope_json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            }
            $updated = KnowledgeFactValue::query()->whereKey($value->id)->where('lock_version', $expected)->whereHas('fact', fn ($query) => $query->where('library_id', $library->id))
                ->update($data + ['updated_by_admin_id' => $admin->id, 'lock_version' => $expected + 1, 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new ConflictHttpException('knowledge_fact_revision_conflict');
            }
            $library->increment('working_version');

            return $value->fresh();
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function createEvidence(KnowledgeFactLibrary $library, KnowledgeFactValue $value, array $data, Admin $admin): KnowledgeFactEvidence
    {
        return DB::transaction(function () use ($library, $value, $data, $admin): KnowledgeFactEvidence {
            KnowledgeFactLibrary::query()->whereKey($library->id)->lockForUpdate()->firstOrFail();
            $data['excerpt_hash'] = hash('sha256', trim((string) $data['excerpt']));
            $evidence = $value->evidences()->create($data + ['created_by_admin_id' => $admin->id]);
            $library->increment('working_version');

            return $evidence;
        }, 3);
    }

    public function merge(KnowledgeFactLibrary $library, KnowledgeFact $source, KnowledgeFact $target): void
    {
        DB::transaction(function () use ($library, $source, $target): void {
            KnowledgeFactLibrary::query()->whereKey($library->id)->lockForUpdate()->firstOrFail();
            $facts = KnowledgeFact::query()->whereIn('id', [$source->id, $target->id])->where('library_id', $library->id)->orderBy('id')->lockForUpdate()->get();
            abort_unless($facts->count() === 2, 404);
            KnowledgeFactValue::query()->where('fact_id', $source->id)->update(['fact_id' => $target->id, 'updated_at' => now()]);
            $source->forceFill(['is_enabled' => false, 'review_status' => 'rejected', 'lock_version' => $source->lock_version + 1])->save();
            $library->increment('working_version');
        }, 3);
    }

    /** @param list<int> $valueIds @param array<string,mixed> $data */
    public function split(KnowledgeFactLibrary $library, KnowledgeFact $source, array $valueIds, array $data, Admin $admin): KnowledgeFact
    {
        return DB::transaction(function () use ($library, $source, $valueIds, $data, $admin): KnowledgeFact {
            KnowledgeFactLibrary::query()->whereKey($library->id)->lockForUpdate()->firstOrFail();
            KnowledgeFact::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $new = $library->facts()->create(['stable_key' => $data['stable_key'], 'label' => $data['label'], 'subject' => $source->subject, 'predicate' => $source->predicate, 'value_type' => $source->value_type, 'created_by_admin_id' => $admin->id, 'updated_by_admin_id' => $admin->id]);
            $moved = KnowledgeFactValue::query()->where('fact_id', $source->id)->whereIn('id', $valueIds)->update(['fact_id' => $new->id, 'updated_at' => now()]);
            abort_if($moved !== count(array_unique($valueIds)), 422, 'knowledge_fact_split_values_invalid');
            $library->increment('working_version');

            return $new;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    private function guardInterval(KnowledgeFact $fact, array $data): void
    {
        $from = $data['valid_from'] ?? null;
        $to = $data['valid_to'] ?? null;
        if ($from !== null && $to !== null && $from > $to) {
            throw new ConflictHttpException('knowledge_fact_invalid_interval');
        }
        $overlap = $fact->values()->where('scope_hash', $data['scope_hash'])
            ->where(function ($query) use ($to): void {
                $query->whereNull('valid_from')->when($to, fn ($q) => $q->orWhere('valid_from', '<=', $to));
            })
            ->where(function ($query) use ($from): void {
                $query->whereNull('valid_to')->when($from, fn ($q) => $q->orWhere('valid_to', '>=', $from));
            })
            ->exists();
        if ($overlap) {
            throw new ConflictHttpException('knowledge_fact_interval_conflict');
        }
    }
}
