<?php

namespace App\Services\HostedSites;

use App\Models\Article;
use App\Models\HostedSiteArticleAssignment;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class HostedSiteArticleFingerprintService
{
    public function __construct(private readonly HostedSiteContentFingerprint $fingerprints) {}

    public function synchronizeLockedArticle(Article $article): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Hosted article fingerprints require an active transaction.');
        }

        $assignment = HostedSiteArticleAssignment::query()
            ->where('article_id', (int) $article->id)
            ->lockForUpdate()
            ->first();
        if (! $assignment) {
            return;
        }

        $fingerprint = $this->fingerprints->forArticle($article);
        if (hash_equals((string) $assignment->content_fingerprint, $fingerprint)) {
            return;
        }
        if (HostedSiteArticleAssignment::query()
            ->whereKeyNot((int) $assignment->id)
            ->where('content_fingerprint', $fingerprint)
            ->exists()) {
            throw new DomainException('An identical hosted article already exists.');
        }

        try {
            $assignment->forceFill(['content_fingerprint' => $fingerprint])->save();
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException('An identical hosted article already exists.', previous: $exception);
        }
    }
}
