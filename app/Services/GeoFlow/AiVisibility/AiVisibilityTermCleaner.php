<?php

namespace App\Services\GeoFlow\AiVisibility;

interface AiVisibilityTermCleaner
{
    /**
     * 清洗词云候选主题：剔除噪声、合并同义与碎片、规范命名。
     * 不可用或失败时返回 null，由调用方回退到启发式结果。
     *
     * @param  array<string, float>  $candidates  候选词 => 权重
     * @return list<array{name: string, terms: list<string>}>|null
     */
    public function clean(array $candidates): ?array;
}
