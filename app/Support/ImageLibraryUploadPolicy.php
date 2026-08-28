<?php

namespace App\Support;

final class ImageLibraryUploadPolicy
{
    /** @var list<string> */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** @var list<string> */
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public static function maxKilobytes(): int
    {
        return max(1, (int) ceil((int) config('geoflow.max_upload_bytes', 2 * 1024 * 1024) / 1024));
    }

    public static function maxBytes(): int
    {
        return self::maxKilobytes() * 1024;
    }

    public static function accept(): string
    {
        return implode(',', self::MIME_TYPES);
    }
}
