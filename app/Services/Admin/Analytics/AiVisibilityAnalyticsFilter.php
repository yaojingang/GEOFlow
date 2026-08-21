<?php

namespace App\Services\Admin\Analytics;

use App\Models\AiVisibilityRun;
use Illuminate\Support\Carbon;

class AiVisibilityAnalyticsFilter
{
    /** @param array<string, mixed> $input */
    public static function fromRequest(array $input): self
    {
        $hasDateInput = trim((string) ($input['ai_date_from'] ?? '')) !== ''
            || trim((string) ($input['ai_date_to'] ?? '')) !== '';
        $requestedPreset = (string) ($input['ai_preset'] ?? ($hasDateInput ? 'custom' : '60d'));
        $preset = in_array($requestedPreset, ['14d', '30d', '60d', '90d', 'custom'], true)
            ? $requestedPreset
            : '60d';
        [$dateFrom, $dateTo] = self::resolveDates($input, $preset);
        $provider = (string) ($input['ai_provider'] ?? 'all');
        if (! in_array($provider, [
            'all',
            AiVisibilityRun::PROVIDER_DOUBAO_ARK_RESPONSES,
            AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS,
        ], true)) {
            $provider = 'all';
        }

        return new self(
            preset: $preset,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            keyword: mb_substr(trim((string) ($input['ai_keyword'] ?? '')), 0, 160),
            provider: $provider,
        );
    }

    public static function forDays(int $days): self
    {
        $days = max(1, min(90, $days));
        $preset = in_array($days, [14, 30, 60, 90], true) ? $days.'d' : 'custom';
        $today = Carbon::today();

        return new self($preset, $today->copy()->subDays($days - 1), $today->copy(), '', 'all');
    }

    public function __construct(
        public readonly string $preset,
        public readonly Carbon $dateFrom,
        public readonly Carbon $dateTo,
        public readonly string $keyword,
        public readonly string $provider,
    ) {}

    public function start(): Carbon
    {
        return $this->dateFrom->copy()->startOfDay();
    }

    public function end(): Carbon
    {
        return $this->dateTo->copy()->endOfDay();
    }

    public function days(): int
    {
        return (int) $this->dateFrom->diffInDays($this->dateTo) + 1;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ai_preset' => $this->preset,
            'ai_date_from' => $this->dateFrom->toDateString(),
            'ai_date_to' => $this->dateTo->toDateString(),
            'ai_keyword' => $this->keyword,
            'ai_provider' => $this->provider,
        ];
    }

    /** @param array<string, mixed> $input @return array{0: Carbon, 1: Carbon} */
    private static function resolveDates(array $input, string &$preset): array
    {
        $today = Carbon::today();
        $presetDays = ['14d' => 14, '30d' => 30, '60d' => 60, '90d' => 90];
        if (isset($presetDays[$preset])) {
            return [$today->copy()->subDays($presetDays[$preset] - 1), $today->copy()];
        }

        $preset = 'custom';
        $from = self::parseDate((string) ($input['ai_date_from'] ?? '')) ?? $today->copy()->subDays(59);
        $to = self::parseDate((string) ($input['ai_date_to'] ?? '')) ?? $today->copy();

        return AnalyticsDateRange::normalize($from, $to, $today);
    }

    private static function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);

            return $date->toDateString() === $value ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
