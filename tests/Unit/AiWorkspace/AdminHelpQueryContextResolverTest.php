<?php

namespace Tests\Unit\AiWorkspace;

use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Services\AiWorkspace\AdminHelpQueryContextResolver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminHelpQueryContextResolverTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_short_followups_use_the_previous_user_question_and_source_hints(): void
    {
        $conversation = $this->conversation();
        $this->message($conversation, 'user', '如何创建内容任务？', [], now()->subSeconds(3));
        $this->message($conversation, 'assistant', '请进入任务创建页。', ['knowledge_sources' => [[
            'feature_id' => 'tasks',
            'section_path' => '内容生产与任务 > 任务创建',
        ]]], now()->subSeconds(2));
        $current = $this->message($conversation, 'user', '然后呢？');

        $result = app(AdminHelpQueryContextResolver::class)->resolve($conversation, (string) $current->getKey(), '然后呢？');

        self::assertTrue($result['followup_expanded']);
        self::assertStringContainsString('如何创建内容任务', $result['retrieval_query']);
        self::assertStringContainsString('tasks', $result['retrieval_query']);
        self::assertStringNotContainsString('请进入任务创建页', $result['retrieval_query']);
    }

    public function test_followup_does_not_reuse_sources_from_an_older_unrelated_answer(): void
    {
        $conversation = $this->conversation();
        $this->message($conversation, 'user', '如何创建任务？', [], now()->subMinutes(3));
        $this->message($conversation, 'assistant', '进入任务创建页。', [
            'knowledge_sources' => [[
                'feature_id' => 'tasks',
                'section_path' => '任务管理 > 任务创建',
            ]],
        ], now()->subMinutes(2));
        $this->message($conversation, 'user', '知识库同步为什么失败？', [], now()->subMinute());
        $current = $this->message($conversation, 'user', '然后呢？');

        $result = app(AdminHelpQueryContextResolver::class)->resolve($conversation, (string) $current->getKey(), '然后呢？');

        self::assertTrue($result['followup_expanded']);
        self::assertStringContainsString('知识库同步为什么失败', $result['retrieval_query']);
        self::assertStringNotContainsString('tasks', $result['retrieval_query']);
        self::assertSame([], $result['previous_sources']);
    }

    public function test_followup_query_keeps_the_current_question_when_the_previous_question_is_long(): void
    {
        $conversation = $this->conversation();
        $this->message($conversation, 'user', str_repeat('任务配置说明', 260), [], now()->subMinute());
        $this->message($conversation, 'assistant', '上一轮回答。', [], now()->subSeconds(30));
        $current = $this->message($conversation, 'user', '详细说说发布步骤？');

        $result = app(AdminHelpQueryContextResolver::class)->resolve(
            $conversation,
            (string) $current->getKey(),
            '详细说说发布步骤？',
        );

        self::assertTrue($result['followup_expanded']);
        self::assertStringContainsString('详细说说发布步骤', $result['retrieval_query']);
        self::assertLessThanOrEqual(1200, Str::length($result['retrieval_query']));
    }

    private function conversation(): AiConversation
    {
        return AiConversation::query()->create([
            'id' => (string) Str::uuid(),
            'participant_type' => 'App\\Models\\Admin',
            'participant_id' => 7,
            'title' => '后台帮助',
        ]);
    }

    /** @param array<string, mixed> $meta */
    private function message(
        AiConversation $conversation,
        string $role,
        string $content,
        array $meta = [],
        mixed $createdAt = null,
    ): AiConversationMessage {
        $message = AiConversationMessage::query()->create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->getKey(),
            'role' => $role,
            'content' => $content,
            ...$this->messageDefaults(),
            'meta' => $meta,
        ]);
        if ($createdAt) {
            $message->timestamps = false;
            $message->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();
        }

        return $message;
    }

    /** @return array<string, mixed> */
    private function messageDefaults(): array
    {
        return [
            'agent' => 'admin-help',
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [],
        ];
    }
}
