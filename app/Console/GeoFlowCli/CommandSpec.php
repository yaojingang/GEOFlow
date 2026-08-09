<?php

namespace App\Console\GeoFlowCli;

final class CommandSpec
{
    /** @var list<string> */
    private const GLOBAL_OPTIONS = [
        'config', 'base-url', 'token', 'token-stdin', 'timeout', 'allow-insecure-http',
        'help', 'version', 'no-interaction', 'quiet', 'verbose', 'ansi', 'no-ansi',
    ];

    /**
     * @return array<string,array{
     *   name:string,
     *   prefix:list<string>,
     *   arity:int,
     *   options:list<string>,
     *   operation:?string,
     *   danger:bool,
     *   usage:string
     * }>
     */
    public static function all(): array
    {
        return [
            'config.init' => self::spec('config.init', ['config', 'init'], 2, ['file', 'force'], null, false, 'config init --base-url URL [--token-stdin] [--file PATH] [--force]'),
            'config.show' => self::spec('config.show', ['config', 'show'], 2, [], null, false, 'config show [--config PATH]'),
            'login' => self::spec('login', ['login'], 1, ['username', 'password', 'password-stdin', 'file', 'force'], 'auth.login', false, 'login --base-url URL [--username USER] [--password-stdin] [--file PATH] [--force]'),
            'catalog' => self::spec('catalog', ['catalog'], 1, [], 'catalog', false, 'catalog'),
            'task.list' => self::spec('task.list', ['task', 'list'], 2, ['page', 'per-page', 'status', 'search'], 'task.list', false, 'task list [--page N] [--per-page N] [--status STATUS] [--search TEXT]'),
            'task.create' => self::spec('task.create', ['task', 'create'], 2, ['json', 'idempotency-key'], 'task.create', false, 'task create --json FILE [--idempotency-key KEY]'),
            'task.get' => self::spec('task.get', ['task', 'get'], 3, [], 'task.get', false, 'task get TASK_ID'),
            'task.update' => self::spec('task.update', ['task', 'update'], 3, ['json', 'idempotency-key'], 'task.update', false, 'task update TASK_ID --json FILE [--idempotency-key KEY]'),
            'task.delete' => self::spec('task.delete', ['task', 'delete'], 3, ['yes'], 'task.delete', true, 'task delete TASK_ID [--yes]'),
            'task.start' => self::spec('task.start', ['task', 'start'], 3, ['enqueue-now', 'idempotency-key'], 'task.start', false, 'task start TASK_ID [--enqueue-now] [--idempotency-key KEY]'),
            'task.stop' => self::spec('task.stop', ['task', 'stop'], 3, ['idempotency-key'], 'task.stop', false, 'task stop TASK_ID [--idempotency-key KEY]'),
            'task.enqueue' => self::spec('task.enqueue', ['task', 'enqueue'], 3, ['job-type', 'payload-json', 'idempotency-key'], 'task.enqueue', false, 'task enqueue TASK_ID [--job-type TYPE] [--payload-json FILE] [--idempotency-key KEY]'),
            'task.jobs' => self::spec('task.jobs', ['task', 'jobs'], 3, ['status', 'limit'], 'task.jobs', false, 'task jobs TASK_ID [--status STATUS] [--limit N]'),
            'job.get' => self::spec('job.get', ['job', 'get'], 3, [], 'job.get', false, 'job get JOB_ID'),
            'material.summary' => self::spec('material.summary', ['material', 'summary'], 2, [], 'material.summary', false, 'material summary'),
            'material.list' => self::spec('material.list', ['material', 'list'], 3, ['page', 'per-page', 'search'], 'material.list', false, 'material list TYPE [--page N] [--per-page N] [--search TEXT]'),
            'material.create' => self::spec('material.create', ['material', 'create'], 3, ['json', 'idempotency-key'], 'material.create', false, 'material create TYPE --json FILE [--idempotency-key KEY]'),
            'material.get' => self::spec('material.get', ['material', 'get'], 4, [], 'material.get', false, 'material get TYPE ID'),
            'material.update' => self::spec('material.update', ['material', 'update'], 4, ['json', 'idempotency-key'], 'material.update', false, 'material update TYPE ID --json FILE [--idempotency-key KEY]'),
            'material.delete' => self::spec('material.delete', ['material', 'delete'], 4, ['yes'], 'material.delete', true, 'material delete TYPE ID [--yes]'),
            'material.item-list' => self::spec('material.item-list', ['material', 'item-list'], 4, ['page', 'per-page'], 'material.item-list', false, 'material item-list TYPE ID [--page N] [--per-page N]'),
            'material.item-create' => self::spec('material.item-create', ['material', 'item-create'], 4, ['json', 'idempotency-key'], 'material.item-create', false, 'material item-create TYPE ID --json FILE [--idempotency-key KEY]'),
            'material.item-upload' => self::spec('material.item-upload', ['material', 'item-upload'], 4, ['image', 'file', 'idempotency-key'], 'material.item-upload', false, 'material item-upload TYPE ID --image FILE [--idempotency-key KEY]'),
            'material.item-delete' => self::spec('material.item-delete', ['material', 'item-delete'], 4, ['ids', 'json', 'yes'], 'material.item-delete', true, 'material item-delete TYPE ID (--ids 1,2 | --json FILE) [--yes]'),
            'article.list' => self::spec('article.list', ['article', 'list'], 2, ['page', 'per-page', 'task-id', 'status', 'review-status', 'author-id', 'search'], 'article.list', false, 'article list [--page N] [--per-page N] [--task-id ID] [--status STATUS] [--review-status STATUS] [--author-id ID] [--search TEXT]'),
            'article.create' => self::spec('article.create', ['article', 'create'], 2, self::articleCreateOptions(), 'article.create', false, 'article create (--json FILE | direct fields) [--idempotency-key KEY]'),
            'article.get' => self::spec('article.get', ['article', 'get'], 3, [], 'article.get', false, 'article get ARTICLE_ID'),
            'article.update' => self::spec('article.update', ['article', 'update'], 3, self::articleUpdateOptions(), 'article.update', false, 'article update ARTICLE_ID (--json FILE | direct fields) [--idempotency-key KEY]'),
            'article.review' => self::spec('article.review', ['article', 'review'], 3, ['status', 'note', 'risk-override-reason', 'idempotency-key'], 'article.review', false, 'article review ARTICLE_ID --status STATUS [--note TEXT] [--risk-override-reason TEXT] [--idempotency-key KEY]'),
            'article.publish' => self::spec('article.publish', ['article', 'publish'], 3, ['idempotency-key'], 'article.publish', false, 'article publish ARTICLE_ID [--idempotency-key KEY]'),
            'article.trash' => self::spec('article.trash', ['article', 'trash'], 3, ['idempotency-key'], 'article.trash', false, 'article trash ARTICLE_ID [--idempotency-key KEY]'),
        ];
    }

