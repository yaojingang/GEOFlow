<?php

namespace App\Services\Topics;

use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\SiteThemeBinding;
use App\Models\ThemeRevision;
use App\Services\Api\ThemeRevisionStorage;
use App\Support\Site\InstalledSiteThemeRepository;
use App\Support\Site\SiteThemePreviewContext;
use App\Support\Site\ThemeRevisionContext;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;

/** The manifest selects known core layouts or a view inside its own theme package. */
final class TopicTemplateCatalog
{
    public const CORE = ['default' => '标准聚合', 'guide' => '阅读指南', 'roundup' => '资讯盘点'];

    public static function declaration(): array
    {
        return ['contract' => 1, 'layouts' => array_map(fn ($id, $name) => ['id' => $id, 'name' => $name, 'view' => 'site.topics.templates.'.$id], array_keys(self::CORE), self::CORE), 'homepage_module' => ['view' => 'site.partials.topic-home', 'enabled' => true]];
    }

    /** Strict metadata shared by package inspection and runtime resolution. */
    public static function validateDeclaration(mixed $topic): array
    {
        if (! is_array($topic) || ($topic['contract'] ?? null) !== 1 || ! is_array($topic['layouts'] ?? null) || ! array_is_list($topic['layouts']) || count($topic['layouts']) < 1 || count($topic['layouts']) > 24) {
            throw new \InvalidArgumentException('Invalid topic capability');
        }
        $ids = [];
        foreach ($topic['layouts'] as $layout) {
            if (! is_array($layout) || ! is_string($layout['id'] ?? null) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $layout['id']) || strlen($layout['id']) > 64 || isset($ids[$layout['id']]) || ! is_string($layout['name'] ?? null) || trim($layout['name']) === '' || mb_strlen($layout['name']) > 60 || ! is_string($layout['view'] ?? null)) {
                throw new \InvalidArgumentException('Invalid topic layout');
            }
            $ids[$layout['id']] = true;
            if (! in_array($layout['view'], array_map(fn ($id) => 'site.topics.templates.'.$id, array_keys(self::CORE)), true) && ! preg_match('~^topics/templates/[a-z0-9]+(?:-[a-z0-9]+)*\.blade\.php$~D', $layout['view'])) {
                throw new \InvalidArgumentException('Invalid topic view');
            }
        }
        if (! isset($ids['default'])) {
            throw new \InvalidArgumentException('Default topic layout required');
        }
        $home = $topic['homepage_module'] ?? null;
        if (! is_array($home) || ($home['view'] ?? null) !== 'site.partials.topic-home' || ! is_bool($home['enabled'] ?? null)) {
            throw new \InvalidArgumentException('Invalid topic homepage module');
        }

        return $topic;
    }

    public static function capability(array $manifest): array
    {
        if (! array_key_exists('topic', $manifest)) {
            return self::declaration() + ['source' => 'core_fallback'];
        }

        return self::validateDeclaration($manifest['topic']) + ['source' => 'theme_manifest'];
    }

    /** @return array<string,string> */
    public function options(string $siteKey): array
    {
        [$theme, $manifest] = $this->manifest($siteKey);
        $options = [];
        foreach (self::capability($manifest)['layouts'] as $layout) {
            $options[$layout['id']] = $layout['name'];
        }

        return $options;
    }

    public function assertAvailableForSite(string $siteKey, string $key, string $field = 'template_key'): void
    {
        if (! array_key_exists($key, $this->options($siteKey))) {
            throw ValidationException::withMessages([$field => '当前网站模板未提供这个专题布局，请选择可用布局，或前往模板设置完成适配。']);
        }
    }

    /** Runtime reads the same immutable revision as the view finder, including workspace previews. */
    public function viewForSite(string $siteKey, string $key): string
    {
        [$theme, $manifest] = $this->manifest($siteKey, true);
        foreach (self::capability($manifest)['layouts'] as $layout) {
            if ($layout['id'] !== $key) {
                continue;
            }
            $view = str_starts_with($layout['view'], 'site.') ? $layout['view'] : 'theme.'.$theme.'.'.str_replace('/', '.', substr($layout['view'], 0, -10));
            if (str_starts_with($view, 'site.')) {
                $override = 'theme.'.$theme.'.topics.templates.'.$key;

                return $theme !== '' && View::exists($override) ? $override : $view;
            }
            if (View::exists($view)) {
                return $view;
            }
        }

        return 'site.topics.templates.default';
    }

    public function homeEnabled(string $siteKey): bool
    {
        [, $manifest] = $this->manifest($siteKey, true);

        return self::capability($manifest)['homepage_module']['enabled'];
    }

    /** @return array{string,array} */
    private function manifest(string $siteKey, bool $runtime = false): array
    {
        $snapshot = $runtime ? app(ThemeRevisionContext::class)->snapshot() : null;
        if ($snapshot !== null) {
            $theme = $snapshot['theme_id'];
            $revisionId = $snapshot['revision_id'];
        } else {
            $profile = $siteKey === 'primary' ? null : HostedSiteProfile::query()->with('channel')->findOrFail((int) substr($siteKey, 7));
            $theme = $siteKey === 'primary' ? (string) (SiteSetting::query()->where('setting_key', 'active_theme')->value('setting_value') ?? config('geoflow.default_theme', '')) : (string) ($profile->channel?->site_settings['theme_id'] ?? $profile->channel?->template_key ?? '');
            $binding = SiteThemeBinding::query()->find($siteKey);
            $revisionId = $binding?->theme_id === $theme ? $binding?->revision_id : null;
            $preview = $runtime ? app(SiteThemePreviewContext::class)->themeId() : null;
            if ($preview !== null) {
                $theme = $preview;
                $revisionId = null;
            }
        }
        if (! preg_match('/^[a-zA-Z0-9_-]+$/D', $theme)) {
            return ['', []];
        }
        $path = 'resources/views/theme/'.$theme.'/manifest.json';
        if ($revisionId) {
            $revision = ThemeRevision::query()->findOrFail($revisionId);
            if (! isset($revision->files[$path])) {
                return [$theme, []];
            }
            $storage = app(ThemeRevisionStorage::class);
            $bytes = $storage->read($storage->path($revision, $path), 1048576);
            abort_unless(hash_equals($revision->files[$path]['sha256'], hash('sha256', $bytes)), 503);

            return [$theme, json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)];
        }
        if (is_file(base_path($path))) {
            return [$theme, json_decode(file_get_contents(base_path($path)), true, flags: JSON_THROW_ON_ERROR)];
        }

        return [$theme, app(InstalledSiteThemeRepository::class)->find($theme)['manifest'] ?? []];
    }
}
