<?php

namespace App\Support;

use App\Models\Title;
use Closure;

final class LibraryImportPolicy
{
    public const MAX_TEXT_BYTES = 4 * 1024 * 1024;

    public const MAX_ENTRIES = 1_000;

    public const KEYWORD_MAX_CHARACTERS = 200;

    public const TITLE_MAX_CHARACTERS = 500;

    public const TITLE_KEYWORD_MAX_CHARACTERS = 200;

    public const DESCRIPTION_MAX_CHARACTERS = 2_000;

    public const INSERT_CHUNK_SIZE = 250;

    public const MAX_PARSE_SEGMENTS = 10_000;

    /**
     * @return list<mixed>
     */
    public static function rawTextRules(string $tooLargeMessage): array
    {
        return [
            'required',
            'string',
            static function (string $attribute, mixed $value, Closure $fail) use ($tooLargeMessage): void {
                if (is_string($value) && strlen($value) > self::MAX_TEXT_BYTES) {
                    $fail($tooLargeMessage);
                }
            },
        ];
    }

    public static function rejectNullByteRule(string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            if (is_string($value) && self::containsNullByte($value)) {
                $fail($message);
            }
        };
    }

    public static function rejectInvalidUtf8Rule(string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            if (is_string($value) && ! self::isValidUtf8($value)) {
                $fail($message);
            }
        };
    }

    public static function isValidUtf8(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8');
    }

    public static function containsNullByte(string $value): bool
    {
        return str_contains($value, "\0");
    }

    public static function containsNullByteInInput(mixed $value): bool
    {
        if (is_string($value)) {
            return self::containsNullByte($value);
        }
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (self::containsNullByteInInput($item)) {
                return true;
            }
        }

        return false;
    }

    public static function sanitizeDatabaseText(string $value): string
    {
        return str_replace("\0", '', mb_scrub($value, 'UTF-8'));
    }

    public static function flashableText(mixed $value, int $maxCharacters): ?string
    {
        if (! is_string($value)
            || ! self::isValidUtf8($value)
            || self::containsNullByte($value)
            || mb_strlen($value, 'UTF-8') > $maxCharacters) {
            return null;
        }

        return $value;
    }

    public static function normalizeTitle(string $value): string
    {
        return Title::normalizeText($value);
    }

    public static function titleFitsStorage(string $normalizedTitle): bool
    {
        return mb_strlen($normalizedTitle, 'UTF-8') <= self::TITLE_MAX_CHARACTERS;
    }

    public static function normalizeStorableTitle(string $value): ?string
    {
        $normalized = self::normalizeTitle($value);

        return $normalized !== '' && self::titleFitsStorage($normalized)
            ? $normalized
            : null;
    }

    /**
     * @return array{max_text_size:string,max_entries:int,keyword_max_characters:int,title_max_characters:int,title_keyword_max_characters:int}
     */
    public static function viewLimits(): array
    {
        return [
            'max_text_size' => (self::MAX_TEXT_BYTES / 1024 / 1024).' MB',
            'max_entries' => self::MAX_ENTRIES,
            'keyword_max_characters' => self::KEYWORD_MAX_CHARACTERS,
            'title_max_characters' => self::TITLE_MAX_CHARACTERS,
            'title_keyword_max_characters' => self::TITLE_KEYWORD_MAX_CHARACTERS,
        ];
    }

    /**
     * @return array{segments:list<string>,overflow:bool}
     */
    public static function splitBounded(string $text, string $delimiterPattern): array
    {
        $segments = preg_split($delimiterPattern, $text, self::MAX_PARSE_SEGMENTS + 1);
        if ($segments === false || count($segments) > self::MAX_PARSE_SEGMENTS) {
            return ['segments' => [], 'overflow' => true];
        }

        return ['segments' => array_values($segments), 'overflow' => false];
    }
}
