<?php

namespace App\Services\GeoFlow;

use Illuminate\Support\Str;

class ArticleFactCandidateExtractor
{
    private const FIELDS = ['title', 'excerpt', 'content', 'keywords', 'meta_description'];

    /**
     * @param  array<string, mixed>  $articleSnapshot
     * @return list<array{id:string,field:string,quote:string,type:string,materiality:string,start_offset:int,end_offset:int,source_hash:string}>
     */
    public function extract(array $articleSnapshot): array
    {
        $candidates = [];

        foreach (self::FIELDS as $field) {
            $text = (string) ($articleSnapshot[$field] ?? '');
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
                $candidates[] = [
                    'id' => 'F'.(count($candidates) + 1),
                    'field' => $field,
                    'quote' => $quote,
                    'type' => $type,
                    'materiality' => $this->materiality($type),
                    'start_offset' => $startOffset,
                    'end_offset' => $startOffset + Str::length($quote),
                    'source_hash' => hash('sha256', $field."\0".$quote),
                ];
            }
        }

        return $candidates;
    }

    private function claimType(string $claim): ?string
    {
        $patterns = [
            'percentage' => '/(?:%|％|百分之|增长率|转化率|占比)/u',
            'amount' => '/(?:价格|金额|售价|费用|人民币|美元|港元|欧元|\d[\d,.]*\s*(?:元|万元|亿元|USD|CNY|RMB|\$|¥))/iu',
            'date' => '/(?:\d{4}\s*年|\d{1,2}\s*月|\d{1,2}\s*日|\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2})/u',
            'ranking' => '/(?:第\s*[一二三四五六七八九十\d]+|排名|领先|唯一|首个|最高|最佳|国家级)/u',
            'qualification' => '/(?:资质|认证|证书|许可|专利|ISO\s*\d+)/iu',
            'guarantee' => '/(?:保证|确保|承诺|零风险|稳赚|百分百|100%|绝对)/u',
            'comparison' => '/(?:高于|低于|超过|优于|不低于|不少于|同比|环比)/u',
            'number' => '/\d/u',
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $claim) === 1) {
                return $type;
            }
        }

        return null;
    }

    private function materiality(string $type): string
    {
        return in_array($type, ['percentage', 'amount', 'date', 'ranking', 'qualification', 'guarantee'], true)
            ? 'high'
            : 'medium';
    }
}
