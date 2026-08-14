<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PublicPage extends Model
{
    public const AREAS = ['institution', 'health', 'governance'];

    protected $fillable = [
        'slug',
        'page_type',
        'area',
        'title',
        'eyebrow',
        'summary',
        'body',
        'seo_title',
        'meta_description',
        'cta_label',
        'cta_url',
        'sort_order',
        'is_placeholder',
        'status',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_placeholder' => 'boolean',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $page): void {
            $page->content_hash = hash('sha256', json_encode(
                $page->approvalPayload(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
        });
    }

    public function facts(): BelongsToMany
    {
        return $this->belongsToMany(PublicFact::class, 'public_fact_page');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ContentApproval::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(PublicationSnapshot::class);
    }

    public function activeSnapshot(): HasOne
    {
        return $this->hasOne(PublicationSnapshot::class)->where('is_active', true)->latestOfMany('published_at');
    }

    /**
     * @return array<string, mixed>
     */
    public function contentPayload(): array
    {
        return [
            'slug' => trim((string) $this->slug),
            'page_type' => trim((string) $this->page_type),
            'area' => trim((string) $this->area),
            'title' => trim((string) $this->title),
            'eyebrow' => trim((string) $this->eyebrow),
            'summary' => trim((string) ($this->summary ?? '')),
            'body' => trim((string) $this->body),
            'seo_title' => trim((string) $this->seo_title),
            'meta_description' => trim((string) ($this->meta_description ?? '')),
            'cta_label' => trim((string) $this->cta_label),
            'cta_url' => trim((string) $this->cta_url),
            'sort_order' => (int) $this->sort_order,
            'is_placeholder' => (bool) $this->is_placeholder,
            'version' => (int) $this->version,
        ];
    }

    /** @return array<string, mixed> */
    public function approvalPayload(): array
    {
        $facts = $this->exists
            ? $this->facts()->orderBy('fact_code')->get(['fact_code', 'statement', 'evidence_level', 'visibility', 'status', 'expires_at', 'updated_at'])
            : collect();

        return [
            ...$this->contentPayload(),
            '_fact_fingerprints' => $facts->map(fn (PublicFact $fact): array => [
                'fact_code' => $fact->fact_code,
                'statement_hash' => hash('sha256', (string) $fact->statement),
                'evidence_level' => (int) $fact->evidence_level,
                'visibility' => (string) $fact->visibility,
                'status' => (string) $fact->status,
                'expires_at' => $fact->expires_at?->toDateString(),
                'updated_at' => $fact->updated_at?->toAtomString(),
            ])->all(),
        ];
    }
}
