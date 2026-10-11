<?php

namespace App\Services\LinkPool;

use App\Exceptions\DistributionChannelDeletionBlocked;
use App\Services\GeoFlow\DistributionChannelOperationLeaseService;
use App\Services\Site\LinkPoolSiteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LinkPoolImportService
{
    public function __construct(
        private readonly LinkPoolSiteService $sites,
        private readonly DistributionChannelOperationLeaseService $leases,
        private readonly LinkPoolTargetService $targets,
        private readonly LinkPoolUrlHealthService $health,
    ) {}

    /** @return list<array<string,mixed>> */
    public function preflight(string $text, ?string $format = null): array
    {
        if (strlen($text) > 1024 * 1024 || ! mb_check_encoding($text, 'UTF-8')) {
            throw ValidationException::withMessages(['links' => '仅支持 UTF-8 文本，文件最大 1 MB。']);
        }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $text));
        rewind($stream);
        $delimiter = $format === 'tsv' ? "\t" : ',';
        if ($format === null) {
            $candidates = [];
            foreach ([',', "\t"] as $candidate) {
                rewind($stream);
                while (($first = fgetcsv($stream, 0, $candidate)) !== false) {
                    if (count($first) === 1 && trim((string) $first[0]) === '') {
                        continue;
                    }
                    $firstCell = trim((string) ($first[0] ?? ''));
                    if (count($first) >= 2 && (strtolower($firstCell) === 'url'
                        || preg_match('/^https?:\/\//i', $firstCell) && filter_var($firstCell, FILTER_VALIDATE_URL) !== false)) {
                        $candidates[] = $candidate;
                    }
                    break;
                }
            }
            if (count($candidates) > 1) {
                fclose($stream);
                throw ValidationException::withMessages(['delimiter' => '这段内容同时符合 CSV 和表格格式，请选择下方的格式重新预检，原内容已保留。']);
            }
            $delimiter = $candidates[0] ?? ',';
        }
        rewind($stream);
        $rows = [];
        $seen = [];
        $line = 0;
        $firstRecord = true;
        try {
            while (($columns = fgetcsv($stream, 0, $delimiter)) !== false) {
                $line++;
                if (count($columns) === 1 && trim((string) $columns[0]) === '') {
                    continue;
                }
                $header = $firstRecord && strtolower(trim((string) ($columns[0] ?? ''))) === 'url';
                $firstRecord = false;
                if ($header) {
                    continue;
                }
                if (count($rows) >= 1000) {
                    throw ValidationException::withMessages(['links' => '一次最多导入 1000 行。']);
                }
                [$url, $title, $topic, $priority, $expiresAt] = array_pad($columns, 5, '');
                $url = trim((string) $url);
                $title = trim((string) $title);
                $topic = trim((string) $topic);
                $row = compact('line', 'url', 'title', 'topic');
                $row['priority'] = $priority === '' ? 1 : (int) $priority;
                $row['expires_at'] = trim((string) $expiresAt) ?: null;
                $row['status'] = 'error';
                $row['reason'] = '';
                if (count($columns) > 5 || $url === '' || $title === '' || mb_strlen($title) > 120 || mb_strlen($topic) > 120) {
                    $row['reason'] = '需要 URL、120 字以内的标题；主题最多 120 字。';
                } elseif (! ctype_digit((string) ($priority === '' ? '1' : $priority)) || $row['priority'] < 1 || $row['priority'] > 10) {
                    $row['reason'] = '优先级须为 1–10。';
                } elseif ($row['expires_at'] !== null && (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $row['expires_at'], $dateParts)
                    || ! checkdate((int) ($dateParts[2] ?? 0), (int) ($dateParts[3] ?? 0), (int) ($dateParts[1] ?? 0)))) {
                    $row['reason'] = '到期日格式为 YYYY-MM-DD。';
                } elseif ($row['expires_at'] !== null && $row['expires_at'] < now()->toDateString()) {
                    $row['reason'] = '到期日已过。';
                } else {
                    $resolved = $this->targets->resolve($url);
                    if ($resolved === null) {
                        $row['reason'] = $this->targets->normalizeUrl($url) !== null
                            ? '该地址对应的 GEOFlow 文章当前未公开，或其站点已停用。请先检查文章和站点状态。'
                            : '请输入完整的 HTTP 或 HTTPS 网页地址，不支持账号密码、脚本地址或无效域名。';
                    } else {
                        $external = $resolved['target_type'] === 'external';
                        $row['target_type'] = $resolved['target_type'];
                        $row['url_sha256'] = $resolved['url_sha256'];
                        $row['site_key'] = $resolved['site_key'];
                        $row['article_id'] = $resolved['article']?->id;
                        $row['canonical_url'] = $external ? $resolved['url'] : $this->sites->url($resolved['site_key'], $resolved['article']);
                        $row['article_title'] = $external ? null : (string) $resolved['article']->title;
                        $row['title_differs'] = ! $external && mb_strtolower($title) !== mb_strtolower($row['article_title']);
                        $key = $external ? 'external:'.$row['url_sha256'] : $row['site_key'].':'.$row['article_id'];
                        $existing = DB::table('link_pool_targets')->where('target_site_key', $row['site_key'])
                            ->when($external, fn ($query) => $query->where('url_sha256', $row['url_sha256']),
                                fn ($query) => $query->where('article_id', $row['article_id']))->first();
                        if (isset($seen[$key])) {
                            $row['status'] = 'duplicate';
                            $row['reason'] = '本批中重复，保留前一行。';
                        } else {
                            $seen[$key] = true;
                            $row['status'] = $existing ? 'update' : 'new';
                            $row['reason'] = $external ? '外部链接，可直接导入；保存后自动检查可访问性。'
                                : ($row['title_differs'] ? '导入标题与文章标题不同，请确认锚文本准确。' : '');
                        }
                    }
                }
                $rows[] = $row;
            }
        } finally {
            fclose($stream);
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $rows @param list<int> $articleTitleLines @return array<string,int|string> */
    public function import(array $rows, int $adminId, bool $ackTitles, array $articleTitleLines = []): array
    {
        if (! $ackTitles && collect($rows)->contains(fn (array $row): bool => in_array($row['status'], ['new', 'update'], true)
            && $row['title_differs'] && ! in_array((int) $row['line'], $articleTitleLines, true))) {
            throw ValidationException::withMessages(['ack_titles' => '请核对并确认与文章标题不同的展示标题。']);
        }
        $counts = ['new' => 0, 'update' => 0, 'duplicate' => 0, 'error' => 0];
        $createdIds = [];
        foreach ($rows as $row) {
            $status = $row['status'];
            $counts[$status]++;
            if (! in_array($status, ['new', 'update'], true)) {
                continue;
            }
            // Recheck site and article at the moment of writing.
            $resolved = $this->targets->resolve((string) $row['url']);
            if ($resolved === null || $resolved['site_key'] !== $row['site_key']
                || $resolved['target_type'] !== $row['target_type'] || $resolved['url_sha256'] !== $row['url_sha256']
                || $resolved['article']?->id !== $row['article_id']) {
                $counts[$status]--;
                $counts['error']++;

                continue;
            }
            $save = function () use ($row, $adminId, $articleTitleLines): array {
                $external = $row['target_type'] === 'external';
                $values = [
                    'target_type' => $row['target_type'], 'url_sha256' => $row['url_sha256'],
                    'input_url' => $external ? $row['canonical_url'] : $row['url'], 'input_title' => $row['title'],
                    'display_title' => ! $external && in_array((int) $row['line'], $articleTitleLines, true) ? $row['article_title'] : $row['title'],
                    'topic' => $row['topic'], 'priority' => $row['priority'], 'status' => 'active',
                    'expires_at' => $row['expires_at'] === null ? null : $row['expires_at'].' 23:59:59',
                    'updated_at' => now(),
                ];
                $identity = DB::table('link_pool_targets')->where('target_site_key', $row['site_key'])
                    ->when($external, fn ($query) => $query->where('url_sha256', $row['url_sha256']),
                        fn ($query) => $query->where('article_id', $row['article_id']));
                $inserted = DB::table('link_pool_targets')->insertOrIgnore([
                    'target_site_key' => $row['site_key'], 'article_id' => $row['article_id'],
                    ...$values, 'created_by' => $adminId, 'created_at' => now(),
                ]);
                $existing = $inserted !== 1 ? (clone $identity)->lockForUpdate()->first() : null;
                $changed = $inserted === 1 || $existing && LinkPoolTargetService::operationalValuesChanged($existing, $values);
                if ($inserted !== 1 && $changed) {
                    (clone $identity)->update($values);
                }
                if ($changed) {
                    $this->targets->advanceLibraryRevision();
                }

                return [
                    'inserted' => $inserted === 1,
                    'id' => (int) $identity->value('id'),
                ];
            };
            if (in_array($row['site_key'], ['primary', 'external'], true)) {
                $result = DB::transaction($save, 3);
            } else {
                $profile = $this->sites->profile((string) $row['site_key']);
                if ($profile?->channel === null) {
                    $counts[$status]--;
                    $counts['error']++;

                    continue;
                }
                try {
                    $result = $this->leases->run($profile->channel, 'link_pool_import',
                        fn (): array => DB::transaction($save, 3));
                } catch (DistributionChannelDeletionBlocked) {
                    $counts[$status]--;
                    $counts['error']++;

                    continue;
                }
            }
            if ($result['id'] === 0) {
                $counts[$status]--;
                $counts['error']++;

                continue;
            }
            $actualStatus = $result['inserted'] ? 'new' : 'update';
            if ($actualStatus !== $status) {
                $counts[$status]--;
                $counts[$actualStatus]++;
            }
            if ($result['inserted']) {
                $createdIds[] = $result['id'];
            }
            if ($row['target_type'] === 'external') {
                $this->health->enqueue($result['id']);
            }
        }

        $batchId = (string) Str::uuid();
        DB::table('link_pool_import_batches')->insert([
            'id' => $batchId, 'created_by' => $adminId,
            'created_target_ids_json' => json_encode($createdIds),
            'counts_json' => json_encode($counts), 'created_at' => now(),
        ]);

        return [...$counts, 'batch_id' => $batchId];
    }

    /** @return array{removed:int,retained:int} */
    public function undo(string $batchId, int $adminId): array
    {
        $batch = DB::table('link_pool_import_batches')->where('id', $batchId)->first();
        if ($batch === null || (int) $batch->created_by !== $adminId || $batch->undone_at !== null) {
            throw ValidationException::withMessages(['batch' => '导入批次不存在、已撤销，或不属于当前管理员。']);
        }
        $ids = array_map('intval', json_decode((string) $batch->created_target_ids_json, true) ?: []);
        $siteKeys = DB::table('link_pool_targets')->whereIn('id', $ids)->pluck('target_site_key')
            ->unique()->filter(fn ($key): bool => str_starts_with($key, 'hosted:'))->sort()->values()->all();

        return $this->withSiteLeases($siteKeys, function () use ($batchId, $adminId): array {
            return DB::transaction(function () use ($batchId, $adminId): array {
                $batch = DB::table('link_pool_import_batches')->where('id', $batchId)->lockForUpdate()->first();
                if ($batch === null || (int) $batch->created_by !== $adminId || $batch->undone_at !== null) {
                    throw ValidationException::withMessages(['batch' => '导入批次已经变化，请重新打开链接库。']);
                }
                $ids = array_map('intval', json_decode((string) $batch->created_target_ids_json, true) ?: []);
                $ids = DB::table('link_pool_targets')->whereIn('id', $ids)->orderBy('id')
                    ->lockForUpdate()->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $referenced = DB::table('link_campaign_targets as relations')
                    ->join('link_campaigns as campaigns', 'campaigns.id', '=', 'relations.campaign_id')
                    ->whereIn('relations.target_id', $ids)->where('campaigns.status', 'active')
                    ->pluck('relations.target_id')->map(fn ($id): int => (int) $id)->all();
                $placed = DB::table('link_pool_targets as targets')
                    ->join('link_placements as placements', function ($join): void {
                        $join->on('placements.target_id', '=', 'targets.id')
                            ->orOn(function ($legacy): void {
                                $legacy->whereNull('placements.target_id')->on('placements.target_site_key', '=', 'targets.target_site_key')
                                    ->on('placements.target_article_id', '=', 'targets.article_id');
                            });
                    })
                    ->join('link_selection_heads as heads', 'heads.batch_id', '=', 'placements.batch_id')
                    ->join('link_campaigns as campaigns', 'campaigns.id', '=', 'placements.campaign_id')
                    ->where('campaigns.status', 'active')
                    ->whereIn('targets.id', $ids)->pluck('targets.id')->map(fn ($id): int => (int) $id)->all();
                $protected = array_unique([...$referenced, ...$placed]);
                $removable = array_diff($ids, $protected);
                $removed = DB::table('link_pool_targets')->whereIn('id', $removable)->delete();
                if ($removed > 0) {
                    $this->targets->advanceLibraryRevision();
                }
                DB::table('link_pool_import_batches')->where('id', $batchId)->update(['undone_at' => now()]);

                return ['removed' => $removed, 'retained' => count($ids) - $removed];
            }, 3);
        });
    }

    /** @param list<string> $siteKeys */
    private function withSiteLeases(array $siteKeys, \Closure $callback): mixed
    {
        if ($siteKeys === []) {
            return $callback();
        }
        $siteKey = array_shift($siteKeys);
        $profile = $this->sites->profile($siteKey);
        if ($profile?->channel === null) {
            throw ValidationException::withMessages(['batch' => '导入目标站点已经删除，无法撤销此批次。']);
        }
        try {
            return $this->leases->run($profile->channel, 'link_pool_import_undo',
                fn () => $this->withSiteLeases($siteKeys, $callback));
        } catch (DistributionChannelDeletionBlocked) {
            throw ValidationException::withMessages(['batch' => '托管渠道正在删除，无法撤销此批次。']);
        }
    }
}
