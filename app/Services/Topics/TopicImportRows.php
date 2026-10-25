<?php

namespace App\Services\Topics;

use App\Models\Category;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class TopicImportRows
{
    private const HEADERS = ['title' => ['title', 'topic_title', '标题', '专题标题'], 'audience' => ['audience', '读者', '目标读者', '适合人群'], 'category' => ['category', '分类', '文章分类'], 'tags' => ['tags', '标签'], 'template' => ['template', 'template_key', '模板'], 'freshness' => ['freshness', '时效', '时效模式'], 'sourcecoverage' => ['sourcecoverage', 'source_coverage', '来源范围', '来源覆盖期', '适用范围与限制', '适用范围', '范围', 'scope', 'scope_note']];

    public function csv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages(['csv' => '请上传 UTF-8 编码的 CSV 文件。']);
        }
        $quoted = false;
        for ($i = 0; $i < strlen($content); $i++) {
            if ($content[$i] !== '"') {
                continue;
            }
            if ($quoted && ($content[$i + 1] ?? '') === '"') {
                $i++;
            } else {
                $quoted = ! $quoted;
            }
        }
        if ($quoted) {
            throw ValidationException::withMessages(['csv' => 'CSV 的引号没有闭合，请检查文件。']);
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $records = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            if ($row !== [null]) {
                $records[] = $row;
            }
        }
        fclose($stream);
        $header = [];
        foreach ($records[0] ?? [] as $index => $cell) {
            foreach (self::HEADERS as $key => $aliases) {
                if (in_array(mb_strtolower(trim((string) $cell)), $aliases, true)) {
                    if (isset($header[$key])) {
                        throw ValidationException::withMessages(['csv' => 'CSV 表头重复：'.$cell.'。请每个字段只保留一列。']);
                    }
                    $header[$key] = $index;
                }
            }
        }
        if ($header !== [] && ! isset($header['title'])) {
            throw ValidationException::withMessages(['csv' => 'CSV 表头需包含 title 或「标题」列，标题可位于任意列。']);
        }
        if ($header === [] && count($records[0] ?? []) > 1) {
            throw ValidationException::withMessages(['csv' => '多列 CSV 请使用 title 或「标题」表头。']);
        }
        if ($header !== []) {
            array_shift($records);
        } else {
            $header = ['title' => 0];
        }

        return array_map(function (array $record) use ($header): array {
            $row = [];
            foreach ($header as $key => $index) {
                $row[$key] = trim((string) ($record[$index] ?? ''));
            }
            $row['title'] = trim(preg_replace('/\s+/u', ' ', $row['title']) ?? '');

            return ['title' => $row['title'], 'metadata' => array_filter(array_diff_key($row, ['title' => true]), fn ($value) => $value !== '')];
        }, $records);
    }

    /** Bind each declared range to a real library title while preserving the public topic title. */
    public function taskTitle(array $row, array $settings): array
    {
        $normalized = $this->normalize($row);
        $identity = app(TopicGenerationService::class)->identityPayload(array_replace($settings, $normalized['payload'], ['filters' => array_replace(['category_ids' => $settings['category_ids'] ?? []], $normalized['filters'])]));
        $key = TopicPayload::topicKey($normalized['title'], $identity);
        $parts = array_filter([(string) ($identity['summary']['scope'] ?? '')]);
        $freshness = $identity['freshness'] ?? [];
        $coverage = match ($freshness['mode'] ?? 'evergreen') {
            'annual' => '年度覆盖 '.($freshness['year'] ?? '待补充'),
            'monthly' => isset($freshness['year'], $freshness['month']) ? '月度覆盖 '.sprintf('%04d-%02d', $freshness['year'], $freshness['month']) : '月度覆盖待补充',
            'version' => '版本覆盖 '.($freshness['version'] ?? ''),
            default => '',
        };
        if ($coverage !== '') {
            $parts[] = $coverage;
        }
        if (! in_array($freshness['mode'] ?? 'evergreen', ['none', 'evergreen', 'composed_at'], true) && ! empty($freshness['coverage_note'])) {
            $parts[] = $freshness['coverage_note'];
        }
        $label = mb_strtolower(trim(preg_replace('/\s+/u', ' ', implode('；', array_unique($parts))) ?? ''), 'UTF-8');
        $libraryTitle = $normalized['title'];
        $overrides = $normalized['metadata'];
        if ($key !== TopicPayload::titleKey($normalized['title'])) {
            $suffix = '（范围：'.mb_substr($label, 0, 90).' · '.substr($key, 0, 16).'）';
            $libraryTitle = mb_substr($normalized['title'], 0, max(1, 200 - mb_strlen($suffix))).$suffix;
            $overrides['topic_title'] = $normalized['title'];
        }

        return $normalized + ['topic_key' => $key, 'scope_label' => $label, 'library_title' => $libraryTitle, 'overrides' => $overrides];
    }

    public function normalize(array $row): array
    {
        $data = Validator::make($row, [
            'title' => ['required', 'string', 'max:200'], 'metadata' => ['nullable', 'array:audience,category,tags,template,freshness,sourcecoverage'],
            'metadata.audience' => ['nullable', 'string', 'max:500'], 'metadata.category' => ['nullable', 'string', 'max:100'],
            'metadata.tags' => ['nullable', 'string', 'max:3000'], 'metadata.template' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'metadata.freshness' => ['nullable', 'string', 'max:2000'], 'metadata.sourcecoverage' => ['nullable', 'string', 'max:2000'],
        ], ['title.required' => '标题为空', 'title.max' => '标题超过 200 字', 'metadata.template.regex' => '模板名称须使用当前可选布局的标识'], ['title' => '专题标题', 'metadata' => '本行配置', 'metadata.audience' => '目标读者', 'metadata.category' => '来源分类', 'metadata.tags' => '标签', 'metadata.template' => '模板', 'metadata.freshness' => '时效配置', 'metadata.sourcecoverage' => '来源范围'])->validate();
        $metadata = array_filter($data['metadata'] ?? [], fn ($value) => $value !== null && $value !== '');
        $payload = ['title' => trim($data['title'])];
        if ($payload['title'] === '') {
            throw ValidationException::withMessages(['title' => '标题为空']);
        }
        $filters = [];
        if (isset($metadata['category'])) {
            $matches = ctype_digit($metadata['category']) ? Category::query()->whereKey((int) $metadata['category'])->get() : Category::query()->where('name', $metadata['category'])->get();
            $category = $matches->count() === 1 ? $matches->first() : null;
            $id = $category instanceof Category ? $category->id : $category;
            if (! $id) {
                throw ValidationException::withMessages(['metadata.category' => '分类不存在，请填写现有分类名称或 ID。']);
            }
            $filters['category_ids'] = [(int) $id];
        }
        if (isset($metadata['tags'])) {
            $payload['tags'] = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,，;；\r\n]+/u', $metadata['tags'])))));
            Validator::make($payload, ['tags' => ['array', 'max:30'], 'tags.*' => ['string', 'max:100']])->validate();
        }
        if (isset($metadata['template'])) {
            $payload['template_key'] = $metadata['template'];
        }
        if (isset($metadata['freshness'])) {
            $parts = explode(';', $metadata['freshness']);
            $first = array_shift($parts);
            $freshness = ['mode' => $first];
            if (preg_match('/^annual:(\d{4})$/', $first, $matches)) {
                $freshness = ['mode' => 'annual', 'year' => (int) $matches[1]];
            } elseif (preg_match('/^monthly:(\d{4})-(\d{2})$/', $first, $matches)) {
                $freshness = ['mode' => 'monthly', 'year' => (int) $matches[1], 'month' => (int) $matches[2]];
            }
            foreach ($parts as $part) {
                $pair = explode('=', trim($part), 2);
                if (count($pair) !== 2 || ! in_array($pair[0], ['coverage_note', 'valid_until', 'effective_from', 'effective_to', 'timezone', 'version', 'last_verified_at', 'next_review_at', 'public_updates'], true)) {
                    throw ValidationException::withMessages(['metadata.freshness' => '时效格式无法读取，请使用说明中的模式和字段。']);
                }
                $freshness[$pair[0]] = $pair[1];
            }
            $payload['freshness'] = app(TopicFreshnessService::class)->normalize($freshness);
            app(TopicFreshnessService::class)->assertConfiguration($payload['freshness']);
        }
        $basic = [];
        foreach (['audience' => '目标读者', 'category' => '来源分类', 'sourcecoverage' => '来源范围'] as $key => $label) {
            if (isset($metadata[$key])) {
                $basic[] = ['label' => $label, 'value' => $metadata[$key]];
            }
        }
        if ($basic !== []) {
            $payload['basic_info'] = $basic;
        }

        return ['title' => $payload['title'], 'metadata' => $metadata, 'payload' => $payload, 'filters' => $filters];
    }
}
