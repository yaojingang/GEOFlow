<?php

namespace App\Services\Admin\Analytics;

use Illuminate\Support\Carbon;

class AnalyticsFilter
{
    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromRequest(array $input): self
    {
        $hasDateInput = trim((string) ($input['date_from'] ?? '')) !== '' || trim((string) ($input['date_to'] ?? '')) !== '';
        $preset = self::normalizePreset((string) ($input['preset'] ?? ($hasDateInput ? 'custom' : '7d')));
        [$dateFrom, $dateTo] = self::resolveDates($input, $preset);

        return new self(
            preset: $preset,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            channelId: self::nullablePositiveInt($input['channel_id'] ?? null),
            taskId: self::nullablePositiveInt($input['task_id'] ?? null),
            categoryId: self::nullablePositiveInt($input['category_id'] ?? null),
            articleId: self::nullablePositiveInt($input['article_id'] ?? null),
        );
    }

    public function __construct(
        public readonly string $preset,
        public readonly Carbon $dateFrom,
        public readonly Carbon $dateTo,
        public readonly ?int $channelId,
        public readonly ?int $taskId,
        public readonly ?int $categoryId,
        public readonly ?int $articleId,
    ) {}

    public function start(): Carbon
    {
        return $this->dateFrom->copy()->startOfDay();
    }

    public function end(): Carbon
    {
        return $this->dateTo->copy()->endOfDay();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'date_from' => $this->dateFrom->toDateString(),
            'date_to' => $this->dateTo->toDateString(),
            'channel_id' => $this->channelId,
            'task_id' => $this->taskId,
            'category_id' => $this->categoryId,
            'article_id' => $this->articleId,
        ];
    }

    private static function normalizePreset(string $preset): string
    {
        return in_array($preset, ['today', 'yesterday', '7d', '30d', '90d', 'custom'], true) ? $preset : '7d';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function resolveDates(array $input, string &$preset): array
    {
        $today = Carbon::today();

        if ($preset === 'today') {
            return [$today->copy(), $today->copy()];
        }

        if ($preset === 'yesterday') {
            $yesterday = $today->copy()->subDay();

            return [$yesterday, $yesterday->copy()];
        }

        if ($preset === '30d') {
            return [$today->copy()->subDays(29), $today->copy()];
        }

        if ($preset === '90d') {
            return [$today->copy()->subDays(89), $today->copy()];
        }

        if ($preset === '7d') {
            return [$today->copy()->subDays(6), $today->copy()];
        }

        $rawFrom = trim((string) ($input['date_from'] ?? ''));
        $rawTo = trim((string) ($input['date_to'] ?? ''));
        if ($preset === 'custom' || $rawFrom !== '' || $rawTo !== '') {
            $preset = 'custom';
            $from = self::parseDate($rawFrom) ?? $today->copy()->subDays(6);
            $to = self::parseDate($rawTo) ?? $today->copy();

            return AnalyticsDateRange::normalize($from, $to, $today);
        }

        $preset = '7d';

        return [$today->copy()->subDays(6), $today->copy()];
    }

    private static function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);

            return $date->toDateString() === $value ? $date->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $integer === false ? null : (int) $integer;
    }
}
