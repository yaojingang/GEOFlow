<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Title extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'titles';

    protected $fillable = [
        'library_id',
        'title',
        'keyword',
        'is_ai_generated',
        'used_count',
        'usage_count',
    ];

    protected static function booted(): void
    {
        static::saving(function (Title $title): void {
            if (! $title->exists || $title->isDirty(['library_id', 'title'])) {
                $title->title = self::normalizeText((string) $title->title);
                $title->title_fingerprint = self::fingerprintFor((string) $title->title);
            }
        });
    }

    public static function fingerprintFor(string $title): string
    {
        return hash('sha256', self::normalizeText($title));
    }

    public static function normalizeText(string $title): string
    {
        $normalized = $title;
        if (class_exists(\Normalizer::class)) {
            $candidate = \Normalizer::normalize($title, \Normalizer::FORM_KC);
            if (is_string($candidate)) {
                $normalized = $candidate;
            }
        }
        $normalized = preg_replace(
            '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200B}\x{2060}\x{FEFF}]/u',
            '',
            $normalized,
        );
        $normalized = preg_replace('/\s+/u', ' ', (string) $normalized);

        return trim((string) $normalized);
    }

    protected function casts(): array
    {
        return [
            'library_id' => 'integer',
            'is_ai_generated' => 'boolean',
            'used_count' => 'integer',
            'usage_count' => 'integer',
        ];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(TitleLibrary::class, 'library_id');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'source_title_id');
    }
}
