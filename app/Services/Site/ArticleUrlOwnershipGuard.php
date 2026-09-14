<?php

namespace App\Services\Site;

use App\Models\Article;
use App\Models\ArticleSlugHistory;
use App\Support\Site\ArticlePermalinkPattern;
use App\Support\Site\ArticlePermalinkPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class ArticleUrlOwnershipGuard
{
    public function __construct(private readonly ArticlePermalinkService $urls) {}

    /** Reject reverse ownership collisions using bounded, indexed locator candidates. */
    public function assertAvailable(Article $article, array $site, Builder $scope): void
    {
        $patterns = ArticlePermalinkPolicy::fromRaw($site['policy'])->patterns();
        $deadline = microtime(true) + 2;
        foreach ($patterns as $matching) {
            $this->budget(0, $deadline);
            $matcher = ArticlePermalinkPattern::compile($matching);
            $locator = str_contains($matching, '{id}') ? 'id' : 'slug';
            $value = (string) $article->{$locator};
            $matchingSegments = explode('/', trim($matching, '/'));
            $position = array_find_key($matchingSegments, fn ($segment) => str_contains($segment, '{'.$locator.'}'));
            $locatorSegment = str_replace('{'.$locator.'}', rawurlencode($value), $matchingSegments[$position]);
            foreach ($patterns as $rendering) {
                $this->budget(0, $deadline);
                if ($rendering === $matching || $matcher->preservesLocatorOf(ArticlePermalinkPattern::compile($rendering))) {
                    continue;
                }
                $renderingSegments = explode('/', trim($rendering, '/'));
                if (count($matchingSegments) !== count($renderingSegments)) {
                    continue;
                }
                $renderer = ArticlePermalinkPattern::compile($rendering);
                $variants = [[]];
                $uncertain = false;
                $query = clone $scope;
                $query->whereKeyNot($article->id);
                foreach ($matchingSegments as $segmentIndex => $segment) {
                    $target = $segmentIndex === $position ? $locatorSegment : $segment;
                    if (! $this->constrain($query, $renderingSegments[$segmentIndex], $target, $deadline, $variants, $uncertain)) {
                        continue 2;
                    }
                }
                $candidates = $query->with('category:id,slug')->limit(501)->get(['articles.id', 'articles.slug', 'articles.category_id', 'articles.created_at']);
                $this->budget($candidates->count(), $deadline);
                if ($uncertain && $candidates->isNotEmpty()) {
                    $this->rejectUnprovenOwnership();
                }
                foreach ($candidates as $candidate) {
                    $slugs = ArticleSlugHistory::query()->where('article_id', $candidate->id)->limit(501)->pluck('slug')->prepend($candidate->slug)->unique();
                    $this->budget($slugs->count(), $deadline);
                    foreach ($variants as $variant) {
                        if (isset($variant['id']) && (int) $variant['id'] !== (int) $candidate->id) {
                            continue;
                        }
                        foreach ($slugs as $slug) {
                            $this->budget(0, $deadline);
                            if (isset($variant['slug']) && $variant['slug'] !== $slug) {
                                continue;
                            }
                            try {
                                $renderValues = $this->urls->valuesForPatterns($candidate, $slug, $renderer->tokens());
                                $path = $renderer->render(array_replace($renderValues, $variant));
                            } catch (\InvalidArgumentException) {
                                $this->rejectUnprovenOwnership();
                            }
                            $values = $matcher->match($path);
                            if ($values !== null && ($locator === 'id' ? (int) ($values['id'] ?? 0) === (int) $article->id : ($values['slug'] ?? '') === $value)) {
                                throw ValidationException::withMessages(['slug' => __('article_permalink.errors.ambiguous_path', ['path' => $path, 'articles' => '#'.$candidate->id.' / #'.$article->id])]);
                            }
                        }
                    }
                }
            }
        }
    }

    /** @param list<array<string,string>> $variants */
    private function constrain(Builder $query, string $source, string $target, float $deadline, array &$variants, bool &$uncertain): bool
    {
        if (! str_contains($target, '{')) {
            if (! str_contains($source, '{')) {
                return $source === $target;
            }
            $alternatives = $this->segmentValues($source, $target, $deadline);
            if ($alternatives === []) {
                return false;
            }
            // Retain inferred historical display values even after moves, deletion or date correction.
            $combined = [];
            foreach ($variants as $variant) {
                foreach ($alternatives as $values) {
                    $combined[] = array_merge($variant, $values);
                    $this->budget(count($combined), $deadline);
                }
            }
            $variants = $combined;
            $query->where(function (Builder $possibilities) use ($alternatives): void {
                foreach ($alternatives as $values) {
                    $possibilities->orWhere(function (Builder $candidate) use ($values): void {
                        foreach ($values as $token => $value) {
                            $this->whereValue($candidate, $token, $value, false);
                        }
                    });
                }
            });

            return true;
        }
        if (! str_contains($source, '{')) {
            return $this->segmentValues($target, $source, $deadline) !== [];
        }
        if (preg_match('/\A\{(slug|id)\}\z/', $source, $tokens) === 1) {
            $parts = preg_split('/(\{[a-z]+\})/', $target, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            $like = implode('', array_map(fn ($part) => preg_match('/\A\{[a-z]+\}\z/', $part) === 1 ? '%' : str_replace(['!', '%', '_'], ['!!', '!%', '!_'], rawurldecode($part)), $parts));
            $this->whereValue($query, $tokens[1], $like, true);
        }
        if (preg_match('/\{(?:category|year|month|day)\}/', $source) === 1
            && ! ($source === $target && substr_count($source, '{') === 1)
            && preg_match('/\A\{(?:category|slug)\}\z/', $target) !== 1) {
            // Current display values cannot prove disjointness for unresolved dynamic intersections.
            $uncertain = true;
        }

        return true;
    }

    /** @return list<array<string,string>> All renderable splits retain candidates hidden by greedy matching. */
    private function segmentValues(string $source, string $target, float $deadline): array
    {
        $parts = preg_split('/(\{[a-z]+\})/', $source, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $length = strlen($target);
        $alternatives = [];
        $visit = function (int $index, int $offset, array $values) use (&$visit, $parts, $target, $length, $deadline, &$alternatives): void {
            $this->budget(count($alternatives), $deadline);
            if ($index === count($parts)) {
                if ($offset === $length) {
                    $alternatives[] = $values;
                    $this->budget(count($alternatives), $deadline);
                }

                return;
            }
            $part = $parts[$index];
            if (! str_starts_with($part, '{')) {
                if (substr($target, $offset, strlen($part)) === $part) {
                    $visit($index + 1, $offset + strlen($part), $values);
                }

                return;
            }
            $token = substr($part, 1, -1);
            if ($offset >= $length) {
                return;
            }
            $separator = $parts[$index + 1] ?? null;
            $end = $separator === null ? $length : strpos($target, $separator, $offset + 1);
            while ($end !== false) {
                $this->budget(count($alternatives), $deadline);
                $encoded = substr($target, $offset, $end - $offset);
                $decoded = rawurldecode($encoded);
                if (preg_match('~\A(?:'.ArticlePermalinkPattern::tokenRegex($token).')\z~D', $encoded) === 1
                    && rawurlencode($decoded) === $encoded) {
                    $visit($index + 1, $end, array_merge($values, [$token => $decoded]));
                }
                if ($separator === null) {
                    break;
                }
                $end = strpos($target, $separator, $end + 1);
            }
        };
        $visit(0, 0, []);

        return $alternatives;
    }

    private function whereValue(Builder $query, string $token, string $value, bool $like): void
    {
        $condition = static function (Builder $builder, string $column) use ($value, $like): Builder {
            return $like
                ? $builder->whereRaw($builder->getQuery()->getGrammar()->wrap($column)." LIKE ? ESCAPE '!'", [$value])
                : $builder->where($column, $value);
        };
        if ($token === 'slug') {
            $query->where(fn ($q) => $condition($q, 'articles.slug')->orWhereIn('articles.id', $condition(ArticleSlugHistory::query(), 'slug')->select('article_id')));
        } elseif ($token === 'id') {
            if (! $like && ctype_digit($value)) {
                $query->where('articles.id', (int) $value);
            } elseif (! $like) {
                $query->whereRaw('1 = 0');
            }
        }
    }

    private function budget(int $count, float $deadline): void
    {
        if ($count > 500 || microtime(true) > $deadline) {
            $this->rejectUnprovenOwnership();
        }
    }

    private function rejectUnprovenOwnership(): never
    {
        throw ValidationException::withMessages(['slug' => __('url_change.errors.ownership_budget')]);
    }
}
