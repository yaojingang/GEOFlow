<?php

namespace App\Services\Topics;

use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\Topic;
use App\Models\TopicPath;
use App\Services\Site\UrlChangeVersions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class TopicPathService
{
    public function register(Topic $topic): void
    {
        TopicPath::query()->firstOrCreate(['site_key' => $topic->site_key, 'slug' => $topic->slug], ['topic_id' => $topic->id, 'generation' => $topic->path_generation]);
    }

    public function preview(Topic $topic, string $slug, int $actorId): array
    {
        $this->assertAvailable($topic, $slug);
        $data = ['topic_id' => $topic->id, 'actor_id' => $actorId, 'old_slug' => $topic->slug, 'new_slug' => $slug, 'draft_version' => $topic->draft_version, 'path_generation' => $topic->path_generation, 'public_revision_id' => $topic->public_revision_id, 'site_fingerprint' => $this->siteFingerprint($topic->site_key), 'expires' => now()->addMinutes(10)->timestamp];

        return $data + ['token' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR))];
    }

    public function confirm(Topic $topic, string $token, int $actorId): Topic
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['path' => '地址预检已失效，请重新预检。']);
        }
        try {
            return DB::transaction(function () use ($topic, $data, $actorId): Topic {
                $this->lockSiteContext($topic->site_key);
                $locked = Topic::query()->lockForUpdate()->findOrFail($topic->id);
                if (($data['topic_id'] ?? null) !== $locked->id || ($data['actor_id'] ?? null) !== $actorId || ($data['expires'] ?? 0) < now()->timestamp
                    || ($data['site_fingerprint'] ?? null) !== $this->siteFingerprint($locked->site_key)
                    || ($data['old_slug'] ?? null) !== $locked->slug || ($data['draft_version'] ?? null) !== $locked->draft_version || ($data['path_generation'] ?? null) !== $locked->path_generation || ($data['public_revision_id'] ?? null) !== $locked->public_revision_id) {
                    throw ValidationException::withMessages(['path' => '专题内容或地址已更新，请重新预检。']);
                }
                $slug = (string) ($data['new_slug'] ?? '');
                $this->assertAvailable($locked, $slug);
                $this->register($locked);
                TopicPath::query()->create(['site_key' => $locked->site_key, 'slug' => $slug, 'topic_id' => $locked->id, 'generation' => $locked->path_generation + 1]);
                $locked->update(['slug' => $slug, 'path_generation' => $locked->path_generation + 1, 'draft_version' => $locked->draft_version + 1, 'manual_edit_version' => $locked->manual_edit_version + 1, 'control_version' => $locked->control_version + 1,
                    'pending_revision_id' => null, 'approved_revision_id' => null, 'approved_at' => null, 'approved_by_admin_id' => null, 'submitted_at' => null, 'maintenance_paused_at' => $locked->maintenance_paused_at ?? now()]);

                return $locked->fresh();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['path' => '该地址刚被其他专题保留，请更换地址并重新预检。']);
        }
    }

    public function resolve(string $siteKey, string $slug): ?Topic
    {
        $path = TopicPath::query()->useWritePdo()->where('site_key', $siteKey)->where('slug', $slug)->first();

        return $path ? Topic::query()->useWritePdo()->find($path->topic_id) : Topic::query()->useWritePdo()->where('site_key', $siteKey)->where('slug', $slug)->first();
    }

    private function lockSiteContext(string $siteKey): void
    {
        $scope = 'primary';
        if ($siteKey !== 'primary') {
            $profile = HostedSiteProfile::query()->findOrFail((int) substr($siteKey, 7));
            $channel = DistributionChannel::query()->whereKey($profile->distribution_channel_id)->lockForUpdate()->firstOrFail();
            abort_if($channel->status === DistributionChannel::STATUS_DELETING, 409);
            HostedSiteProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            $scope = 'hosted:'.$channel->id;
        }
        app(UrlChangeVersions::class)->snapshot([$scope], true);
    }

    private function siteFingerprint(string $siteKey): string
    {
        $profile = $siteKey === 'primary' ? null : HostedSiteProfile::query()->useWritePdo()->with('channel')->findOrFail((int) substr($siteKey, 7));
        $policy = $profile ? ($profile->channel?->site_settings['article_permalink_policy'] ?? null) : SiteSetting::query()->useWritePdo()->where('setting_key', 'article_permalink_policy')->value('setting_value');
        $scope = $profile ? 'hosted:'.$profile->distribution_channel_id : 'primary';
        $versions = app(UrlChangeVersions::class)->snapshot([$scope]);

        return hash('sha256', json_encode([$siteKey, $versions, $policy, $profile?->getAttributes(), $profile?->channel?->site_settings,
            $siteKey === 'primary' ? [config('app.url'), config('geoflow.site_url'), SiteSetting::query()->whereIn('setting_key', ['site_url', 'site_domain'])->pluck('setting_value', 'setting_key')->all()] : null], JSON_THROW_ON_ERROR));
    }

    private function assertAvailable(Topic $topic, string $slug): void
    {
        Validator::make(['path' => $slug], ['path' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', 'not_in:page']])->validate();
        app(TopicNamespaceGuard::class)->assertAvailable($topic->site_key);
        if ($slug === $topic->slug || TopicPath::query()->useWritePdo()->where('site_key', $topic->site_key)->where('slug', $slug)->exists() || Topic::withTrashed()->where('site_key', $topic->site_key)->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['path' => '该地址已被当前或历史专题保留，请使用新地址。']);
        }
    }
}
