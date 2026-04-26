<?php

namespace Tests\Unit;

use App\Services\GeoFlow\WorkerExecutionService;
use ReflectionMethod;
use Tests\TestCase;

class WorkerExecutionServicePromptTest extends TestCase
{
    public function test_custom_prompt_without_variables_receives_smart_context(): void
    {
        $prompt = $this->renderContentPrompt(
            'AI CRM 到底是什么？',
            'AI CRM',
            '请写一篇专业、可信、适合 GEO 引用的文章。',
            '这是来自知识库的参考资料。'
        );

        $this->assertStringContainsString('请写一篇专业、可信、适合 GEO 引用的文章。', $prompt);
        $this->assertStringContainsString('【任务上下文】', $prompt);
        $this->assertStringContainsString('- 文章标题：AI CRM 到底是什么？', $prompt);
        $this->assertStringContainsString('- 核心关键词：AI CRM', $prompt);
        $this->assertStringContainsString('这是来自知识库的参考资料。', $prompt);
    }

    public function test_prompt_with_variables_keeps_precise_rendering_without_extra_context(): void
    {
        $prompt = $this->renderContentPrompt(
            'AI CRM 到底是什么？',
            'AI CRM',
            '标题：{{title}}'."\n".'{{#if keyword}}关键词：{{keyword}}{{/if}}'."\n".'{{#if Knowledge}}知识：{{Knowledge}}{{/if}}',
            '这是来自知识库的参考资料。'
        );

        $this->assertStringContainsString('标题：AI CRM 到底是什么？', $prompt);
        $this->assertStringContainsString('关键词：AI CRM', $prompt);
        $this->assertStringContainsString('知识：这是来自知识库的参考资料。', $prompt);
        $this->assertStringNotContainsString('【任务上下文】', $prompt);
    }

    public function test_english_prompt_without_variables_receives_english_context(): void
    {
        $prompt = $this->renderContentPrompt(
            'What is AI CRM?',
            'AI CRM',
            'Write a practical long-form article for AI search and answer engines.',
            'Reference knowledge from the business knowledge base.'
        );

        $this->assertStringContainsString('Task context:', $prompt);
        $this->assertStringContainsString('- Article title: What is AI CRM?', $prompt);
        $this->assertStringContainsString('- Core keyword: AI CRM', $prompt);
        $this->assertStringContainsString('Reference knowledge from the business knowledge base.', $prompt);
        $this->assertStringContainsString('Please output only the final article body in Markdown.', $prompt);
    }

    public function test_unknown_template_blocks_are_preserved_for_future_extensions(): void
    {
        $prompt = $this->renderContentPrompt(
            'AI CRM 到底是什么？',
            'AI CRM',
            '{{#if custom_context}}自定义上下文：{{custom_context}}{{/if}}'."\n".'标题：{{title}}',
            ''
        );

        $this->assertStringContainsString('{{#if custom_context}}自定义上下文：{{custom_context}}{{/if}}', $prompt);
        $this->assertStringContainsString('标题：AI CRM 到底是什么？', $prompt);
    }

    private function renderContentPrompt(string $title, string $keyword, ?string $promptContent, string $knowledgeContext): string
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'buildContentPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $title, $keyword, $promptContent, $knowledgeContext);
    }
}
