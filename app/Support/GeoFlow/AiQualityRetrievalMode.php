<?php

namespace App\Support\GeoFlow;

final class AiQualityRetrievalMode
{
    public const ATOMIC_FIRST = 'atomic_first';

    public const CHUNK = 'chunk';

    public const KNOWLEDGE_BROAD = 'knowledge_broad';

    /** @return list<string> */
    public static function values(): array
    {
        return [self::ATOMIC_FIRST, self::CHUNK, self::KNOWLEDGE_BROAD];
    }

    public static function legacyDefault(): string
    {
        return self::CHUNK;
    }

    public static function isValid(?string $mode): bool
    {
        return is_string($mode) && in_array($mode, self::values(), true);
    }

    public static function label(string $mode): string
    {
        return match ($mode) {
            self::ATOMIC_FIRST => '原子质检',
            self::CHUNK => '切片质检',
            self::KNOWLEDGE_BROAD => '知识库质检',
            default => $mode,
        };
    }

    /** @return array<string,array{label:string,badge:string,description:string}> */
    public static function options(): array
    {
        return [
            self::ATOMIC_FIRST => [
                'label' => '原子质检',
                'badge' => '精准优先',
                'description' => '使用已发布的原子事实逐条核验，未覆盖主张继续使用切片。',
            ],
            self::CHUNK => [
                'label' => '切片质检',
                'badge' => '效率均衡',
                'description' => '按文章主张召回相关切片，兼顾准确度、成本和速度。',
            ],
            self::KNOWLEDGE_BROAD => [
                'label' => '知识库质检',
                'badge' => '覆盖优先',
                'description' => '从知识库正文按段落与前中后区域做宽范围取证，噪音、Token 和耗时更高。',
            ],
        ];
    }
}
