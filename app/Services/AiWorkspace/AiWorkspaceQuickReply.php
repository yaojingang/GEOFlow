<?php

namespace App\Services\AiWorkspace;

use Illuminate\Support\Str;

final class AiWorkspaceQuickReply
{
    private const PRODUCT_INTRO = 'GEOFlow 是面向生成式引擎优化（GEO）的内容运营系统，用于连接 AI 可见性诊断、知识资产、内容生产、任务管理和多站分发。';

    /** @var array<string,string> */
    private const REPLIES = [
        '你好' => '你好！我是 GEOFlow。你可以直接告诉我想查询的数据、要生成的内容或需要处理的任务。',
        '您好' => '您好！我是 GEOFlow。你可以直接告诉我想查询的数据、要生成的内容或需要处理的任务。',
        '嗨' => '嗨！我是 GEOFlow。你可以直接告诉我想查询的数据、要生成的内容或需要处理的任务。',
        '哈喽' => '哈喽！我是 GEOFlow。你可以直接告诉我想查询的数据、要生成的内容或需要处理的任务。',
        '在吗' => '在的，我是 GEOFlow。请直接告诉我需要查询或处理的事项。',
        '早上好' => '早上好！我是 GEOFlow。请直接告诉我今天需要查询或处理的事项。',
        '下午好' => '下午好！我是 GEOFlow。请直接告诉我需要查询或处理的事项。',
        '晚上好' => '晚上好！我是 GEOFlow。请直接告诉我需要查询或处理的事项。',
        'hi' => 'Hi! I am GEOFlow. Tell me what data you want to check or which task you want to handle.',
        'hello' => 'Hello! I am GEOFlow. Tell me what data you want to check or which task you want to handle.',
        'hey' => 'Hey! I am GEOFlow. Tell me what data you want to check or which task you want to handle.',
    ];

    public function replyFor(string $prompt): ?string
    {
        $normalized = Str::lower(trim($prompt));
        $normalized = preg_replace('/^[\p{Z}\p{P}\p{S}]+|[\p{Z}\p{P}\p{S}]+$/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? $normalized;
        $reply = self::REPLIES[$normalized] ?? null;

        if ($reply !== null) {
            return $reply;
        }

        if (preg_match('/^(?:你好[,， ]*)?(?:请)?(?:用一句话)?(?:简单|简要)?(?:介绍|说明)(?:一下)?\s*geoflow$/iu', $normalized) === 1
            || preg_match('/^geoflow\s*(?:是什么|能做什么)$/iu', $normalized) === 1) {
            return self::PRODUCT_INTRO;
        }

        return null;
    }
}
