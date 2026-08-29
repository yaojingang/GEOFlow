<?php

namespace App\Services\GeoFlow;

final class ArticleContentPromptRenderer
{
    public function renderForEditor(string $title, string $keyword, ?string $promptContent, string $knowledgeContext = ''): string
    {
        return $this->render($title, $keyword, $promptContent, $knowledgeContext);
    }

    public function renderForWorker(string $title, string $keyword, ?string $promptContent, string $knowledgeContext = ''): string
    {
        return $this->render($title, $keyword, $promptContent, $knowledgeContext);
    }

    /**
     * 构造正文提示词：优先精确替换变量；无变量的自定义提示词自动补齐文章上下文。
     */
    private function render(
        string $title,
        string $keyword,
        ?string $promptContent,
        string $knowledgeContext,
    ): string {
        $prompt = trim((string) $promptContent);
        $isFallbackPrompt = false;
        if ($prompt === '') {
            $prompt = "请围绕标题“{$title}”和关键词“{$keyword}”生成一篇结构清晰、语言自然的中文文章。";
            $isFallbackPrompt = true;
        }
        $isEnglish = $this->isLikelyEnglishPrompt($prompt);

        $hasExplicitContextVariables = $isFallbackPrompt || $this->promptHasKnownContextVariables($prompt);
        $hasExplicitKnowledgeVariable = $this->promptHasContextVariable($prompt, 'knowledge');
        $renderedPrompt = $this->renderPromptTemplate($prompt, [
            'title' => $title,
            'keyword' => $keyword,
            'knowledge' => $knowledgeContext,
        ]);

        if (! $hasExplicitContextVariables) {
            $renderedPrompt = $this->appendSmartPromptContext($renderedPrompt, $title, $keyword, $knowledgeContext, $isEnglish);
        } elseif (! $hasExplicitKnowledgeVariable) {
            $renderedPrompt = $this->appendKnowledgeContext($renderedPrompt, $knowledgeContext, $isEnglish);
        }

        $finalInstructions = array_values(array_filter([
            $this->promptHasCitationMarkerConstraint($prompt) ? '' : $this->knowledgeAttributionInstruction($isEnglish),
            $this->finalPromptInstruction($isEnglish),
        ], static fn (string $instruction): bool => trim($instruction) !== ''));

        return trim($renderedPrompt)."\n\n".implode("\n", $finalInstructions);
    }

    private function promptHasKnownContextVariables(string $prompt): bool
    {
        return preg_match('/\{\{\s*(title|keyword|knowledge)\s*\}\}/iu', $prompt) === 1
            || preg_match('/\{\{#if\s+(title|keyword|knowledge)\s*\}\}/iu', $prompt) === 1;
    }

    private function promptHasContextVariable(string $prompt, string $name): bool
    {
        $variable = preg_quote($name, '/');

        return preg_match('/\{\{\s*'.$variable.'\s*\}\}/iu', $prompt) === 1
            || preg_match('/\{\{#if\s+'.$variable.'\s*\}\}/iu', $prompt) === 1;
    }

