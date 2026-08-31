<?php

namespace Tests\Unit;

use App\Services\GeoFlow\KnowledgeFacts\AtomicFactComparator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AtomicFactComparatorV2Test extends TestCase
{
    #[DataProvider('typedCases')]
    public function test_it_compares_typed_atomic_values(array $claim, array $standard, string $result): void
    {
        $this->assertSame($result, app(AtomicFactComparator::class)->compare($claim, $standard)['result']);
    }

    public static function typedCases(): array
    {
        return [
            'scaled integer' => [['value' => '1.28', 'unit' => '万'], ['value' => '12800', 'unit' => ''], 'match'],
            'percentage' => [['value' => '25', 'unit' => '%'], ['value' => '0.25', 'unit' => ''], 'match'],
            'date' => [['text' => '2026年8月30日', 'type' => 'date'], ['answer' => '2026-08-30', 'type' => 'date'], 'match'],
            'version conflict' => [['text' => 'v2.1.1', 'type' => 'version'], ['answer' => 'v2.1.0', 'type' => 'version'], 'mismatch'],
            'path' => [['text' => '/admin/themes/preview', 'type' => 'path'], ['answer' => '/admin/themes/preview/', 'type' => 'path'], 'match'],
            'range' => [['text' => '10 至 20', 'type' => 'range'], ['answer' => '10-20', 'type' => 'range'], 'match'],
            'boolean' => [['text' => '支持', 'type' => 'boolean'], ['answer' => '是', 'type' => 'boolean'], 'match'],
        ];
    }

    public function test_missing_comparable_value_is_not_covered(): void
    {
        $result = app(AtomicFactComparator::class)->compare(['text' => ''], ['answer' => '标准答案']);

        $this->assertSame('not_covered', $result['result']);
        $this->assertSame('passed', $result['decision']);
    }
}
