<?php

namespace App\Services\Topics;

use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Support\Site\ArticlePermalinkPattern;
use App\Support\Site\ArticlePermalinkPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Existing article addresses retain ownership until a deliberate URL migration. */
final class TopicNamespaceGuard
{
    /** @return list<string> */
    public function conflicts(string $siteKey): array
    {
        if (! Schema::hasTable('site_settings')) {
            return [];
        }
        $raw = $siteKey === 'primary'
            ? SiteSetting::query()->useWritePdo()->where('setting_key', ArticlePermalinkPolicy::SETTING_KEY)->value('setting_value')
            : (HostedSiteProfile::query()->useWritePdo()->with(['channel' => fn ($query) => $query->useWritePdo()])->findOrFail((int) substr($siteKey, 7))->channel?->site_settings[ArticlePermalinkPolicy::SETTING_KEY] ?? null);
        $conflicts = [];
        foreach (ArticlePermalinkPolicy::fromRaw($raw)->patterns() as $pattern) {
            $compiled = ArticlePermalinkPattern::compileStored($pattern);
            $first = explode('/', ltrim($pattern, '/'), 2)[0];
            if ($first === 'topics' || ($compiled->usesRootCategorySegment() && $this->categoryOwnsTopics())) {
                $conflicts[] = $pattern;
            }
        }

        return array_values(array_unique($conflicts));
    }

    public function assertAvailable(string $siteKey): void
    {
        $conflicts = $this->conflicts($siteKey);
        if ($conflicts !== []) {
            throw ValidationException::withMessages(['site' => '现有文章地址占用 /topics：'.implode('、', $conflicts).'。专题草稿已保留；请在网站地址设置中完成旧链接迁移后启用专题。']);
        }
    }

    private function categoryOwnsTopics(): bool
    {
        foreach (['categories', 'category_slug_histories'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->useWritePdo()->whereRaw('LOWER(TRIM(slug)) = ?', ['topics'])->exists()) {
                return true;
            }
        }

        return false;
    }
}
