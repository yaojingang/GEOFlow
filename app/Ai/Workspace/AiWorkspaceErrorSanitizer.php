<?php

namespace App\Ai\Workspace;

use Illuminate\Support\Str;

final class AiWorkspaceErrorSanitizer
{
    public static function clean(string $message, int $limit = 2000): string
    {
        $clean = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $message) ?? $message;
        $clean = preg_replace('/\bBasic\s+[A-Za-z0-9._~+\/-]+=*/i', 'Basic [REDACTED]', $clean) ?? $clean;
        $clean = preg_replace_callback(
            '/(["\'])(api[_-]?key|access[_-]?token|token|secret|password)\1\s*:\s*(["\'])(.*?)\3/i',
            static fn (array $matches): string => $matches[1].$matches[2].$matches[1].':'.$matches[3].'[REDACTED]'.$matches[3],
            $clean,
        ) ?? $clean;
        $clean = preg_replace(
            '/\b(api[_-]?key|access[_-]?token|token|secret|password)\b\s*[:=]\s*([^\s,;]+)/i',
            '$1=[REDACTED]',
            $clean,
        ) ?? $clean;
        $clean = preg_replace(
            '/([?&](?:api[_-]?key|access[_-]?token|token|secret|password)=)[^&#\s]+/i',
            '$1[REDACTED]',
            $clean,
        ) ?? $clean;
        $clean = preg_replace('#(https?://)[^/@\s]+:[^/@\s]+@#i', '$1[REDACTED]@', $clean) ?? $clean;
        $clean = preg_replace('~https?://[^\s"\'<>]+~iu', '[REDACTED_URL]', $clean) ?? $clean;
        $clean = preg_replace('/\bsk-[A-Za-z0-9_-]{8,}\b/', '[REDACTED]', $clean) ?? $clean;

        return Str::limit(trim($clean), $limit, '');
    }
}
