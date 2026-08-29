<?php

namespace App\Services\GeoFlow;

use Illuminate\Support\Str;

class ArticleFactCandidateExtractor
{
    private const FIELDS = ['title', 'excerpt', 'content', 'keywords', 'meta_description'];

    public function __construct(
        private readonly ArticleCitationMarkerCleaner $citationMarkerCleaner = new ArticleCitationMarkerCleaner,
    ) {}

    /**
     * @param  array<string, mixed>  $articleSnapshot
     * @return list<array<string, mixed>>
     */
    public function extract(array $articleSnapshot, int $limit = 12): array
    {
        $candidatesByHash = [];
        $cleanedSnapshot = $this->citationMarkerCleaner->cleanArticleFields($articleSnapshot);

        foreach (self::FIELDS as $field) {
            $text = (string) ($cleanedSnapshot[$field] ?? '');
            if ($text === '') {
                continue;
            }

            preg_match_all('/[^。！？!?；;\n]+[。！？!?；;]?/u', $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] ?? [] as $match) {
                $raw = (string) ($match[0] ?? '');
                $byteOffset = (int) ($match[1] ?? 0);
                $quote = trim($raw);
                if ($quote === '') {
                    continue;
                }

                $type = $this->claimType($quote);
                if ($type === null) {
                    continue;
                }

                $leadingCharacters = Str::length($raw) - Str::length(ltrim($raw));
                $startOffset = mb_strlen(substr($text, 0, $byteOffset), 'UTF-8') + $leadingCharacters;
                $normalizedClaim = $this->normalizeClaim($quote);
                if ($normalizedClaim === '') {
                    continue;
                }

                $claimHash = hash('sha256', $normalizedClaim);
                $occurrence = [
                    'field' => $field,
                    'quote' => $quote,
                    'start_offset' => $startOffset,
                    'end_offset' => $startOffset + Str::length($quote),
                    'source_hash' => hash('sha256', $field."\0".$quote),
                ];

                if (isset($candidatesByHash[$claimHash])) {
                    $candidatesByHash[$claimHash]['occurrences'][] = $occurrence;

                    continue;
                }

                $candidatesByHash[$claimHash] = [
                    'field' => $field,
                    'quote' => $quote,
                    'normalized_claim' => $normalizedClaim,
                    'claim_hash' => $claimHash,
                    'type' => $type,
                    'materiality' => $this->materiality($type),
                    'start_offset' => $occurrence['start_offset'],
                    'end_offset' => $occurrence['end_offset'],
                    'source_hash' => $occurrence['source_hash'],
                    'occurrences' => [$occurrence],
                ];
            }
        }

        $fieldRanks = array_flip(self::FIELDS);
        $candidates = array_values($candidatesByHash);
        usort($candidates, static function (array $left, array $right) use ($fieldRanks): int {
            $materialityRanks = ['high' => 0, 'medium' => 1, 'low' => 2];
            $materialityDifference = ($materialityRanks[(string) ($left['materiality'] ?? '')] ?? 3)
                <=> ($materialityRanks[(string) ($right['materiality'] ?? '')] ?? 3);
            if ($materialityDifference !== 0) {
                return $materialityDifference;
            }

            $fieldDifference = ($fieldRanks[(string) ($left['field'] ?? '')] ?? count(self::FIELDS))
                <=> ($fieldRanks[(string) ($right['field'] ?? '')] ?? count(self::FIELDS));

            return $fieldDifference !== 0
                ? $fieldDifference
                : ((int) ($left['start_offset'] ?? 0) <=> (int) ($right['start_offset'] ?? 0));
        });
        $candidates = array_slice($candidates, 0, max(1, min(1000, $limit)));

        foreach ($candidates as $index => &$candidate) {
            $candidate['id'] = 'F'.($index + 1);
        }
        unset($candidate);

        return $candidates;
    }

    private function claimType(string $claim): ?string
    {
        $patterns = [
            'percentage' => '/(?:\d+(?:[.,]\d+)?\s*(?:%|％)|百分之[零〇一二两三四五六七八九十百千万点\d.]+|(?:增长率|转化率|占比)\D{0,12}\d+(?:[.,]\d+)?\s*(?:%|％)?)/u',
            'amount' => '/(?:\d[\d,.]*\s*(?:元|万元|亿元|USD|CNY|RMB|\$|¥)|(?:\$|¥)\s*\d[\d,.]*|(?:价格|金额|售价|费用)\D{0,12}\d[\d,.]*|(?:人民币|美元|港元|欧元)\s*\d[\d,.]*)/iu',
            'date' => '/(?:\d{4}\s*年|\d{1,2}\s*月|\d{1,2}\s*日|\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2})/u',
            'ranking' => '/(?:第\s*[一二三四五六七八九十\d]+|排名|领先|唯一|首个|最高|最佳|国家级)/u',
            'qualification' => '/(?:(?:获得|取得|通过|拥有|持有|具备|获批)\D{0,12}(?:资质|认证|证书|许可|专利)|(?:资质|认证|证书|许可|专利)\D{0,8}(?:编号|号码|号为)\s*[A-Z0-9-]+|ISO\s*\d+)/iu',
            'guarantee' => '/(?:保证|确保|承诺|零风险|稳赚|百分百|100%|绝对)/u',
            'citation' => '/(?:据.{0,20}(?:报告|研究|数据|统计|显示|披露)|(?:来源|引用|参考资料|文献|报告)\s*[:：]|“[^”]{4,}”|「[^」]{4,}」)/u',
            'comparison' => '/(?:高于|低于|超过|优于|不低于|不少于|同比|环比)/u',
            'quantity' => '/(?:\d[\d,.]*\s*(?:家|人|户|次|项|个|台|套|份|篇|件|所|名)|(?:客户|用户|门店|员工|项目|案例|企业|机构)\D{0,8}\d[\d,.]*)/u',
        ];

        if (preg_match('/[？?]\s*$/u', $claim) === 1) {
            return null;
        }

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $claim) === 1) {
                return $type;
            }
        }

        return null;
    }

    private function materiality(string $type): string
    {
        return in_array($type, ['percentage', 'amount', 'date', 'ranking', 'qualification', 'guarantee', 'citation'], true)
            ? 'high'
            : 'medium';
    }

    private function normalizeClaim(string $claim): string
    {
        $normalized = Str::squish($claim);
        $normalized = preg_replace('/[。！？!?；;，,：:\s]+$/u', '', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
