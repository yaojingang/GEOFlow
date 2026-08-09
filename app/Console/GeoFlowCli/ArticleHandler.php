<?php

namespace App\Console\GeoFlowCli;

final class ArticleHandler
{
    public function __construct(private readonly CommandRuntime $runtime) {}

    public function handle(): int
    {
        $arguments = $this->runtime->context->positionals;
        $action = $arguments[1];
        $articleId = fn (): int => $this->runtime->positiveId($arguments[2] ?? null, '文章 ID');

        return match ($action) {
            'list' => $this->runtime->send('article.list', query: [
                'page' => $this->runtime->integerOption('page', 1),
                'per_page' => $this->runtime->integerOption('per-page', 20),
                'task_id' => $this->runtime->optionalInteger('task-id'),
                'status' => $this->runtime->context->options['status'] ?? null,
                'review_status' => $this->runtime->context->options['review-status'] ?? null,
                'author_id' => $this->runtime->optionalInteger('author-id'),
                'search' => $this->runtime->context->options['search'] ?? null,
            ]),
            'create' => $this->runtime->send('article.create', body: $this->body(true), idempotencyKey: $this->runtime->idempotencyKey()),
            'get' => $this->runtime->send('article.get', ['article' => $articleId()]),
            'update' => $this->runtime->send('article.update', ['article' => $articleId()], body: $this->body(false), idempotencyKey: $this->runtime->idempotencyKey()),
            'review' => $this->runtime->send('article.review', ['article' => $articleId()], body: [
                'review_status' => $this->runtime->requiredOption('status'),
                'review_note' => trim((string) ($this->runtime->context->options['note'] ?? '')),
                'risk_override_reason' => trim((string) ($this->runtime->context->options['risk-override-reason'] ?? '')),
            ], idempotencyKey: $this->runtime->idempotencyKey()),
            'publish' => $this->runtime->send('article.publish', ['article' => $articleId()], body: [], idempotencyKey: $this->runtime->idempotencyKey()),
            'trash' => $this->runtime->send('article.trash', ['article' => $articleId()], body: [], idempotencyKey: $this->runtime->idempotencyKey()),
        };
    }

    /** @return array<string,mixed> */
    private function body(bool $creating): array
    {
        $options = $this->runtime->context->options;
        if (isset($options['json'])) {
            return $this->runtime->jsonBody();
        }

        $body = [];
        foreach ([
            'title' => 'title',
            'excerpt' => 'excerpt',
            'slug' => 'slug',
            'status' => 'status',
            'review-status' => 'review_status',
            'keywords' => 'keywords',
            'meta-description' => 'meta_description',
        ] as $option => $field) {
            if (array_key_exists($option, $options)) {
                $body[$field] = (string) $options[$option];
            }
        }

        foreach (['task-id' => 'task_id', 'author-id' => 'author_id', 'category-id' => 'category_id'] as $option => $field) {
            $value = $this->runtime->optionalInteger($option);
            if ($value !== null) {
                $body[$field] = $value;
            }
        }
        if (array_key_exists('ai-generated', $options)) {
            $body['is_ai_generated'] = $this->runtime->flag('ai-generated') ? 1 : 0;
        }
        if (isset($options['content']) && isset($options['content-file'])) {
            throw new CliException('--content 和 --content-file 不能同时使用');
        }
        if (isset($options['content'])) {
            if (strlen((string) $options['content']) > CommandRuntime::MAX_INPUT_BYTES) {
                throw new CliException('文章正文超过 5 MiB 安全上限');
            }
            $body['content'] = (string) $options['content'];
        } elseif (isset($options['content-file'])) {
            $body['content'] = $this->runtime->loadText((string) $options['content-file']);
        }

        if ($creating && (trim((string) ($body['title'] ?? '')) === '' || trim((string) ($body['content'] ?? '')) === '')) {
            throw new CliException('创建文章必须提供 title 和 content，可使用 --json 或标题/正文参数');
        }
        if (! $creating && $body === []) {
            throw new CliException('没有可更新的字段');
        }

        return $body;
    }
}
