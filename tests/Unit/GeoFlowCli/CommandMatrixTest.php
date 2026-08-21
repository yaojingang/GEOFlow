<?php

namespace Tests\Unit\GeoFlowCli;

use App\Console\GeoFlowCli\CommandDispatcher;
use App\Console\GeoFlowCli\CommandSpec;
use App\Console\GeoFlowCli\ConfigurationRepository;
use App\Console\GeoFlowCli\OperationRegistry;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandMatrixTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/geoflow-matrix-test-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/home', 0700, true);
        mkdir($this->root.'/cwd', 0700, true);
        file_put_contents($this->root.'/payload.json', '{"name":"sample","ids":[3]}');
        file_put_contents($this->root.'/image.png', 'image bytes');
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('apiCommands')]
    public function every_public_api_command_matches_its_independent_http_contract(array $contract): void
    {
        $tokens = array_map(fn (string $token): string => strtr($token, [
            '{json}' => $this->root.'/payload.json',
            '{image}' => $this->root.'/image.png',
        ]), $contract['tokens']);
        $tokens = array_merge($tokens, ['--base-url', 'https://api.example.com']);
        if ($contract['auth']) {
            $tokens = array_merge($tokens, ['--token', 'secret-token']);
        }
        if ($contract['idempotency_key'] !== null) {
            $tokens = array_merge($tokens, ['--idempotency-key', $contract['idempotency_key']]);
        }

        $factory = new HttpFactory;
        $factory->preventStrayRequests();
        $factory->fake(fn () => $factory->response($contract['auth'] ? [
            'success' => true,
        ] : [
            'success' => true,
            'data' => ['token' => 'new-secret-token', 'admin' => ['id' => 1]],
        ], 200));
        $dispatcher = new CommandDispatcher(
            $factory,
            new ConfigurationRepository($this->root.'/cwd', $this->root.'/home'),
        );
        $input = new ArgvInput(array_merge(['geoflow'], $tokens));
        $input->setInteractive(false);

        $status = $dispatcher->dispatch($tokens, $input, new BufferedOutput, new BufferedOutput);

        $this->assertSame(0, $status);
        $this->assertCount(1, $factory->recorded());
        $request = $factory->recorded()[0][0];
        $this->assertSame($contract['method'], $request->method());
        $this->assertSame('https://api.example.com/api/v1/'.$contract['target'], $request->url());
        $this->assertTrue($request->hasHeader('Accept', 'application/json'));
        $this->assertSame(
            $contract['auth'],
            $request->hasHeader('Authorization', 'Bearer secret-token'),
        );
        if ($contract['idempotency_key'] === null) {
            $this->assertFalse($request->hasHeader('X-Idempotency-Key'));
        } else {
            $this->assertTrue($request->hasHeader('X-Idempotency-Key', $contract['idempotency_key']));
        }

        if ($contract['multipart']) {
            $this->assertTrue($request->isMultipart());
            $this->assertTrue($request->hasFile('image', filename: 'image.png'));
        } else {
            $this->assertFalse($request->isMultipart());
        }
        if ($contract['body'] !== null) {
            $this->assertSame($contract['body'], $request->data());
        }
    }

    #[Test]
    public function literal_matrix_and_command_examples_are_bidirectionally_complete(): void
    {
        $contractNames = array_keys(self::contracts());
        $exampleNames = array_keys(iterator_to_array(CommandSpec::apiExamples()));
        sort($contractNames);
        sort($exampleNames);

        $this->assertSame($exampleNames, $contractNames);
    }

    #[Test]
    public function public_contract_keeps_29_operations_on_28_routes(): void
    {
        $this->assertCount(29, self::contracts());
        $this->assertCount(29, OperationRegistry::all());
        $this->assertCount(28, OperationRegistry::routeSignatures());
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function apiCommands(): iterable
    {
        foreach (self::contracts() as $name => $contract) {
            yield $name => [$contract];
        }
    }

    /**
     * Independent public contract oracle. Expected methods, targets, queries, bodies, and headers
     * must stay literal here; deriving them from OperationRegistry would make this test self-proving.
     *
     * @return array<string,array{
     *   tokens:list<string>,
     *   method:string,
     *   target:string,
     *   auth:bool,
     *   idempotency_key:?string,
     *   body:?array<string,mixed>,
     *   multipart:bool
     * }>
     */
    private static function contracts(): array
    {
        return [
            'login' => self::contract(
                ['login', '--username', 'admin', '--password', 'password'],
                'POST',
                'auth/login',
                false,
                body: ['username' => 'admin', 'password' => 'password'],
            ),
            'catalog' => self::contract(['catalog'], 'GET', 'catalog'),
            'task.list' => self::contract(['task', 'list'], 'GET', 'tasks?page=1&per_page=20'),
            'task.create' => self::contract(
                ['task', 'create', '--json', '{json}'],
                'POST',
                'tasks',
                idempotencyKey: 'task-create-1',
                body: ['name' => 'sample', 'ids' => [3]],
            ),
            'task.get' => self::contract(['task', 'get', '7'], 'GET', 'tasks/7'),
            'task.update' => self::contract(
                ['task', 'update', '7', '--json', '{json}'],
                'PATCH',
                'tasks/7',
                idempotencyKey: 'task-update-7',
                body: ['name' => 'sample', 'ids' => [3]],
            ),
            'task.delete' => self::contract(
                ['task', 'delete', '7', '--yes'],
                'DELETE',
                'tasks/7',
                body: [],
            ),
            'task.start' => self::contract(
                ['task', 'start', '7'],
                'POST',
                'tasks/7/start',
                idempotencyKey: 'task-start-7',
                body: ['enqueue_now' => false],
            ),
            'task.stop' => self::contract(
                ['task', 'stop', '7'],
                'POST',
                'tasks/7/stop',
                idempotencyKey: 'task-stop-7',
                body: [],
            ),
            'task.enqueue' => self::contract(
                ['task', 'enqueue', '7', '--payload-json', '{json}'],
                'POST',
                'tasks/7/enqueue',
                idempotencyKey: 'task-enqueue-7',
                body: ['name' => 'sample', 'ids' => [3]],
            ),
            'task.jobs' => self::contract(['task', 'jobs', '7'], 'GET', 'tasks/7/jobs?limit=20'),
            'job.get' => self::contract(['job', 'get', '8'], 'GET', 'jobs/8'),
            'material.summary' => self::contract(['material', 'summary'], 'GET', 'materials'),
            'material.list' => self::contract(
                ['material', 'list', 'categories'],
                'GET',
                'materials/categories?page=1&per_page=20',
            ),
            'material.create' => self::contract(
                ['material', 'create', 'authors', '--json', '{json}'],
                'POST',
                'materials/authors',
                idempotencyKey: 'material-create-authors',
                body: ['name' => 'sample', 'ids' => [3]],
            ),
            'material.get' => self::contract(
                ['material', 'get', 'keywords', '9'],
                'GET',
                'materials/keyword-libraries/9',
            ),
            'material.update' => self::contract(
                ['material', 'update', 'titles', '9', '--json', '{json}'],
                'PATCH',
                'materials/title-libraries/9',
                idempotencyKey: 'material-update-9',
                body: ['name' => 'sample', 'ids' => [3]],
            ),
            'material.delete' => self::contract(
                ['material', 'delete', 'images', '9', '--yes'],
                'DELETE',
                'materials/image-libraries/9',
                body: [],
            ),
            'material.item-list' => self::contract(
                ['material', 'item-list', 'knowledge', '9'],
                'GET',
                'materials/knowledge-bases/9/items?page=1&per_page=20',
            ),
            'material.item-create' => self::contract(
                ['material', 'item-create', 'keywords', '9', '--json', '{json}'],
                'POST',
                'materials/keyword-libraries/9/items',
                idempotencyKey: 'material-item-create-9',
                body: ['name' => 'sample', 'ids' => [3]],
            ),
            'material.item-upload' => self::contract(
                ['material', 'item-upload', 'images', '9', '--image', '{image}'],
                'POST',
                'materials/image-libraries/9/items',
                idempotencyKey: 'material-item-upload-9',
                multipart: true,
            ),
            'material.item-delete' => self::contract(
                ['material', 'item-delete', 'titles', '9', '--json', '{json}', '--yes'],
                'DELETE',
                'materials/title-libraries/9/items',
                body: ['name' => 'sample', 'ids' => [3]],
            ),
            'article.list' => self::contract(['article', 'list'], 'GET', 'articles?page=1&per_page=20'),
            'article.create' => self::contract(
                ['article', 'create', '--json', '{json}'],
                'POST',
                'articles',
                idempotencyKey: 'article-create-1',
                body: ['name' => 'sample', 'ids' => [3]],
            ),
            'article.get' => self::contract(['article', 'get', '10'], 'GET', 'articles/10'),
            'article.update' => self::contract(
                ['article', 'update', '10', '--json', '{json}'],
                'PATCH',
                'articles/10',
                idempotencyKey: 'article-update-10',
                body: ['name' => 'sample', 'ids' => [3]],
            ),
            'article.review' => self::contract(
                ['article', 'review', '10', '--status', 'approved'],
                'POST',
                'articles/10/review',
                idempotencyKey: 'article-review-10',
                body: [
                    'review_status' => 'approved',
                    'review_note' => '',
                    'risk_override_reason' => '',
                ],
            ),
            'article.publish' => self::contract(
                ['article', 'publish', '10'],
                'POST',
                'articles/10/publish',
                idempotencyKey: 'article-publish-10',
                body: [],
            ),
            'article.trash' => self::contract(
                ['article', 'trash', '10'],
                'POST',
                'articles/10/trash',
                idempotencyKey: 'article-trash-10',
                body: [],
            ),
        ];
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string,mixed>|null  $body
     * @return array{
     *   tokens:list<string>,
     *   method:string,
     *   target:string,
     *   auth:bool,
     *   idempotency_key:?string,
     *   body:?array<string,mixed>,
     *   multipart:bool
     * }
     */
    private static function contract(
        array $tokens,
        string $method,
        string $target,
        bool $auth = true,
        ?string $idempotencyKey = null,
        ?array $body = null,
        bool $multipart = false,
    ): array {
        return [
            'tokens' => $tokens,
            'method' => $method,
            'target' => $target,
            'auth' => $auth,
            'idempotency_key' => $idempotencyKey,
            'body' => $body,
            'multipart' => $multipart,
        ];
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path.'/'.$entry;
            is_dir($child) ? $this->deleteTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
