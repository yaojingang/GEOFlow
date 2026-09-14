<?php

namespace App\Services\GeoFlow;

use App\Models\Category;
use App\Models\CategorySlugHistory;
use App\Support\Site\ArticlePermalinkPattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CategorySlugRegistry
{
    public function normalize(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if (preg_match('/\A[a-z0-9-]{1,100}\z/', $slug) !== 1) {
            throw ValidationException::withMessages([
                'slug' => __('url_change.errors.category_slug_invalid'),
            ]);
        }

        return $slug;
    }

    public function generate(string $name, ?int $excludeId = null): string
    {
        return $this->availableCandidate($name, $excludeId, lock: true);
    }

    private function availableCandidate(string $name, ?int $excludeId, bool $lock): string
    {
        if ($lock && DB::transactionLevel() > 0) {
            $this->lockSqliteWriter();
        }
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        if ($base === '') {
            $base = 'cat-'.substr(md5($name), 0, 8);
        }

        for ($attempt = 1; $attempt <= 1000; $attempt++) {
            $suffix = $attempt === 1 ? '' : '-'.$attempt;
            $slug = substr($base, 0, 100 - strlen($suffix)).$suffix;
            if (ArticlePermalinkPattern::isReservedFirstSegment($slug)) {
                continue;
            }

            if (! $this->isAvailable($slug, $excludeId)) {
                continue;
            }
            if ($lock && DB::transactionLevel() > 0 && DB::getDriverName() === 'pgsql') {
                // Generated candidates can skip a busy name without waiting on a different lock order.
                $result = DB::selectOne('select pg_try_advisory_xact_lock(hashtext(?)) as acquired', ['category-slug:'.$slug]);
                if (! in_array($result?->acquired, [true, 1, 't'], true)) {
                    continue;
                }
                if (! $this->isAvailable($slug, $excludeId)) {
                    continue;
                }
            }

            return $slug;
        }

        throw ValidationException::withMessages([
            'slug' => __('url_change.errors.category_slug_generation_exhausted'),
        ]);
    }

    /** Call within the same transaction that creates the category. */
    public function assertAvailable(string $slug, ?int $ownerId = null): void
    {
        $slug = $this->normalize($slug);
        if (ArticlePermalinkPattern::isReservedFirstSegment($slug)) {
            throw ValidationException::withMessages([
                'slug' => __('article_permalink.errors.category_reserved_path', [
                    'slug' => $slug,
                    'path' => $slug,
                ]),
            ]);
        }

        if (DB::transactionLevel() > 0) {
            $this->lockSlugNamespace([$slug]);
        }
        if (! $this->isAvailable($slug, $ownerId)) {
            $this->unavailable($slug, $ownerId);
        }
    }

    public function change(Category $category, string $newSlug, ?int $adminId = null): Category
    {
        $newSlug = $this->normalize($newSlug);

        try {
            return DB::transaction(function () use ($category, $newSlug, $adminId): Category {
                $this->lockSqliteWriter();
                $lockedCategory = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
                $oldSlug = (string) $lockedCategory->slug;
                if (hash_equals($oldSlug, $newSlug)) {
                    return $lockedCategory;
                }

                $this->lockSlugNamespace([$oldSlug, $newSlug]);
                $this->assertAvailable($newSlug, (int) $lockedCategory->id);
                $this->remember($lockedCategory, $adminId);
                $lockedCategory->forceFill(['slug' => $newSlug])->save();

                return $lockedCategory;
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isSlugUniqueViolation($exception)) {
                $this->unavailable($newSlug, (int) $category->id);
            }

            throw $exception;
        }
    }

    /** The caller deletes the category in the same outer transaction. */
    public function rememberDeleted(Category $category): void
    {
        DB::transaction(function () use ($category): void {
            $this->lockSqliteWriter();
            $lockedCategory = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            $this->lockSlugNamespace([(string) $lockedCategory->slug]);
            $this->remember($lockedCategory);
        }, 3);
    }

    private function remember(Category $category, ?int $adminId = null): void
    {
        $slug = (string) $category->slug;
        if (! $this->isAvailable($slug, (int) $category->id)) {
            $this->unavailable($slug, (int) $category->id);
        }

        $history = CategorySlugHistory::query()->firstOrNew(['slug' => $slug]);
        $history->category_id = (int) $category->id;
        $history->original_category_id = (int) $category->id;
        $history->admin_id = $adminId;
        $history->last_used_at = now();
        $history->save();
    }

    private function isAvailable(string $slug, ?int $ownerId): bool
    {
        $current = Category::query()->where('slug', $slug);
        if ($ownerId !== null) {
            $current->whereKeyNot($ownerId);
        }
        if ($current->exists()) {
            return false;
        }

        $history = CategorySlugHistory::query()->where('slug', $slug);
        if ($ownerId !== null) {
            $history->where(function (Builder $query) use ($ownerId): void {
                $query->whereNull('category_id')
                    ->orWhere('category_id', '!=', $ownerId)
                    ->orWhere('original_category_id', '!=', $ownerId);
            });
        }

        return ! $history->exists();
    }

    /** @param list<string> $slugs */
    public function lockSlugNamespace(array $slugs): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->lockSqliteWriter();

            return;
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        sort($slugs, SORT_STRING);
        foreach (array_unique($slugs) as $slug) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['category-slug:'.$slug]);
        }
    }

    private function lockSqliteWriter(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::update('update categories set id = id where 1 = 0');
        }
    }

    private function unavailable(string $slug, ?int $ownerId): never
    {
        throw ValidationException::withMessages([
            'slug' => __('url_change.errors.category_slug_unavailable', [
                'suggestion' => $this->availableCandidate($slug, $ownerId, lock: false),
            ]),
        ]);
    }

    private function isSlugUniqueViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'categories_slug_unique')
            || str_contains($message, 'category_slug_histories_slug_unique')
            || str_contains($message, 'categories.slug')
            || str_contains($message, 'category_slug_histories.slug');
    }
}
