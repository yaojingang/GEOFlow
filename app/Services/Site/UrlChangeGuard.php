<?php

namespace App\Services\Site;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Category;
use App\Support\Site\ArticlePermalinkPolicy;
use Illuminate\Support\Facades\DB;

final class UrlChangeGuard
{
    public function __construct(private readonly UrlChangeService $changes) {}

    public function category(Category $category, string $slug, ?Admin $actor = null): void
    {
        if ($slug !== (string) $category->slug) {
            $this->deny($actor);
        }
    }

    /** @return list<int> */
    public function protectedCategoryIds(Article $article): array
    {
        foreach ($this->changes->sites('article_category', (int) $article->id, false) as $site) {
            if (str_contains(ArticlePermalinkPolicy::fromRaw($site['policy'])->currentPattern, '{category}')) {
                return Category::query()->whereKeyNot($article->category_id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            }
        }

        return [];
    }

    /** @param array<string,mixed> $values */
    public function article(Article $article, array $values, ?Admin $actor = null): void
    {
        if (array_key_exists('slug', $values) && (string) $values['slug'] !== (string) $article->slug) {
            $this->deny($actor);
        }
        if (array_key_exists('created_at', $values) && (string) $values['created_at'] !== (string) $article->created_at) {
            $this->deny($actor);
        }
        if (array_key_exists('category_id', $values) && (int) $values['category_id'] !== (int) $article->category_id) {
            if (DB::transactionLevel() > 0) {
                Category::query()->whereKey([$article->category_id, (int) $values['category_id']])
                    ->orderBy('id')->lockForUpdate()->get(['id']);
                $scopes = array_column($this->changes->sites('article_category', (int) $article->id, false), 'key');
                // Match the revision trigger's category-before-site lock order for both sides of a move.
                $scopes[] = 'category:'.$article->category_id;
                $scopes[] = 'category:'.(int) $values['category_id'];
                app(UrlChangeVersions::class)->snapshot($scopes, true);
            }
            $nextCategory = Category::query()->find((int) $values['category_id']);
            foreach ($this->changes->sites('article_category', (int) $article->id, false) as $site) {
                if ($site['key'] === 'primary' && ! app(UrlChangeInspector::class)->articles($site)->whereKey($article->id)->exists()) {
                    continue;
                }
                if (str_contains(ArticlePermalinkPolicy::fromRaw($site['policy'])->currentPattern, '{category}')
                    && (string) $article->category?->slug !== (string) $nextCategory?->slug) {
                    $this->deny($actor);
                }
            }
        }
    }

    private function deny(?Admin $actor): never
    {
        if ($actor === null && request()->is('api/*')) {
            $context = request()->attributes->get('api_auth');
            $actor = $context instanceof ApiAuthContext ? Admin::query()->find($context->auditAdminId) : null;
        } else {
            $actor ??= auth('admin')->user() ?? request()->user();
        }
        $super = $actor instanceof Admin && $actor->status === 'active' && $actor->isSuperAdmin();
        if (! request()->is('api/*')) {
            abort($super ? 409 : 403, __('url_change.errors.protected'));
        }
        throw new ApiException($super ? 'url_change_confirmation_required' : 'forbidden', __('url_change.errors.protected'), $super ? 409 : 403);
    }
}
