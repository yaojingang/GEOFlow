<?php

namespace App\Services\LinkPool;

use App\Services\Site\LinkPoolSiteService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class LinkPoolTargetService
{
    public function __construct(private readonly LinkPoolSiteService $sites) {}

    public static function isExternal(object $target): bool
    {
        return ($target->target_type ?? 'article') === 'external';
    }

    public function libraryRevision(): int
    {
        return (int) DB::table('site_settings')->where('setting_key', 'link_pool_library_revision')->value('setting_value');
    }

    /** @param array<string,mixed> $values */
    public static function operationalValuesChanged(object $target, array $values): bool
    {
        foreach ($values as $key => $value) {
            if ($key !== 'updated_at' && (string) ($target->{$key} ?? '') !== (string) ($value ?? '')) {
                return true;
            }
        }

        return false;
    }

    /** Called inside the same transaction as the target mutation. */
    public function advanceLibraryRevision(): void
    {
        DB::table('site_settings')->insertOrIgnore([
            'setting_key' => 'link_pool_library_revision', 'setting_value' => '0',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('site_settings')->where('setting_key', 'link_pool_library_revision')->lockForUpdate()->first();
        DB::table('site_settings')->where('id', $row->id)->update([
            'setting_value' => (string) ((int) $row->setting_value + 1), 'updated_at' => now(),
        ]);
    }

    public function normalizeUrl(string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 4096 || preg_match('/[\x00-\x1f\x7f<>"\\\\]/', $input)) {
            return null;
        }
        $parts = parse_url($input);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if (! mb_check_encoding($host, 'ASCII')) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($host === false) {
                return null;
            }
        }
        $ip = trim($host, '[]');
        if (! filter_var($ip, FILTER_VALIDATE_IP)
            && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) !== 1) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }
        $url = $scheme.'://'.$host.($port !== null && $port !== ($scheme === 'https' ? 443 : 80) ? ':'.$port : '');
        $url .= $parts['path'] ?? '/';
        if (isset($parts['query'])) {
            $url .= '?'.$parts['query'];
        }
        if (isset($parts['fragment'])) {
            $url .= '#'.$parts['fragment'];
        }

        return str_replace(' ', '%20', $url);
    }

    /** @return array<string,mixed>|null */
    public function resolve(string $input): ?array
    {
        $url = $this->normalizeUrl($input);
        if ($url === null) {
            return null;
        }
        $article = $this->sites->resolveImportedUrl($url);
        if ($article !== null) {
            return ['target_type' => 'article', 'url_sha256' => null, 'url' => $url, ...$article];
        }

        if ($this->sites->resolveImportedUrl($url, false) !== null) {
            return null;
        }

        return ['target_type' => 'external', 'site_key' => 'external', 'article' => null,
            'url' => $url, 'url_sha256' => hash('sha256', $url)];
    }

    public function eligible(object $target): bool
    {
        if ($target->status !== 'active' || $target->expires_at && Carbon::parse($target->expires_at)->isPast()) {
            return false;
        }

        return self::isExternal($target) ? $this->normalizeUrl($target->input_url) !== null
            : $this->sites->canPublish($target->target_site_key) && $this->sites->article($target->target_site_key, (int) $target->article_id) !== null;
    }

    public function url(object $target): ?string
    {
        if (self::isExternal($target)) {
            return $this->normalizeUrl($target->input_url);
        }
        $article = $this->sites->article($target->target_site_key, (int) $target->article_id);

        return $article ? $this->sites->url($target->target_site_key, $article) : null;
    }

    public function forPlacement(object $placement): ?object
    {
        return ($placement->target_id ?? null) !== null ? DB::table('link_pool_targets')->find($placement->target_id)
            : DB::table('link_pool_targets')->where('target_site_key', $placement->target_site_key)
                ->where('article_id', $placement->target_article_id)->first();
    }

    public function scopeEligible(Builder $query, string $alias = 'targets', ?string $siteKey = null): Builder
    {
        $siteKeys = DB::table('link_pool_targets')->where('status', 'active')->where('target_type', 'article')
            ->distinct()->pluck('target_site_key')->filter(fn (string $key): bool => $this->sites->canPublish($key))->all();

        return $query->where($alias.'.status', 'active')
            ->where(fn (Builder $q) => $q->whereNull($alias.'.expires_at')->orWhere($alias.'.expires_at', '>', now()))
            ->when($siteKey, fn (Builder $q) => $q->where($alias.'.target_site_key', $siteKey))
            ->where(function (Builder $q) use ($siteKeys, $alias): void {
                $q->where($alias.'.target_type', 'external');
                foreach ($siteKeys as $key) {
                    $q->orWhere(fn (Builder $site) => $site->where($alias.'.target_type', 'article')
                        ->where($alias.'.target_site_key', $key)
                        ->whereExists($this->sites->query($key)->selectRaw('1')->whereColumn('articles.id', $alias.'.article_id')->toBase()));
                }
            });
    }
}