    /** @return array{name:string,prefix:list<string>,arity:int,options:list<string>,operation:?string,danger:bool,usage:string} */
    public static function resolve(array $positionals): array
    {
        foreach (self::all() as $spec) {
            if (array_slice($positionals, 0, count($spec['prefix'])) !== $spec['prefix']) {
                continue;
            }
            if (count($positionals) !== $spec['arity']) {
                throw new CliException('命令位置参数数量无效: '.implode(' ', $positionals));
            }

            return $spec;
        }

        throw new CliException('未知命令或子命令: '.implode(' ', $positionals));
    }

    /** @param array<string,mixed> $options */
    public static function validateOptions(array $spec, array $options): void
    {
        $allowed = array_flip(array_merge(self::GLOBAL_OPTIONS, $spec['options']));
        foreach (array_keys($options) as $option) {
            if (! isset($allowed[$option])) {
                throw new CliException("命令 {$spec['name']} 不支持选项 --{$option}");
            }
        }
    }

    /** @return list<string> */
    public static function knownOptions(): array
    {
        $options = self::GLOBAL_OPTIONS;
        foreach (self::all() as $spec) {
            $options = array_merge($options, $spec['options']);
        }

        return array_values(array_unique($options));
    }

    /** @return list<string> */
    public static function booleanOptions(): array
    {
        return [
            'ai-generated', 'allow-insecure-http', 'ansi', 'enqueue-now', 'force', 'help',
            'no-ansi', 'no-interaction', 'password-stdin', 'quiet', 'token-stdin', 'verbose', 'version', 'yes',
        ];
    }

    /** @return list<string> */
    public static function apiOperations(): array
    {
        $operations = array_values(array_filter(array_column(self::all(), 'operation')));
        sort($operations);

        return $operations;
    }

