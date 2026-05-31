<?php

namespace App\Services\Geo;

use App\Models\GeoArticleDraft;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GeoArticleVisualImageInserter
{
    public function insert(GeoArticleDraft $draft): GeoArticleDraft
    {
        $draft->loadMissing(['writingTask']);
        $writingTask = $draft->writingTask;
        if (! $writingTask) {
            throw new InvalidArgumentException('草稿缺少写作任务，无法植入配图');
        }

        $brief = (array) $writingTask->brief;
        $package = (array) ($brief['visual_publish_package'] ?? []);
        $items = (array) ($package['items'] ?? []);
        if ($items === []) {
            throw new InvalidArgumentException('请先生成配图与发布包，再植入正文');
        }

        $markdown = trim((string) $draft->content_markdown);
        if ($markdown === '') {
            throw new InvalidArgumentException('草稿正文为空，无法植入配图');
        }

        [$markdown, $items] = $this->insertItems($markdown, $items, (int) $draft->id);
        $package['items'] = $items;

        return DB::transaction(function () use ($draft, $writingTask, $brief, $package, $markdown): GeoArticleDraft {
            $draft->forceFill([
                'content_markdown' => $markdown,
                'content_html' => ArticleHtmlPresenter::markdownToHtml($markdown),
                'status' => $draft->status === 'converted' ? 'converted' : 'draft',
            ])->save();

            $writingTask->forceFill([
                'brief' => array_merge($brief, [
                    'visual_images_inserted_at' => now()->toDateTimeString(),
                    'visual_publish_package' => $package,
                ]),
            ])->save();

            return $draft->refresh();
        });
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array{0:string,1:array<int, mixed>}
     */
    private function insertItems(string $markdown, array $items, int $draftId): array
    {
        $orderedTypes = ['cover_image', 'process_diagram', 'checklist_infographic', 'real_case_material'];
        $ordered = collect($items)->sortBy(function (mixed $item) use ($orderedTypes): int {
            $item = (array) $item;
            $type = (string) ($item['type'] ?? '');
            $position = array_search($type, $orderedTypes, true);

            return $position === false ? 99 : (int) $position;
        });

        $updated = $items;
        foreach ($ordered as $originalIndex => $item) {
            $item = (array) $item;
            $type = (string) ($item['type'] ?? '');
            $title = trim((string) ($item['title'] ?? '配图')) ?: '配图';
            $imageUrl = $this->imageUrl($item, $draftId);

            if ($imageUrl === '') {
                $item['markdown_inserted'] = false;
                $item['skip_reason'] = '等待真实素材，不自动插入';
                $updated[$originalIndex] = $item;

                continue;
            }

            $block = '!['.$this->alt($title).']('.$imageUrl.')';
            $item['image_url'] = $imageUrl;

            if (str_contains($markdown, $block) || preg_match('/!\['.preg_quote($this->alt($title), '/').'\]\([^)]+\)/u', $markdown) === 1) {
                $item['markdown_inserted'] = true;
                $updated[$originalIndex] = $item;

                continue;
            }

            $markdown = match ($type) {
                'cover_image' => $this->insertAfterLeadingTitle($markdown, $block),
                'checklist_infographic' => $this->insertAfterHeading($markdown, '核验清单', $block),
                'process_diagram' => $this->insertBeforeHeading($markdown, '核验清单', $block),
                'real_case_material' => $this->insertAfterHeading($markdown, '本地案例', $block),
                default => $this->appendBlock($markdown, $block),
            };

            $item['markdown_inserted'] = true;
            $updated[$originalIndex] = $item;
        }

        return [$markdown, array_values($updated)];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function imageUrl(array $item, int $draftId): string
    {
        foreach (['image_url', 'asset_url', 'file_path', 'planned_url'] as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        if (($item['source_mode'] ?? '') === 'real_material_required') {
            return '';
        }

        return match ((string) ($item['type'] ?? '')) {
            'cover_image' => 'uploads/geo/visual-pack/draft-'.$draftId.'/cover-image.png',
            'checklist_infographic' => 'uploads/geo/visual-pack/draft-'.$draftId.'/checklist-infographic.png',
            'process_diagram' => 'uploads/geo/visual-pack/draft-'.$draftId.'/process-diagram.png',
            default => '',
        };
    }

    private function alt(string $title): string
    {
        return str_replace(['[', ']'], '', $title);
    }

    private function insertAfterLeadingTitle(string $markdown, string $block): string
    {
        if (preg_match('/\A(#[^\n]*\n+)/u', $markdown, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $block."\n\n".$markdown;
        }

        $insertAt = (int) $matches[1][1] + strlen((string) $matches[1][0]);

        return substr($markdown, 0, $insertAt)."\n".$block."\n\n".ltrim(substr($markdown, $insertAt));
    }

    private function insertBeforeHeading(string $markdown, string $needle, string $block): string
    {
        $pattern = '/^##\s+[^\n]*'.preg_quote($needle, '/').'[^\n]*$/mu';
        if (preg_match($pattern, $markdown, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $this->appendBlock($markdown, $block);
        }

        $insertAt = (int) $matches[0][1];

        return rtrim(substr($markdown, 0, $insertAt))."\n\n".$block."\n\n".ltrim(substr($markdown, $insertAt));
    }

    private function insertAfterHeading(string $markdown, string $needle, string $block): string
    {
        $pattern = '/^##\s+[^\n]*'.preg_quote($needle, '/').'[^\n]*\n+/mu';
        if (preg_match($pattern, $markdown, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $this->appendBlock($markdown, $block);
        }

        $insertAt = (int) $matches[0][1] + strlen((string) $matches[0][0]);

        return substr($markdown, 0, $insertAt)."\n".$block."\n\n".ltrim(substr($markdown, $insertAt));
    }

    private function appendBlock(string $markdown, string $block): string
    {
        return rtrim($markdown)."\n\n".$block;
    }
}
