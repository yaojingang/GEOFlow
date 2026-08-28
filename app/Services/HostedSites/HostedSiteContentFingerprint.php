<?php

namespace App\Services\HostedSites;

use App\Models\Article;

final class HostedSiteContentFingerprint
{
    public function forArticle(Article $article): string
    {
        $title = $this->normalize((string) $article->title);
        $content = $this->normalize((string) $article->content);

        return hash('sha256', $title."\n".$content);
    }

    private function normalize(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtolower($value);
    }
}
