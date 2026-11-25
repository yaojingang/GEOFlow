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
        $rollingRange = self::rollingRange($preset, $today);

        $rawFrom = trim((string) ($input['date_from'] ?? ''));
        $rawTo = trim((string) ($input['date_to'] ?? ''));

        // 非空但解析不了的日期：整段忽略并回退到 7 天。
        // 旧逻辑会把它当成“今天减 6 天”，再与另一端对调，生成用户没要求过的畸形区间。
        $hasInvalidDate = ($rawFrom !== '' && self::parseDate($rawFrom) === null)
            || ($rawTo !== '' && self::parseDate($rawTo) === null);

        if ($hasInvalidDate) {
            $preset = '7d';

            return [$today->copy()->subDays(6), $today->copy()];
        }

        if ($rollingRange !== null && $rawFrom === '' && $rawTo === '') {
            return $rollingRange;
        }

        $from = self::parseDate($rawFrom);
        $to = self::parseDate($rawTo);

        // 显式日期与预设窗口一致时保留预设（预设按钮继续高亮）；
        // 不一致时才以显式日期为准，避免 preset 静默吞掉用户传进来的区间。
        if ($rollingRange !== null
            && $from !== null && $to !== null
            && $from->toDateString() === $rollingRange[0]->toDateString()
            && $to->toDateString() === $rollingRange[1]->toDateString()) {
            return $rollingRange;
        }

        if ($rollingRange === null && $from === null && $to === null) {
            $preset = '7d';

            return [$today->copy()->subDays(6), $today->copy()];
        }

        $preset = 'custom';

        return AnalyticsDateRange::normalize(
            $from ?? $today->copy()->subDays(6),
            $to ?? $today->copy(),
            $today,
        );
    }

    /**
     * 滚动预设对应的固定窗口；custom 返回 null。
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private static function rollingRange(string $preset, Carbon $today): ?array
    {
        return match ($preset) {
            'today' => [$today->copy(), $today->copy()],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            '7d' => [$today->copy()->subDays(6), $today->copy()],
            '30d' => [$today->copy()->subDays(29), $today->copy()],
            '90d' => [$today->copy()->subDays(89), $today->copy()],
            default => null,
        };
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
