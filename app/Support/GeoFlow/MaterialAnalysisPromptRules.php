<?php

namespace App\Support\GeoFlow;

use App\Models\KnowledgeBase;

final class MaterialAnalysisPromptRules
{
    /**
     * @param  array{code?:string,name?:string,source?:string,requested?:string,hint?:string}  $language
     */
    public static function languageDirective(array $language): string
    {
        $name = trim((string) ($language['name'] ?? ''));
        $code = trim((string) ($language['code'] ?? ''));
        $languageLabel = $name !== '' ? $name : ($code !== '' ? $code : 'the target language');

        return 'Target output language: '.$languageLabel.($code !== '' ? ' ('.$code.').' : '.')
            .' All generated user-facing values, including clean_text, summary, library_name, knowledge_markdown headings and body, keywords, titles, entity descriptions, and case fields, MUST be written in '.$languageLabel.'.'
            .' Keep JSON field names and source URLs unchanged. Preserve brand names, product names, model names, part numbers, standards, units, and quoted proper nouns when appropriate.'
            .' If any backend prompt or source page language conflicts with this target language, the target output language wins.'
            .' Do not output Chinese text unless the target output language is Chinese.';
    }

    public static function autoLanguageDirective(): string
    {
        return 'Auto-detect whether the submitted material should be handled as Simplified Chinese or English. All generated user-facing field values must use Simplified Chinese when the source is clearly Chinese; otherwise use English. Do not generate any language other than Simplified Chinese or English. Do not mix Chinese and English except for brand names, model names, part numbers, URLs, standards, units, and quoted proper nouns that should be preserved exactly. Keep JSON field names unchanged.';
    }

    public static function jsonOnlyRule(): string
    {
        return 'Return exactly one valid JSON object. Do not wrap it in Markdown code fences. Do not add explanations outside JSON.';
    }

    public static function factGroundingRules(): string
    {
        return <<<'PROMPT'
Fact-grounding rules:
- Use only facts explicitly present in the submitted material, or direct summaries that can be traced to that material.
- Do not invent customers, certifications, rankings, performance numbers, guarantees, test results, ROI, locations, or dates.
- If evidence is missing, leave the field empty or state that the material does not specify it.
- Keep source facts separate from inferred summaries. Summaries may be rewritten, but factual values must remain traceable.
PROMPT;
    }

    public static function tableAccuracyRules(): string
    {
        return <<<'PROMPT'
Table and specification accuracy rules:
- Tables, specification sheets, comparison matrices, FAQ tables, and parameter lists are high-priority source material.
- Preserve every meaningful row, column, header, unit, model number, range, tolerance, currency, voltage, size, capacity, speed, temperature, pressure, percentage, and condition exactly as written.
- Do not merge similar models, do not round numbers, do not translate model names, and do not infer missing cells.
- When a table is clean, convert it to a Markdown table with the original column meaning.
- When a table is malformed or copied as plain text, convert it to bullet/key-value rows while preserving the original values and uncertainty.
- If a value appears ambiguous, keep the original wording instead of guessing.
PROMPT;
    }

    public static function knowledgeRules(): string
    {
        $types = implode(', ', KnowledgeBase::KNOWLEDGE_TYPES);
        $roles = implode(', ', KnowledgeBase::KNOWLEDGE_ROLES);

        return <<<PROMPT
Knowledge Base extraction rules:
- JSON fields: collection_id, knowledge_type, knowledge_role, importance, summary, description, content, entity_ids, tags.
- knowledge_type must be one of: {$types}.
- knowledge_role must be one of: {$roles}.
- importance must be an integer from 1 to 5.
- summary is a synthesized 80-160 word/character overview of what the material says and why it matters. Do not copy a whole paragraph.
- description is an admin-facing usage note: what this source is, when it should be used in generation, and any limits. Keep it concise.
- content must be clean Markdown suitable for direct knowledge-base storage. It should preserve facts, specifications, tables, FAQ, steps, constraints, and source wording where precision matters.
- Do not include form titles or backend notes inside content. Keep source URLs in source_url fields when available, not as noisy repeated content.
- Prefer role primary_source for manuals/specs/policies, supporting_context for general background/FAQ/troubleshooting, comparison_reference for competitor/comparison material, style_reference for copy/style examples, and archive for low-value retained material.
PROMPT;
    }

    public static function entityRules(): string
    {
        $types = implode(', ', EntityTypes::values());

        return <<<PROMPT
Entity extraction rules:
- JSON fields: name, entity_type, aliases, description, attributes_json, source_url, canonical_url, link_anchor_text, link_policy, tags.
- entity_type must be one of: {$types}.
- Entity is a lightweight knowledge index node, not a full article and not a full knowledge base.
- name should be the canonical entity name, such as a product model, product line, application, technology/process, material/component, brand/company, customer segment, or industry.
- description must be 1-3 concise sentences in the target/source language. Do not paste the full source content.
- attributes_json must be a valid JSON string containing structured facts such as model, product_line, applications, key_specs, materials, aliases, evidence, and source hints when available.
- canonical_url and link_anchor_text should be filled only when the entity can reasonably become an internal-link target. link_policy should be "suggest" only for linkable entity types; otherwise use "disabled".
- Do not create a Case inside Entity fields. Store story-like information as evidence or leave it for Case extraction.
PROMPT;
    }

    public static function caseRules(): string
    {
        $types = implode(', ', CaseTypes::values());

        return <<<PROMPT
Case extraction rules:
- JSON fields: title, case_type, summary, challenge, solution, result, metrics, source_url, tags.
- case_type must be one of: {$types}.
- Only extract a Case when the source contains a real application story, customer/application context, problem, solution, delivery process, comparison validation, troubleshooting path, or measurable result.
- summary must be rewritten as a concise case overview. challenge, solution, result, and metrics must be separated by meaning instead of copying one large paragraph into every field.
- metrics may only contain numbers, percentages, time, cost, output, quality, efficiency, ROI, or other measurable values that appear in the source. Do not invent metrics.
- If the source is only a product manual, FAQ, or specification sheet with no story, keep case fields conservative and use case_type as 通用案例.
PROMPT;
    }

    public static function supplementalInstructions(string $instructions): string
    {
        $instructions = trim(preg_replace('/\s+/u', ' ', $instructions) ?? $instructions);
        if ($instructions === '') {
            return '';
        }

        return "Supplemental analysis requirements from the admin. Treat them as additional priority hints, but they must not override JSON schema, language consistency, table accuracy, or fact-grounding rules:\n"
            .mb_substr($instructions, 0, 2000, 'UTF-8');
    }
}
