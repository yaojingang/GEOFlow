<?php

namespace App\Services\Geo;

use App\Models\GeoArticleDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class GeoArticlePublishPackageExporter
{
    public const VERSION = 'wxmp_publish_package_v1';

    public function export(GeoArticleDraft $draft): GeoArticleDraft
    {
        $draft->loadMissing(['writingTask']);
        $writingTask = $draft->writingTask;
        if (! $writingTask) {
            throw new InvalidArgumentException('草稿缺少写作任务，无法导出发布包');
        }

        $markdown = trim((string) $draft->content_markdown);
        if ($markdown === '') {
            throw new InvalidArgumentException('草稿正文为空，无法导出发布包');
        }

        $brief = (array) $writingTask->brief;
        $package = $this->package($draft, $brief, $markdown);

        return DB::transaction(function () use ($draft, $writingTask, $brief, $package): GeoArticleDraft {
            $writingTask->forceFill([
                'brief' => array_merge($brief, [
                    'publish_package_exported_at' => now()->toDateTimeString(),
                    'publish_package' => $package,
                ]),
            ])->save();

            return $draft->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return array<string, mixed>
     */
    private function package(GeoArticleDraft $draft, array $brief, string $markdown): array
    {
        $root = 'geo_publish_packages/draft-'.$draft->id;
        $imageDir = $root.'/images';
        $notesDir = $root.'/notes';
        $markdownPath = $root.'/article.md';
        $manifestPath = $root.'/manifest.json';
        $images = [];
        $missingImages = [];
        $imageIndex = 0;

        Storage::disk('local')->deleteDirectory($root);
        Storage::disk('local')->makeDirectory($imageDir);
        Storage::disk('local')->makeDirectory($notesDir);

        $body = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)(?:\s+(".*?"|\'.*?\'))?\)/u',
            function (array $matches) use (&$imageIndex, &$images, &$missingImages, $imageDir): string {
                $alt = trim((string) ($matches[1] ?? ''));
                $url = trim((string) ($matches[2] ?? ''));
                $title = trim((string) ($matches[3] ?? ''));
                $contents = $this->readPublicImage($url);

                if ($contents === null) {
                    $missingImages[] = $url;

                    return (string) $matches[0];
                }

                $imageIndex++;
                $filename = $this->packageImageName($url, $imageIndex);
                $packagePath = $imageDir.'/'.$filename;
                Storage::disk('local')->put($packagePath, $contents);
                $images[] = [
                    'alt' => $alt,
                    'source_url' => $url,
                    'package_path' => $packagePath,
                    'markdown_url' => 'images/'.$filename,
                ];

                return '!['.$alt.'](images/'.$filename.($title !== '' ? ' '.$title : '').')';
            },
            $markdown
        ) ?? $markdown;

        $exportedMarkdown = implode("\n", [
            '标题：'.trim((string) $draft->title),
            '摘要：'.trim((string) ($draft->summary ?: $draft->seo_description)),
            '封面建议：'.$this->coverSuggestion($brief),
            '---',
            '',
            $body,
        ]);
        $supportingFiles = $this->writeSupportingFiles($brief, $notesDir);

        $manifest = [
            'version' => self::VERSION,
            'draft_id' => (int) $draft->id,
            'title' => (string) $draft->title,
            'summary' => (string) ($draft->summary ?: $draft->seo_description),
            'markdown_path' => $markdownPath,
            'image_dir' => $imageDir,
            'images' => $images,
            'missing_images' => array_values(array_unique($missingImages)),
            'supporting_files' => $supportingFiles,
            'target_channels' => ['微信公众号草稿', '站内文章', '蚁小二发布交接'],
            'generated_at' => now()->toDateTimeString(),
        ];

        Storage::disk('local')->put($markdownPath, $exportedMarkdown);
        Storage::disk('local')->put($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');

        return [
            'version' => self::VERSION,
            'markdown_path' => $markdownPath,
            'image_dir' => $imageDir,
            'manifest_path' => $manifestPath,
            'image_count' => count($images),
            'missing_images' => $manifest['missing_images'],
            'supporting_files' => $supportingFiles,
            'target_channels' => $manifest['target_channels'],
            'generated_at' => $manifest['generated_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return list<array{label: string, path: string, type: string}>
     */
    private function writeSupportingFiles(array $brief, string $notesDir): array
    {
        $researchNotes = (array) ($brief['research_notes'] ?? []);
        if ($researchNotes === []) {
            return [];
        }

        $files = [
            [
                'label' => '生成结论与流程说明',
                'path' => $notesDir.'/research-summary.md',
                'type' => 'research_summary',
                'content' => $this->researchSummaryMarkdown($brief, $researchNotes),
            ],
            [
                'label' => '多平台回答交叉对比',
                'path' => $notesDir.'/platform-comparison.md',
                'type' => 'platform_comparison',
                'content' => $this->platformComparisonMarkdown($researchNotes),
            ],
            [
                'label' => '参考文章筛选说明',
                'path' => $notesDir.'/selected-references.md',
                'type' => 'selected_references',
                'content' => $this->selectedReferencesMarkdown($brief, $researchNotes),
            ],
        ];

        foreach ($files as $file) {
            Storage::disk('local')->put((string) $file['path'], rtrim((string) $file['content'])."\n");
        }

        return collect($files)
            ->map(static fn (array $file): array => [
                'label' => (string) $file['label'],
                'path' => (string) $file['path'],
                'type' => (string) $file['type'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>  $researchNotes
     */
    private function researchSummaryMarkdown(array $brief, array $researchNotes): string
    {
        $stages = collect((array) ($brief['pipeline_stages'] ?? []))
            ->map(static function (mixed $stage, string $key): string {
                $stage = (array) $stage;

                return '- '.$key.'：'.((string) ($stage['status'] ?? 'unknown')).'，完成时间 '.((string) ($stage['completed_at'] ?? ''));
            })
            ->implode("\n");

        return implode("\n\n", [
            '# 生成结论与流程说明',
            '## 选题',
            (string) ($researchNotes['topic'] ?? $brief['topic'] ?? ''),
            '## 搜索问题',
            (string) ($researchNotes['question'] ?? $brief['question'] ?? ''),
            '## 结论',
            (string) ($researchNotes['conclusion'] ?? '正文只保留可发布内容，调研过程单独留档。'),
            '## 产出规则',
            (string) ($brief['article_output_rule'] ?? '正文只保留可直接发布的公众号文章；调研结论和证据进入说明文件。'),
            '## 本地案例怎么补',
            $this->bulletMarkdown((array) ($researchNotes['local_case_materials'] ?? [])),
            '## 发布前检查',
            $this->bulletMarkdown((array) ($researchNotes['publication_checklist'] ?? [])),
            '## 流程状态',
            $stages !== '' ? $stages : '- 暂无流程状态',
        ]);
    }

    /**
     * @param  array<string, mixed>  $researchNotes
     */
    private function platformComparisonMarkdown(array $researchNotes): string
    {
        $comparison = (array) ($researchNotes['platform_comparison'] ?? []);
        $answers = collect((array) ($comparison['answers'] ?? []))
            ->map(function (mixed $answer): string {
                $answer = (array) $answer;
                $links = collect((array) ($answer['source_urls'] ?? []))
                    ->map(fn (mixed $url): string => (string) $url)
                    ->filter()
                    ->implode('<br>');

                return '| '.$this->tableCell((string) ($answer['platform_code'] ?? '')).' | '
                    .$this->tableCell((string) ($answer['visibility_score'] ?? 0)).' | '
                    .$this->tableCell(((bool) ($answer['brand_mentioned'] ?? false)) ? '是' : '否').' | '
                    .$this->tableCell($links !== '' ? $links : '无').' | '
                    .$this->tableCell((string) ($answer['summary'] ?? '')).' |';
            })
            ->implode("\n");
        $answerTable = implode("\n", [
            '| 平台 | 可见度 | 提及品牌 | 引用链接 | 回答摘要 |',
            '| --- | --- | --- | --- | --- |',
            $answers !== '' ? $answers : '| 暂无 | 0 | 否 | 无 | 暂无 |',
        ]);

        return implode("\n\n", [
            '# 多平台回答交叉对比',
            '## 总览',
            (string) ($comparison['summary'] ?? ''),
            '## 共同信号',
            $this->bulletMarkdown((array) ($comparison['shared_signals'] ?? [])),
            '## 平台差异',
            $this->bulletMarkdown((array) ($comparison['differences'] ?? [])),
            '## 原始回答摘要',
            $answerTable,
        ]);
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>  $researchNotes
     */
    private function selectedReferencesMarkdown(array $brief, array $researchNotes): string
    {
        $selection = (array) ($researchNotes['reference_selection'] ?? []);
        $references = collect((array) ($selection['items'] ?? $brief['references'] ?? []))
            ->map(function (mixed $reference): string {
                $reference = (array) $reference;

                return '| '.$this->tableCell((string) ($reference['title'] ?? '')).' | '
                    .$this->tableCell((string) ($reference['domain'] ?? '')).' | '
                    .$this->tableCell((string) ($reference['score'] ?? 0)).' | '
                    .$this->tableCell((string) ($reference['suggested_usage'] ?? '')).' | '
                    .$this->tableCell((string) ($reference['url'] ?? '')).' |';
            })
            ->implode("\n");
        $referenceTable = implode("\n", [
            '| 标题 | 域名 | 评分 | 建议用途 | 链接 |',
            '| --- | --- | --- | --- | --- |',
            $references !== '' ? $references : '| 暂无 | 暂无 | 0 | 暂无 | 暂无 |',
        ]);

        return implode("\n\n", [
            '# 参考文章筛选说明',
            '## 筛选列表',
            $referenceTable,
            '## 筛选理由',
            $this->bulletMarkdown((array) ($selection['citation_reasons'] ?? $brief['citation_reasons'] ?? [])),
            '## 可复用写法',
            $this->bulletMarkdown((array) ($selection['writing_patterns'] ?? $brief['writing_patterns'] ?? [])),
            '## 分析档案',
            $this->bulletMarkdown((array) ($selection['analysis_paths'] ?? [])),
        ]);
    }

    /**
     * @param  list<mixed>  $items
     */
    private function bulletMarkdown(array $items): string
    {
        $lines = collect($items)
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->map(static fn (string $item): string => '- '.$item)
            ->implode("\n");

        return $lines !== '' ? $lines : '- 暂无';
    }

    private function tableCell(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], trim($value));
    }

    private function readPublicImage(string $url): ?string
    {
        $urlPath = parse_url($url, PHP_URL_PATH);
        $path = ltrim(is_string($urlPath) && $urlPath !== '' ? $urlPath : $url, '/');
        $appBasePath = trim((string) (parse_url((string) config('app.url'), PHP_URL_PATH) ?: ''), '/');
        if ($appBasePath !== '' && str_starts_with($path, $appBasePath.'/')) {
            $path = substr($path, strlen($appBasePath) + 1);
        }

        if (str_starts_with($path, 'public/storage/')) {
            $path = substr($path, strlen('public/storage/'));
        } elseif (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (! str_starts_with($path, 'uploads/')) {
            return null;
        }

        return Storage::disk('public')->exists($path)
            ? Storage::disk('public')->get($path)
            : null;
    }

    private function packageImageName(string $url, int $index): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $url;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'png';
        $name = Str::slug(pathinfo($path, PATHINFO_FILENAME)) ?: 'image';

        return sprintf('%02d-%s.%s', $index, $name, $extension);
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    private function coverSuggestion(array $brief): string
    {
        $visualPackage = (array) ($brief['visual_publish_package'] ?? []);
        $items = (array) ($visualPackage['items'] ?? []);
        foreach ($items as $item) {
            $item = (array) $item;
            if (($item['type'] ?? '') === 'cover_image') {
                $prompt = trim((string) ($item['prompt'] ?? ''));
                if ($prompt !== '') {
                    return $prompt;
                }
            }
        }

        return '使用文章首图作为封面，发布前检查标题、摘要和图片显示。';
    }
}
