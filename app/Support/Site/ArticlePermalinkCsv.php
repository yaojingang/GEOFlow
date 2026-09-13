<?php

namespace App\Support\Site;

final class ArticlePermalinkCsv
{
    public static function cell(int|string $value): int|string
    {
        if (is_int($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r\n]/u', $value) === 1
            ? "'".$value
            : $value;
    }
}
