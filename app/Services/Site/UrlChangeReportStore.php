<?php

namespace App\Services\Site;

use App\Exceptions\UrlChangeReportException;
use App\Models\UrlChangeRequest;
use Illuminate\Support\Facades\Storage;

final class UrlChangeReportStore
{
    public function directory(UrlChangeRequest $change): string
    {
        return 'url-changes/'.$change->id;
    }

    /** @param list<array<string,mixed>> $rows */
    public function write(UrlChangeRequest $change, int $segment, array $rows): int
    {
        $disk = Storage::disk('local');
        $directory = $this->directory($change);
        $disk->makeDirectory($directory);
        $content = implode('', array_map(fn (array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", $rows));
        $total = (int) ($change->progress['bytes'] ?? 0) + strlen($content);
        $free = disk_free_space($disk->path($directory));
        if ($total > 2 * 1024 ** 3 || $free === false || $free - strlen($content) < 1024 ** 3) {
            throw new UrlChangeReportException(__('url_change.errors.storage_full'));
        }
        $path = $directory.'/'.sprintf('%08d', $segment).'.jsonl';
        if (! $disk->put($path.'.tmp', $content) || ! $disk->move($path.'.tmp', $path)) {
            throw new UrlChangeReportException(__('url_change.errors.storage_write'));
        }

        return strlen($content);
    }

    /** @return \Generator<int,array<string,mixed>> */
    public function rows(UrlChangeRequest $change, int $afterSegment = 0, ?int $endSegment = null): \Generator
    {
        $segments = min((int) ($change->progress['segments'] ?? 0), $endSegment ?? PHP_INT_MAX);
        for ($segment = $afterSegment; $segment < $segments; $segment++) {
            $stream = Storage::disk('local')->readStream($this->directory($change).'/'.sprintf('%08d', $segment).'.jsonl');
            if (! is_resource($stream)) {
                throw new UrlChangeReportException(__('url_change.errors.report_missing'));
            }
            try {
                while (($line = fgets($stream)) !== false) {
                    yield $segment => json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                }
            } finally {
                fclose($stream);
            }
        }
    }
}
