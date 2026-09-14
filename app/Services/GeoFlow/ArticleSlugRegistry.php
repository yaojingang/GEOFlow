<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Models\ArticleSlugHistory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ArticleSlugRegistry
{
    public function generate(?int $excludeArticleId = null, int $length = 8): string
    {
        do {
            $slug = $this->randomSlug($length);
        } while (! $this->isAvailable($slug, $excludeArticleId));

        $this->assertAvailable($slug, $excludeArticleId);

        return $slug;
    }

    public function isAvailable(string $slug, ?int $excludeArticleId = null): bool
    {
        $articleQuery = Article::withTrashed()->where('slug', $slug);
        if ($excludeArticleId !== null) {
            $articleQuery->whereKeyNot($excludeArticleId);
        }
        if ($articleQuery->exists()) {
            return false;
        }

        if (! Schema::hasTable('article_slug_histories')) {
            return true;
        }

        $historyQuery = ArticleSlugHistory::query()->where('slug', $slug);
        if ($excludeArticleId !== null) {
            $historyQuery->where('article_id', '!=', $excludeArticleId);
        }

        return ! $historyQuery->exists();
    }

    public function assertAvailable(string $slug, ?int $excludeArticleId = null): void
    {
        $this->assertValid($slug);
        if (DB::transactionLevel() > 0) {
            $this->lockSlugNamespace([$slug]);
        }
        if (! $this->isAvailable($slug, $excludeArticleId)) {
            throw ValidationException::withMessages(['slug' => __('article_permalink.errors.slug_unavailable')]);
        }
    }

    public function change(Article $article, string $newSlug): Article
    {
        $newSlug = trim($newSlug);
        $this->assertValid($newSlug);

        try {
            return DB::transaction(function () use ($article, $newSlug): Article {
                if (DB::getDriverName() === 'sqlite') {
                    DB::update('update articles set id = id where id = ?', [(int) $article->id]);
                }

                $lockedArticle = Article::withTrashed()->whereKey($article->id)->lockForUpdate()->firstOrFail();
                $oldSlug = (string) $lockedArticle->slug;
                if (hash_equals($oldSlug, $newSlug)) {
                    return $lockedArticle;
                }

                $this->lockSlugNamespace([$oldSlug, $newSlug]);
                $this->assertAvailable($newSlug, (int) $lockedArticle->id);

                $restoredHistory = ArticleSlugHistory::query()
                    ->where('article_id', $lockedArticle->id)
                    ->where('slug', $newSlug)
                    ->lockForUpdate()
                    ->first();
                $restoredHistory?->delete();

                $history = ArticleSlugHistory::query()->firstOrNew(['slug' => $oldSlug]);
                $history->article_id = (int) $lockedArticle->id;
                $history->last_used_at = now();
                if (! $history->exists) {
                    $history->created_at = now();
                }
                $history->save();
                $lockedArticle->forceFill(['slug' => $newSlug])->save();

                return $lockedArticle->refresh();
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isSlugUniqueViolation($exception)) {
                throw ValidationException::withMessages([
                    'slug' => __('article_permalink.errors.slug_unavailable'),
                ]);
            }

            throw $exception;
        }
    }

    public function assertValid(string $slug): void
    {
        if ($slug === ''
            || strlen($slug) > 255
            || ! mb_check_encoding($slug, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F\\\\\/?#]/u', $slug) === 1
            || in_array($slug, ['.', '..'], true)) {
            throw ValidationException::withMessages([
                'slug' => __('article_permalink.errors.slug_invalid'),
            ]);
        }
    }

    /** @param list<string> $slugs */
    private function lockSlugNamespace(array $slugs): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        sort($slugs, SORT_STRING);
        foreach (array_unique($slugs) as $slug) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$slug]);
        }
    }

    private function randomSlug(int $length): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $slug = '';
        for ($index = 0; $index < $length; $index++) {
            $slug .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $slug;
    }

    private function isSlugUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'articles_slug_unique')
            || str_contains($message, 'article_slug_histories_slug_unique')
            || str_contains($message, 'articles.slug')
            || str_contains($message, 'article_slug_histories.slug');
    }
}
