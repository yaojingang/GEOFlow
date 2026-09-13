<?php

namespace App\Data\Site;

use App\Models\Article;

class ArticlePermalinkResolution
{
    public function __construct(
        public readonly Article $article,
        public readonly string $canonicalPath,
        public readonly bool $isCanonical,
        public readonly string $source,
        public readonly string $reason,
    ) {}
}
