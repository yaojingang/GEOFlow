<?php

namespace App\Support\Site;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;

class FriendLinkSettings
{
    public const KEY = 'friend_links';

    public const MAX_LINKS = 50;

    public const LINK_FIELDS = ['name', 'url', 'sort_order', 'enabled', 'target', 'relationship'];

    /** @return array{state:string, raw:?string, revision:string, config:array} */
    public function snapshot(): array
    {
        $row = $this->query()->useWritePdo()->first(['setting_value']);
        $raw = $row?->setting_value;
        $config = ['enabled' => true, 'links' => []];
        $state = $row === null ? 'missing' : 'invalid';

        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
                if (is_object($decoded) && is_bool($decoded->enabled ?? null) && is_array($decoded->links ?? null) && count($decoded->links) <= self::MAX_LINKS) {
                    $strict = true;
                    foreach ($decoded->links as $link) {
                        $strict = $strict && is_object($link)
                            && is_bool($link->enabled ?? null) && is_int($link->sort_order ?? null);
                    }
                    if ($strict) {
                        $config = $this->validateConfig(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
                        $state = 'valid';
                    }
                }
            } catch (JsonException|ValidationException) {
                // Keep the original value available for explicit administrator recovery.
            }
        }

        return [
            'state' => $state,
            'raw' => $raw,
            'revision' => hash('sha256', $row === null ? 'missing' : ($raw === null ? 'null' : 'value:'.$raw)),
            'config' => $config,
        ];
    }

    /** @return list<array> */
    public function visibleLinks(): array
    {
        $snapshot = $this->snapshot();
        if ($snapshot['state'] !== 'valid' || ! $snapshot['config']['enabled']) {
            return [];
        }

        // Collection sorting preserves saved row order when sort values are equal.
        return collect($snapshot['config']['links'])->where('enabled', true)->sortBy('sort_order')->values()->all();
    }

    /** Validate the entire submitted group before any write. */
    public function validateInput(mixed $input): array
    {
        $metadata = Validator::make(['friend_links' => $input], [
            'friend_links' => ['required', 'array:enabled,links,link_count,expected_revision,replace_invalid'],
            'friend_links.enabled' => ['required', 'boolean'],
            'friend_links.link_count' => ['required', 'integer', 'between:0,'.self::MAX_LINKS],
            'friend_links.expected_revision' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'friend_links.replace_invalid' => ['sometimes', 'required', 'boolean'],
        ], [], [
            'friend_links' => __('friend_links.title'),
            'friend_links.link_count' => __('friend_links.count_label'),
            'friend_links.expected_revision' => __('friend_links.revision_label'),
            'friend_links.enabled' => __('friend_links.show'),
        ])->validate()['friend_links'];

        $links = $input['links'] ?? null;
        if (! array_key_exists('links', $input) && (int) $metadata['link_count'] === 0) {
            $links = [];
        }
        if (! is_array($links) || count($links) !== (int) $metadata['link_count']) {
            throw ValidationException::withMessages(['friend_links.links' => __('friend_links.incomplete')]);
        }

        return [
            'config' => $this->validateConfig(['enabled' => $input['enabled'], 'links' => $links]),
            'expected_revision' => $metadata['expected_revision'],
            'replace_invalid' => (bool) ($metadata['replace_invalid'] ?? false),
        ];
    }

    /** @param array{config:array, expected_revision:string, replace_invalid:bool} $payload */
    public function save(array $payload): void
    {
        $current = $this->snapshot();
        if (! hash_equals($current['revision'], $payload['expected_revision'])) {
            $this->conflict();
        }
        if ($current['state'] === 'invalid' && ! $payload['replace_invalid']) {
            throw ValidationException::withMessages(['friend_links.replace_invalid' => __('friend_links.confirm_recovery')]);
        }

        $raw = json_encode($payload['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($current['state'] === 'missing') {
            try {
                $this->query()->insert([
                    'setting_key' => self::KEY, 'setting_value' => $raw,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                // Only this key's unique constraint represents a competing first save.
                $message = (string) ($exception->errorInfo[2] ?? '');
                if (str_contains($message, 'site_settings_setting_key_unique')
                    || str_contains($message, 'UNIQUE constraint failed: site_settings.setting_key')) {
                    $this->conflict();
                }
                throw $exception;
            }

            return;
        }

        $query = $this->query();
        $current['raw'] === null ? $query->whereNull('setting_value') : $query->where('setting_value', $current['raw']);
        if ($query->update(['setting_value' => $raw, 'updated_at' => now()]) !== 1) {
            $this->conflict();
        }
    }

    private function query(): Builder
    {
        return DB::table('site_settings')->where('setting_key', self::KEY);
    }

    private function conflict(): never
    {
        throw ValidationException::withMessages(['friend_links.expected_revision' => __('friend_links.conflict')]);
    }

    private function validateConfig(array $config): array
    {
        $unsafeUrls = [];
        if (is_array($config['links'] ?? null)) {
            foreach ($config['links'] as $index => &$link) {
                if (! is_array($link)) {
                    continue;
                }
                if (is_string($link['name'] ?? null)) {
                    $link['name'] = trim($link['name']);
                }
                if (is_string($link['url'] ?? null)) {
                    $unsafeUrls[$index] = preg_match('/[\x00-\x1f\x7f\\\\]/', $link['url']) === 1;
                    $link['url'] = trim($link['url']);
                }
            }
            unset($link);
        }

        $validator = Validator::make(['friend_links' => $config], [
            'friend_links' => ['required', 'array:enabled,links'],
            'friend_links.enabled' => ['required', 'boolean'],
            'friend_links.links' => ['present', 'array', 'list', 'max:'.self::MAX_LINKS],
            'friend_links.links.*' => ['required', 'array:'.implode(',', self::LINK_FIELDS)],
            'friend_links.links.*.name' => ['required', 'string', 'max:80'],
            'friend_links.links.*.url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'friend_links.links.*.sort_order' => ['required', 'integer', 'between:0,9999'],
            'friend_links.links.*.enabled' => ['required', 'boolean'],
            'friend_links.links.*.target' => ['required', Rule::in(['_blank', '_self'])],
            'friend_links.links.*.relationship' => ['required', Rule::in(['regular', 'nofollow', 'sponsored'])],
        ], [], collect(['name', 'url', 'sort_order', 'enabled', 'target', 'relationship'])
            ->mapWithKeys(fn (string $field): array => ['friend_links.links.*.'.$field => __('friend_links.'.$field)])->all());

        $validator->after(function ($validator) use ($config, $unsafeUrls): void {
            $seen = [];
            foreach (is_array($config['links'] ?? null) ? $config['links'] : [] as $index => $link) {
                $key = 'friend_links.links.'.$index.'.url';
                if (! is_array($link) || ! is_string($link['url'] ?? null) || $validator->errors()->has($key)) {
                    continue;
                }
                // Keep Unicode bytes out of parse_url's locale-dependent control-byte
                // replacement. Preserve the original path, query and fragment below.
                $asciiUrl = preg_replace_callback('/[^\x00-\x7f]+/u', fn ($match) => rawurlencode($match[0]), $link['url']);
                $parts = $asciiUrl === null ? false : parse_url($asciiUrl);
                if (($unsafeUrls[$index] ?? false) || $parts === false || isset($parts['user']) || isset($parts['pass'])) {
                    $validator->errors()->add($key, __('friend_links.unsafe_url'));

                    continue;
                }
                $scheme = strtolower($parts['scheme']);
                $port = $parts['port'] ?? null;
                $authorityAndSuffix = substr($link['url'], strpos($link['url'], '://') + 3);
                $suffix = substr($authorityAndSuffix, strcspn($authorityAndSuffix, '/?#'));
                $normalized = $scheme.'://'.mb_strtolower(rawurldecode($parts['host']), 'UTF-8')
                    .($port !== null && $port !== ($scheme === 'https' ? 443 : 80) ? ':'.$port : '')
                    .(str_starts_with($suffix, '/') ? $suffix : '/'.$suffix);
                if (isset($seen[$normalized])) {
                    $validator->errors()->add($key, __('friend_links.duplicate'));
                }
                $seen[$normalized] = true;
            }
        });

        $validated = $validator->validate()['friend_links'];

        return [
            'enabled' => (bool) $validated['enabled'],
            'links' => array_map(fn (array $link): array => [
                'name' => $link['name'], 'url' => $link['url'],
                'sort_order' => (int) $link['sort_order'], 'enabled' => (bool) $link['enabled'],
                'target' => $link['target'], 'relationship' => $link['relationship'],
            ], $validated['links']),
        ];
    }
}