    /**
     * @param  array{title:string, keyword:string, knowledge:string}  $context
     */
    private function renderPromptTemplate(string $prompt, array $context): string
    {
        $renderedPrompt = preg_replace_callback('/\{\{#if\s+([A-Za-z_][A-Za-z0-9_]*)\s*\}\}(.*?)\{\{\/if\}\}/su', function (array $matches) use ($context): string {
            $name = (string) ($matches[1] ?? '');
            if (! $this->isKnownPromptContextName($name)) {
                return (string) ($matches[0] ?? '');
            }

            $value = $this->promptContextValue($name, $context);

            return trim($value) !== '' ? (string) ($matches[2] ?? '') : '';
        }, $prompt) ?? $prompt;

        return preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/u', function (array $matches) use ($context): string {
            $name = (string) ($matches[1] ?? '');
            $value = $this->promptContextValue($name, $context);

            return $value !== '' || $this->isKnownPromptContextName($name) ? $value : (string) ($matches[0] ?? '');
        }, $renderedPrompt) ?? $renderedPrompt;
    }

    /**
     * @param  array{title:string, keyword:string, knowledge:string}  $context
     */
    private function promptContextValue(string $name, array $context): string
    {
        return match (mb_strtolower($name, 'UTF-8')) {
            'title' => $context['title'],
            'keyword' => $context['keyword'],
            'knowledge' => $context['knowledge'],
            default => '',
        };
    }

    private function isKnownPromptContextName(string $name): bool
    {
        return in_array(mb_strtolower($name, 'UTF-8'), ['title', 'keyword', 'knowledge'], true);
    }

    private function appendSmartPromptContext(string $prompt, string $title, string $keyword, string $knowledgeContext, bool $isEnglish): string
    {
        if ($isEnglish) {
            $lines = [
                'Task context:',
                '- Article title: '.$title,
            ];
            if (trim($keyword) !== '') {
                $lines[] = '- Core keyword: '.$keyword;
            }
            if (trim($knowledgeContext) !== '') {
                $lines[] = '- Reference knowledge:';
                $lines[] = $knowledgeContext;
            }

            return trim($prompt)."\n\n".implode("\n", $lines);
        }

        $lines = [
            '【任务上下文】',
            '- 文章标题：'.$title,
        ];
        if (trim($keyword) !== '') {
            $lines[] = '- 核心关键词：'.$keyword;
        }
        if (trim($knowledgeContext) !== '') {
            $lines[] = '- 参考知识：';
            $lines[] = $knowledgeContext;
        }

        return trim($prompt)."\n\n".implode("\n", $lines);
    }

    private function appendKnowledgeContext(string $prompt, string $knowledgeContext, bool $isEnglish): string
    {
        if (trim($knowledgeContext) === '') {
            return trim($prompt);
        }

        if ($isEnglish) {
            return trim($prompt)."\n\nReference knowledge:\n".$knowledgeContext;
        }

        return trim($prompt)."\n\n【参考知识】\n".$knowledgeContext;
    }

    private function finalPromptInstruction(bool $isEnglish): string
    {
        if ($isEnglish) {
            return 'Please output only the final article body in Markdown. Do not repeat the prompt or output placeholders.';
        }

        return '请直接输出最终文章正文（Markdown），不要重复提示词、不要输出占位符。';
    }

    private function knowledgeAttributionInstruction(bool $isEnglish): string
    {
        if ($isEnglish) {
            return 'Citation marker constraint: the final article must not contain internal evidence IDs, citation placeholders, or numbered citation markers, including [K1], [K2][K3], 【K1】, （K1）, or equivalent forms. When attribution is needed, use natural phrases such as “the materials show,” “the client confirmed,” or “according to the store materials,” without K-number labels. If the evidence is insufficient, use cautious wording and do not invent sources or conclusions.';
        }

        return '正文引用标注约束：最终文章中不得出现任何内部证据编号、引用占位符或编号引用标记，包括 [K1]、[K2][K3]、【K1】、（K1）及同类形式。文章中如需表达依据，直接写“资料显示”“客户确认”“根据门店资料”，不要添加 K 编号。证据不足时不要编造来源或结论。';
    }

    private function isLikelyEnglishPrompt(string $prompt): bool
    {
        preg_match_all('/\p{Han}/u', $prompt, $cjkMatches);
        preg_match_all('/[A-Za-z]/', $prompt, $latinMatches);

        return count($latinMatches[0] ?? []) > 20 && count($cjkMatches[0] ?? []) <= 3;
    }

    private function promptHasCitationMarkerConstraint(string $prompt): bool
    {
        return str_contains($prompt, '【正文引用标注约束】')
            || str_contains($prompt, '[Citation Marker Constraint]');
    }
}