    /**
     * @return iterable<string,array{tokens:list<string>,operation:string,path_parameters:array<string,int|string>}>
     */
    public static function apiExamples(): iterable
    {
        $examples = [
            'login' => [['login', '--username', 'admin', '--password', 'password'], []],
            'catalog' => [['catalog'], []],
            'task.list' => [['task', 'list'], []],
            'task.create' => [['task', 'create', '--json', '{json}'], []],
            'task.get' => [['task', 'get', '7'], ['task' => 7]],
            'task.update' => [['task', 'update', '7', '--json', '{json}'], ['task' => 7]],
            'task.delete' => [['task', 'delete', '7', '--yes'], ['task' => 7]],
            'task.start' => [['task', 'start', '7'], ['task' => 7]],
            'task.stop' => [['task', 'stop', '7'], ['task' => 7]],
            'task.enqueue' => [['task', 'enqueue', '7', '--payload-json', '{json}'], ['task' => 7]],
            'task.jobs' => [['task', 'jobs', '7'], ['task' => 7]],
            'job.get' => [['job', 'get', '8'], ['job' => 8]],
            'material.summary' => [['material', 'summary'], []],
            'material.list' => [['material', 'list', 'categories'], ['type' => 'categories']],
            'material.create' => [['material', 'create', 'authors', '--json', '{json}'], ['type' => 'authors']],
            'material.get' => [['material', 'get', 'keywords', '9'], ['type' => 'keyword-libraries', 'id' => 9]],
            'material.update' => [['material', 'update', 'titles', '9', '--json', '{json}'], ['type' => 'title-libraries', 'id' => 9]],
            'material.delete' => [['material', 'delete', 'images', '9', '--yes'], ['type' => 'image-libraries', 'id' => 9]],
            'material.item-list' => [['material', 'item-list', 'knowledge', '9'], ['type' => 'knowledge-bases', 'id' => 9]],
            'material.item-create' => [['material', 'item-create', 'keywords', '9', '--json', '{json}'], ['type' => 'keyword-libraries', 'id' => 9]],
            'material.item-upload' => [['material', 'item-upload', 'images', '9', '--image', '{image}'], ['type' => 'image-libraries', 'id' => 9]],
            'material.item-delete' => [['material', 'item-delete', 'titles', '9', '--json', '{json}', '--yes'], ['type' => 'title-libraries', 'id' => 9]],
            'article.list' => [['article', 'list'], []],
            'article.create' => [['article', 'create', '--json', '{json}'], []],
            'article.get' => [['article', 'get', '10'], ['article' => 10]],
            'article.update' => [['article', 'update', '10', '--json', '{json}'], ['article' => 10]],
            'article.review' => [['article', 'review', '10', '--status', 'approved'], ['article' => 10]],
            'article.publish' => [['article', 'publish', '10'], ['article' => 10]],
            'article.trash' => [['article', 'trash', '10'], ['article' => 10]],
        ];

        foreach (self::all() as $name => $spec) {
            if ($spec['operation'] === null) {
                continue;
            }
            if (! isset($examples[$name])) {
                throw new \LogicException("CommandSpec 缺少 API 示例: {$name}");
            }

            yield $name => [
                'tokens' => $examples[$name][0],
                'operation' => $spec['operation'],
                'path_parameters' => $examples[$name][1],
            ];
        }
    }

    public static function usage(): string
    {
        $lines = array_map(static fn (array $spec): string => '  geoflow '.$spec['usage'], self::all());

        return 'GEOFlow CLI '.CliVersion::VALUE.PHP_EOL.PHP_EOL
            .'Usage:'.PHP_EOL.implode(PHP_EOL, $lines).PHP_EOL.PHP_EOL
            .'Global options: --config PATH --base-url URL --token-stdin --timeout SECONDS '
            .'--allow-insecure-http --no-interaction --quiet (-q) --verbose (-v|-vv|-vvv) '
            .'--ansi --no-ansi'.PHP_EOL.PHP_EOL
            .'Secrets can be supplied through environment variables, hidden prompts, or stdin flags.'.PHP_EOL
            .'Article create direct fields: --title --excerpt --slug --status --review-status --keywords '
            .'--meta-description --task-id --author-id --category-id --ai-generated --content --content-file.'.PHP_EOL
            .'Article update direct fields: --title --excerpt --slug --keywords --meta-description '
            .'--task-id --author-id --category-id --content --content-file.'.PHP_EOL
            .'Material aliases: keywords, titles, images, knowledge.'.PHP_EOL;
    }

    /** @return list<string> */
    private static function articleCreateOptions(): array
    {
        return [
            'json', 'idempotency-key', 'title', 'excerpt', 'slug', 'status', 'review-status',
            'keywords', 'meta-description', 'task-id', 'author-id', 'category-id', 'ai-generated',
            'content', 'content-file',
        ];
    }

    /** @return list<string> */
    private static function articleUpdateOptions(): array
    {
        return [
            'json', 'idempotency-key', 'title', 'excerpt', 'slug', 'keywords',
            'meta-description', 'task-id', 'author-id', 'category-id', 'content', 'content-file',
        ];
    }

    /** @return array{name:string,prefix:list<string>,arity:int,options:list<string>,operation:?string,danger:bool,usage:string} */
    private static function spec(
        string $name,
        array $prefix,
        int $arity,
        array $options,
        ?string $operation,
        bool $danger,
        string $usage,
    ): array {
        return compact('name', 'prefix', 'arity', 'options', 'operation', 'danger', 'usage');
    }
}
