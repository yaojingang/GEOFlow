<?php

namespace App\Services\Admin\Analytics;

use App\Models\LeadSubmission;
use Illuminate\Support\Carbon;

class LeadAnalyticsFilter
{
    /** @param array<string, mixed> $input */
    public static function fromRequest(array $input): self
    {
        $hasDateInput = trim((string) ($input['lead_date_from'] ?? '')) !== ''
            || trim((string) ($input['lead_date_to'] ?? '')) !== '';
        $requestedPreset = (string) ($input['lead_preset'] ?? ($hasDateInput ? 'custom' : '30d'));
        $preset = in_array($requestedPreset, ['7d', '30d', '90d', 'custom'], true)
            ? $requestedPreset
            : '30d';
        [$dateFrom, $dateTo] = self::resolveDates($input, $preset);
        $formId = filter_var($input['lead_form_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $status = (string) ($input['lead_status'] ?? 'all');
        if (! in_array($status, ['all', ...LeadSubmission::STATUSES], true)) {
            $status = 'all';
        }

        return new self($preset, $dateFrom, $dateTo, $formId === false ? null : (int) $formId, $status);
    }

    public function __construct(
        public readonly string $preset,
        public readonly Carbon $dateFrom,
        public readonly Carbon $dateTo,
        public readonly ?int $formId,
        public readonly string $status,
    ) {}

    public function start(): Carbon
    {
        return $this->dateFrom->copy()->startOfDay();
    }

    public function end(): Carbon
    {
        return $this->dateTo->copy()->endOfDay();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lead_preset' => $this->preset,
            'lead_date_from' => $this->dateFrom->toDateString(),
            'lead_date_to' => $this->dateTo->toDateString(),
            'lead_form_id' => $this->formId,
            'lead_status' => $this->status,
        ];
    }

    /** @param array<string, mixed> $input @return array{0: Carbon, 1: Carbon} */
    private static function resolveDates(array $input, string &$preset): array
    {
        $today = Carbon::today();
        $presetDays = ['7d' => 7, '30d' => 30, '90d' => 90];
        if (isset($presetDays[$preset])) {
            return [$today->copy()->subDays($presetDays[$preset] - 1), $today->copy()];
        }

        $preset = 'custom';
        $from = self::parseDate((string) ($input['lead_date_from'] ?? '')) ?? $today->copy()->subDays(29);
        $to = self::parseDate((string) ($input['lead_date_to'] ?? '')) ?? $today->copy();

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
