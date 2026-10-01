<?php

namespace App\Services\Topics;

use App\Models\Article;
use Illuminate\Support\Facades\Validator;

final class TopicMatchingRules
{
    public const VERSION = 1;

    public const WEIGHTS = ['title' => 6, 'keywords' => 3, 'excerpt' => 3, 'body' => 1];

    public static function rules(string $prefix = 'matching_rules'): array
    {
        return [
            $prefix => ['nullable', 'array:version,related_terms,required_groups,excluded_terms'],
            $prefix.'.version' => ['nullable', 'integer', 'in:1'],
            $prefix.'.related_terms' => ['nullable', 'array', 'max:50'], $prefix.'.related_terms.*' => ['required', 'string', 'max:80'],
            $prefix.'.required_groups' => ['nullable', 'array', 'max:20'], $prefix.'.required_groups.*' => ['required', 'array', 'min:1', 'max:20'], $prefix.'.required_groups.*.*' => ['required', 'string', 'max:80'],
            $prefix.'.excluded_terms' => ['nullable', 'array', 'max:50'], $prefix.'.excluded_terms.*' => ['required', 'string', 'max:80'],
        ];
    }

    public function parse(array $input): array
    {
        foreach (['related_terms', 'excluded_terms'] as $key) {
            if (array_key_exists($key.'_text', $input) && (is_string($input[$key.'_text']) || $input[$key.'_text'] === null)) {
                $input[$key] = $this->terms((string) $input[$key.'_text']);
                unset($input[$key.'_text']);
            }
        }
        if (array_key_exists('required_groups_text', $input) && (is_string($input['required_groups_text']) || $input['required_groups_text'] === null)) {
            $input['required_groups'] = array_values(array_map(fn (string $line): array => $this->terms($line), array_filter(preg_split('/\r\n|\r|\n/', (string) $input['required_groups_text']), fn ($line) => trim($line) !== '')));
            unset($input['required_groups_text']);
        }

        return $input;
    }

    public function normalize(array $input): array
    {
        $data = Validator::make(['matching_rules' => $this->parse($input)], self::rules())->validate()['matching_rules'];
        $normalize = static fn (array $terms): array => array_values(array_unique(array_map(static fn (string $term): string => mb_strtolower(trim($term)), $terms)));

        return ['version' => self::VERSION, 'related_terms' => $normalize($data['related_terms'] ?? []), 'required_groups' => array_map($normalize, $data['required_groups'] ?? []), 'excluded_terms' => $normalize($data['excluded_terms'] ?? [])];
    }

    private function terms(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,，|\r\n]+/u', $text)), static fn ($term): bool => $term !== ''));
    }

    public function titleTerms(string $title): array
    {
        preg_match_all('/[a-z0-9]{2,}|[\p{Han}]{2,}/iu', $title, $matches);
        $terms = [];
        foreach ($matches[0] as $word) {
            $word = mb_strtolower($word);
            if (preg_match('/\p{Han}/u', $word)) {
                for ($i = 0; $i < mb_strlen($word) - 1; $i++) {
                    $terms[] = mb_substr($word, $i, 2);
                }
            } else {
                $terms[] = $word;
            }
        }

        return array_values(array_unique($terms));
    }

    public function match(Article $article, array $rules, array $baseTerms): array
    {
        $fields = ['title' => mb_strtolower($article->title), 'keywords' => mb_strtolower(is_array($article->keywords) ? implode(' ', $article->keywords) : (string) $article->keywords), 'excerpt' => mb_strtolower(strip_tags((string) $article->excerpt)), 'body' => mb_strtolower(html_entity_decode(strip_tags((string) $article->content), ENT_QUOTES | ENT_HTML5, 'UTF-8'))];
        $has = static fn (string $term): bool => array_any($fields, static fn (string $text): bool => mb_strpos($text, $term) !== false);
        if (array_any($rules['excluded_terms'], static fn (string $term): bool => mb_strpos($fields['title'], $term) !== false || mb_strpos($fields['keywords'], $term) !== false)) {
            return ['matched' => false, 'reason' => 'excluded_term'];
        }
        if ($baseTerms !== [] && ! array_any($baseTerms, $has)) {
            return ['matched' => false, 'reason' => 'no_related_term'];
        }
        foreach ($rules['required_groups'] as $group) {
            if (! array_any($group, $has)) {
                return ['matched' => false, 'reason' => 'required_group'];
            }
        }
        $score = 0;
        $hits = [];
        $terms = array_values(array_unique(array_merge($baseTerms, ...$rules['required_groups'])));
        foreach ($terms as $term) {
            foreach (self::WEIGHTS as $field => $weight) {
                if (mb_strpos($fields[$field], $term) !== false) {
                    $score += $weight;
                    $hits[] = ['term' => $term, 'field' => $field, 'weight' => $weight];
                }
            }
        }
        $body = html_entity_decode(strip_tags((string) $article->content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $snippets = [mb_substr($body, 0, 1200)];
        foreach (array_slice(array_column(array_filter($hits, static fn ($hit): bool => $hit['field'] === 'body'), 'term'), 0, 4) as $term) {
            $position = mb_strpos($fields['body'], $term);
            if ($position > 1000) {
                $snippets[] = mb_substr($body, max(0, $position - 100), 300);
            }
        }

        return ['matched' => true, 'score' => $score, 'hits' => $hits, 'content' => implode("\n", array_unique($snippets))];
    }
}
