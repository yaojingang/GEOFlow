<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\GeoFlow\ArticleAiQualityInvalidationService;
use App\Services\GeoFlow\ArticleCitationMarkerCleaner;
use App\Services\GeoFlow\ArticleRiskScanner;
use App\Services\HostedSites\HostedSiteArticleFingerprintService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class GeoFlowCleanArticleCitationMarkersCommand extends Command
{
    protected $signature = 'geoflow:clean-article-citation-markers
        {--apply : Persist the cleanup; the default is a dry run}
        {--after-id=0 : Resume after this article ID}
        {--limit=0 : Stop after scanning this many articles}';

    protected $description = 'Scan AI-generated articles and remove confirmed citation marker forms';

    public function __construct(
        private readonly ArticleCitationMarkerCleaner $cleaner,
        private readonly ArticleRiskScanner $riskScanner,
        private readonly ArticleAiQualityInvalidationService $qualityInvalidator,
        private readonly HostedSiteArticleFingerprintService $hostedFingerprint,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $afterId = max(0, (int) $this->option('after-id'));
        $limit = max(0, (int) $this->option('limit'));
        $scanned = 0;
        $matched = 0;
        $updated = 0;
        $failed = 0;

        $query = Article::query()
            ->withTrashed()
            ->where('is_ai_generated', true)
            ->where('id', '>', $afterId)
            ->orderBy('id');

        foreach ($query->lazyById(100) as $article) {
            if ($limit > 0 && $scanned >= $limit) {
                break;
            }
            $scanned++;

            $fields = $this->cleaner->cleanArticleFields([
                'content' => (string) $article->content,
                'excerpt' => (string) ($article->excerpt ?? ''),
                'meta_description' => (string) ($article->meta_description ?? ''),
            ]);
            if (! $this->fieldsChanged($article, $fields)) {
                continue;
            }

            $matched++;
            if (! $apply) {
                $this->line("[dry-run] article_id={$article->id}");

                continue;
            }

            try {
                DB::transaction(function () use ($article, $fields): void {
                    $locked = Article::query()->withTrashed()->whereKey($article->id)->lockForUpdate()->firstOrFail();
                    if ($locked->hostedSiteAssignment()->exists() || $locked->distributions()->exists()) {
                        throw new \RuntimeException('文章已有托管或远端分发记录，需要先制定同步方案');
                    }

                    $locked->forceFill($fields)->saveQuietly();
                    $this->riskScanner->record($locked, 'citation_marker_cleanup_command');
                    $this->qualityInvalidator->invalidateArticle($locked, '文章引用标注已清理', false);
                    $this->hostedFingerprint->synchronizeLockedArticle($locked);
                }, 3);
                $updated++;
                $this->info("updated article_id={$article->id}");
            } catch (Throwable $exception) {
                $failed++;
                $this->error("failed article_id={$article->id}: {$exception->getMessage()}");
            }
        }

        $mode = $apply ? 'apply' : 'dry-run';
        $this->newLine();
        $this->info("mode={$mode} scanned={$scanned} matched={$matched} updated={$updated} failed={$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param array{content:string,excerpt:string,meta_description:string} $fields */
    private function fieldsChanged(Article $article, array $fields): bool
    {
        return (string) $article->content !== $fields['content']
            || (string) ($article->excerpt ?? '') !== $fields['excerpt']
            || (string) ($article->meta_description ?? '') !== $fields['meta_description'];
    }
}
