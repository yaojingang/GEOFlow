<?php

namespace App\Support\Site;

use Illuminate\Support\Facades\Log;
use Throwable;

class ArticlePermalinkPolicy
{
    public const SETTING_KEY = 'article_permalink_policy';

    public const SCHEMA_VERSION = 1;

    public const DEFAULT_PATTERN = '/article/{slug}';

    public const PRESETS = [
        'default' => ['name' => 'article_permalink.presets.default', 'pattern' => '/article/{slug}'],
        'root_category' => ['name' => 'article_permalink.presets.root_category', 'pattern' => '/{category}/{slug}'],
        'html' => ['name' => 'article_permalink.presets.html', 'pattern' => '/{slug}.html'],
        'id_slug' => ['name' => 'article_permalink.presets.id_slug', 'pattern' => '/article/{id}-{slug}.html'],
        'category' => ['name' => 'article_permalink.presets.category', 'pattern' => '/article/{category}/{slug}.html'],
        'date' => ['name' => 'article_permalink.presets.date', 'pattern' => '/article/{year}/{month}/{slug}.html'],
        'id' => ['name' => 'article_permalink.presets.id', 'pattern' => '/article/{id}.html'],
    ];

    /** @param list<array{pattern:string,retired_at:string}> $history */
    private function __construct(
        public readonly int $revision,
        public readonly string $currentPattern,
        public readonly ?string $activatedAt,
        public readonly array $history,
    ) {}

    public static function fromRaw(mixed $raw): self
    {
        try {
            if (is_string($raw)) {
                $raw = trim($raw) === '' ? [] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            }
            if (! is_array($raw)) {
                return self::defaults();
            }
            if (array_key_exists('schema_version', $raw)
                && (int) $raw['schema_version'] !== self::SCHEMA_VERSION) {
                throw new \UnexpectedValueException('Unsupported article permalink policy schema.');
            }

            $current = ArticlePermalinkPattern::compile((string) ($raw['current_pattern'] ?? self::DEFAULT_PATTERN))->pattern();
            $history = [];
            foreach ((array) ($raw['history'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $pattern = ArticlePermalinkPattern::compile((string) ($item['pattern'] ?? ''))->pattern();
                if ($pattern === $current || collect($history)->contains('pattern', $pattern)) {
                    continue;
                }
                $history[] = [
                    'pattern' => $pattern,
                    'retired_at' => (string) ($item['retired_at'] ?? ''),
                ];
            }

            return new self(
                max(0, (int) ($raw['revision'] ?? 0)),
                $current,
                isset($raw['activated_at']) ? (string) $raw['activated_at'] : null,
                $history,
            );
        } catch (Throwable $exception) {
            Log::warning('Invalid article permalink policy; using the default policy.', [
                'exception_type' => $exception::class,
            ]);

            return self::defaults();
        }
    }

    public static function defaults(): self
    {
        return new self(0, self::DEFAULT_PATTERN, null, []);
    }

    public function activate(string $pattern, ?string $activatedAt = null): self
    {
        $pattern = ArticlePermalinkPattern::compile($pattern)->pattern();
        if ($pattern === $this->currentPattern) {
            return $this;
        }

        $currentPattern = $this->currentPattern;
        $history = array_values(array_filter(
            $this->history,
            static fn (array $item): bool => $item['pattern'] !== $pattern && $item['pattern'] !== $currentPattern,
        ));
        array_unshift($history, [
            'pattern' => $currentPattern,
            'retired_at' => $activatedAt ?? now()->toIso8601String(),
        ]);

        return new self(
            $this->revision + 1,
            $pattern,
            $activatedAt ?? now()->toIso8601String(),
            $history,
        );
    }

    /** @return list<string> */
    public function patterns(): array
    {
        return array_values(array_unique([
            $this->currentPattern,
            ...array_column($this->history, 'pattern'),
            self::DEFAULT_PATTERN,
        ]));
    }

    /** @return array{schema_version:int,revision:int,current_pattern:string,activated_at:?string,history:list<array{pattern:string,retired_at:string}>} */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'revision' => $this->revision,
            'current_pattern' => $this->currentPattern,
            'activated_at' => $this->activatedAt,
            'history' => $this->history,
        ];
    }
}
