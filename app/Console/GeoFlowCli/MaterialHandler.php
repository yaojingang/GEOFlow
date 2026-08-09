<?php

namespace App\Console\GeoFlowCli;

final class MaterialHandler
{
    /** @var array<string,string> */
    private const TYPE_ALIASES = [
        'categories' => 'categories',
        'authors' => 'authors',
        'keyword-libraries' => 'keyword-libraries',
        'keywords' => 'keyword-libraries',
        'title-libraries' => 'title-libraries',
        'titles' => 'title-libraries',
        'image-libraries' => 'image-libraries',
        'images' => 'image-libraries',
        'knowledge-bases' => 'knowledge-bases',
        'knowledge' => 'knowledge-bases',
    ];

    public function __construct(private readonly CommandRuntime $runtime) {}

    public function handle(): int
    {
        $arguments = $this->runtime->context->positionals;
        $action = $arguments[1];
        if ($action === 'summary') {
            return $this->runtime->send('material.summary');
        }

        $type = $this->materialType($arguments[2] ?? null);
        $id = fn (): int => $this->runtime->positiveId($arguments[3] ?? null, '素材库 ID');

        if (in_array($action, ['item-list', 'item-create', 'item-upload', 'item-delete'], true)) {
            $this->assertItemType($type, $action === 'item-list');
        }
        if ($action === 'delete') {
            $materialId = $id();
            $this->runtime->confirmDeletion("素材库 {$type}/{$materialId}");

            return $this->runtime->send('material.delete', ['type' => $type, 'id' => $materialId]);
        }
        if ($action === 'item-delete') {
            $materialId = $id();
            $body = $this->itemDeleteBody();
            $this->runtime->confirmDeletion("素材条目 {$type}/{$materialId}");

            return $this->runtime->send('material.item-delete', ['type' => $type, 'id' => $materialId], body: $body);
        }
        if ($action === 'item-upload') {
            return $this->upload($type, $id());
        }

        return match ($action) {
            'list' => $this->runtime->send('material.list', ['type' => $type], query: [
                'page' => $this->runtime->integerOption('page', 1),
                'per_page' => $this->runtime->integerOption('per-page', 20),
                'search' => $this->runtime->context->options['search'] ?? null,
            ]),
            'create' => $this->runtime->send('material.create', ['type' => $type], body: $this->runtime->jsonBody(), idempotencyKey: $this->runtime->idempotencyKey()),
            'get' => $this->runtime->send('material.get', ['type' => $type, 'id' => $id()]),
            'update' => $this->runtime->send('material.update', ['type' => $type, 'id' => $id()], body: $this->runtime->jsonBody(), idempotencyKey: $this->runtime->idempotencyKey()),
            'item-list' => $this->runtime->send('material.item-list', ['type' => $type, 'id' => $id()], query: [
                'page' => $this->runtime->integerOption('page', 1),
                'per_page' => $this->runtime->integerOption('per-page', 20),
            ]),
            'item-create' => $this->runtime->send('material.item-create', ['type' => $type, 'id' => $id()], body: $this->runtime->jsonBody(), idempotencyKey: $this->runtime->idempotencyKey()),
        };
    }

    private function upload(string $type, int $id): int
    {
        if ($type !== 'image-libraries') {
            throw new CliException('item-upload 仅支持 image-libraries 或 images');
        }
        $imagePath = trim((string) (
            $this->runtime->context->options['image']
            ?? $this->runtime->context->options['file']
            ?? ''
        ));
        if ($imagePath === '') {
            throw new CliException('缺少必填参数 --image');
        }
        $path = $this->runtime->configuration->expandPath($imagePath);
        if (! is_file($path) || ! is_readable($path)) {
            throw new CliException("图片文件不存在或不可读: {$path}");
        }

        return $this->runtime->send(
            'material.item-upload',
            ['type' => $type, 'id' => $id],
            idempotencyKey: $this->runtime->idempotencyKey(),
            uploadPath: $path,
        );
    }

    /** @return array{ids:list<int>}|array<string,mixed> */
    private function itemDeleteBody(): array
    {
        $options = $this->runtime->context->options;
        $hasIds = array_key_exists('ids', $options);
        $hasJson = array_key_exists('json', $options);
        if ($hasIds && $hasJson) {
            throw new CliException('--ids 和 --json 不能同时使用');
        }
        if ($hasJson) {
            return $this->runtime->jsonBody();
        }
        if (! $hasIds) {
            throw new CliException('item-delete 必须提供 --ids 1,2 或 --json FILE');
        }

        $ids = [];
        foreach (explode(',', (string) $options['ids']) as $rawId) {
            $rawId = trim($rawId);
            if ($rawId === '' || ! ctype_digit($rawId) || (int) $rawId <= 0) {
                throw new CliException('--ids 仅接受逗号分隔的正整数');
            }
            $ids[(int) $rawId] = (int) $rawId;
        }

        return ['ids' => array_values($ids)];
    }

    private function materialType(?string $type): string
    {
        $type = str_replace('_', '-', trim((string) $type));
        $normalized = self::TYPE_ALIASES[$type] ?? null;
        if ($normalized === null) {
            throw new CliException('不支持的素材类型，支持 categories/authors/keyword-libraries/title-libraries/image-libraries/knowledge-bases');
        }

        return $normalized;
    }

    private function assertItemType(string $type, bool $readOnlyOperation): void
    {
        if (! in_array($type, ['keyword-libraries', 'title-libraries', 'image-libraries', 'knowledge-bases'], true)) {
            throw new CliException("{$type} 没有条目接口");
        }
        if (! $readOnlyOperation && $type === 'knowledge-bases') {
            throw new CliException('知识库切块为只读数据，由知识库正文自动生成');
        }
    }
}
