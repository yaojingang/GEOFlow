<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ReconcileDistributionOnlyArticlesCommand extends Command
{
    protected $signature = 'geoflow:reconcile-distribution-only-articles
        {--apply : Persist the repair; the default is a dry run}';

    protected $description = 'Keep articles from distribution-only tasks private on the primary site';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $matched = 0;
        $reconciled = 0;

        $this->invalidArticlesQuery()
            ->select(['articles.id'])
            ->chunkById(200, function (Collection $articles) use ($apply, &$matched, &$reconciled): void {
                $matched += $articles->count();
                if (! $apply) {
                    return;
                }

                $reconciled += $this->invalidArticlesQuery()
                    ->whereKey($articles->modelKeys())
                    ->update([
                        'status' => 'private',
                        'published_at' => null,
                        'updated_at' => now(),
                    ]);
            }, column: 'articles.id', alias: 'id');

        if ($apply) {
            $this->info("Reconciled distribution-only published articles: {$reconciled}");
        } else {
            $this->info("Matched distribution-only published articles: {$matched} (dry run)");
        }

        return self::SUCCESS;
    }

    /** @return Builder<Article> */
    private function invalidArticlesQuery(): Builder
    {
        return Article::query()
            ->where('articles.status', 'published')
            ->whereHas('task', static fn (Builder $task): Builder => $task
                ->where('publish_scope', 'distribution_only'));
    }
}
