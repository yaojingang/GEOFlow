<?php

namespace App\Services\GeoFlow;

class KnowledgeEvidenceSecurityInspector
{
    /** @param array<string,mixed> $evidence */
    public function hasPromptInjectionRisk(array $evidence): bool
    {
        $metadata = is_array($evidence['metadata'] ?? null) ? $evidence['metadata'] : [];
        $text = implode("\n", array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            [
                $evidence['content'] ?? '',
                $evidence['chunk_title'] ?? '',
                $evidence['section_path'] ?? '',
                $metadata['knowledge_base_name'] ?? '',
                $metadata['source_name'] ?? '',
                $metadata['source_url'] ?? '',
                $metadata['source_type'] ?? '',
                $metadata['business_line'] ?? '',
            ],
        )));
        $text = (string) preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text);
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        }
        $compactText = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $text);

        $patterns = [
            '/(?:ignore|disregard|forget|discard|override|bypass)\s+(?:(?:all|every|any)\s+)?(?:the\s+)?(?:(?:previous|prior|above|earlier|system|developer|given)\s+)?(?:instructions?|rules?|prompts?|constraints?|guidelines?|directions?)(?:\s+(?:above|earlier|previously))?/iu',
            '/(?:(?:do\s+not|don[’\']t|never|stop)\s+)(?:keep\s+)?(?:follow(?:ing)?|obey|comply\s+with|adhere\s+to)\s+(?:all\s+)?(?:the\s+)?(?:(?:previous|prior|above|earlier|system|developer|given)\s+)?(?:instructions?|rules?|prompts?|constraints?|guidelines?|directions?)/iu',
            '/(?:follow|obey|execute|apply)\s+(?:(?:these|the\s+following)\s+)?(?:new|replacement|updated)\s+(?:instructions?|directives?|rules?|prompts?)(?:\s+instead)?/iu',
            '/(?:the\s+)?(?:new|replacement|updated)\s+(?:instructions?|directives?|rules?|prompts?)\s+(?:is|are|says?|requires?)/iu',
            '/(?:treat|regard|consider).{0,40}(?:highest|top|overriding)[\s-]*(?:priority\s+)?(?:instructions?|directives?|rules?|prompts?)/iu',
            '/(?:from\s+now\s+on|going\s+forward|henceforth).{0,24}(?:only|always)\s+(?:return|output|respond|say)/iu',
            '/(?:^|[\r\n.!?;:])\s*(?:please\s+)?(?:directly\s+)?(?:output|return|reply|respond|answer|say)\s+(?:with\s+)?(?:exactly\s+|only\s+)?(?:passed|approved|safe|compliant|no\s+(?:issues?|problems?|errors?|violations?))(?:\s*[.!?]|\s*$)/imu',
            '/(?:approve|pass|accept).{0,24}(?:the\s+)?(?:article|content).{0,24}(?:regardless|irrespective|whatever)/iu',
            '/(?:regardless|irrespective)\s+of.{0,24}(?:approve|pass|accept).{0,16}(?:article|content)/iu',
            '/(?:system\s+prompt|developer\s+message|jailbreak|switch\s+(?:your\s+)?role|act\s+as\s+(?:the\s+)?system)/iu',
            '/(?:忽略|无视|忘记|忘掉|抛弃|覆盖|绕过|跳过).{0,16}(?:之前|此前|以上|上述|上面|原有|系统|开发者)?.{0,8}(?:指令|规则|要求|提示词|约束|指南|方向)/u',
            '/(?:不要|不许|禁止|停止).{0,8}(?:遵循|服从|执行|理会).{0,16}(?:之前|此前|以上|上述|上面|原有|系统|开发者)?.{0,8}(?:指令|规则|要求|提示词|约束|指南|方向)/u',
            '/(?:改为|转而|切换为).{0,8}(?:执行|遵循|服从|采用).{0,12}(?:以下|下列|新的?|后续).{0,6}(?:指令|规则|要求|提示词|约束)/u',
            '/(?:从现在起|从今以后|今后|后续).{0,12}(?:只|仅).{0,6}(?:输出|返回|回复|回答)/u',
            '/(?:请)?(?:直接)?(?:输出|返回|回复|回答).{0,8}(?:通过|批准|安全|合规|没有问题|无问题)/u',
            '/(?:无论|不论).{0,16}(?:内容|文章).{0,16}(?:批准|通过|接受|判定安全)/u',
            '/(?:系统提示词|开发者消息|越狱|切换角色|扮演系统)/u',
        ];

        $compactPatterns = [
            '/(?:ignore|disregard|forget|discard|override|bypass)(?:all|every|any)?(?:the)?(?:previous|prior|above|earlier|system|developer|given)?(?:instructions?|rules?|prompts?|constraints?|guidelines?|directions?)/iu',
            '/(?:donot|dont|never|stop)(?:keep)?(?:follow(?:ing)?|obey|complywith|adhereto)(?:all)?(?:the)?(?:previous|prior|above|earlier|system|developer|given)?(?:instructions?|rules?|prompts?|constraints?|guidelines?|directions?)/iu',
            '/(?:follow|obey|execute|apply)(?:these|thefollowing)?(?:new|replacement|updated)(?:instructions?|directives?|rules?|prompts?)(?:instead)?/iu',
            '/(?:the)?(?:new|replacement|updated)(?:instructions?|directives?|rules?|prompts?)(?:is|are|says?|requires?)/iu',
            '/(?:treat|regard|consider).{0,32}(?:highest|top|overriding)(?:priority)?(?:instructions?|directives?|rules?|prompts?)/iu',
            '/(?:fromnowon|goingforward|henceforth).{0,20}(?:only|always)(?:return|output|respond|say)/iu',
            '/^(?:please)?(?:directly)?(?:output|return|reply|respond|answer|say)(?:with)?(?:exactly|only)?(?:passed|approved|safe|compliant|no(?:issues?|problems?|errors?|violations?))$/iu',
            '/(?:approve|pass|accept)(?:the)?(?:article|content).{0,20}(?:regardless|irrespective|whatever)/iu',
            '/(?:忽略|无视|忘记|忘掉|抛弃|覆盖|绕过|跳过)(?:所有)?(?:之前|此前|以上|上述|上面|原有|系统|开发者)?(?:的)?(?:指令|规则|要求|提示词|约束|指南|方向)/u',
            '/(?:不要|不许|禁止|停止)(?:继续)?(?:遵循|服从|执行|理会)(?:所有)?(?:之前|此前|以上|上述|上面|原有|系统|开发者)?(?:的)?(?:指令|规则|要求|提示词|约束|指南|方向)/u',
            '/(?:改为|转而|切换为)(?:执行|遵循|服从|采用)(?:以下|下列|新的?|后续)(?:的)?(?:指令|规则|要求|提示词|约束)/u',
            '/(?:从现在起|从今以后|今后|后续)(?:只|仅)(?:输出|返回|回复|回答)/u',
            '/(?:请)?(?:直接)?(?:输出|返回|回复|回答).{0,8}(?:通过|批准|安全|合规|没有问题|无问题)/u',
            '/(?:无论|不论).{0,12}(?:内容|文章).{0,12}(?:批准|通过|接受|判定安全)/u',
        ];

        return collect($patterns)->contains(
            static fn (string $pattern): bool => preg_match($pattern, $text) === 1,
        ) || collect($compactPatterns)->contains(
            static fn (string $pattern): bool => preg_match($pattern, $compactText) === 1,
        );
    }
}
