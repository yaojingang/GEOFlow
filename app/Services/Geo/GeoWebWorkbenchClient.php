<?php

namespace App\Services\Geo;

use App\Models\BrandProfile;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class GeoWebWorkbenchClient
{
    public const PLATFORM_CODE = 'ai_web_workbench';

    public const PLATFORM_NAME = '本机多平台AI搜索工作台';

    public function ask(BrandProfile $brandProfile, string $question, string $diagnosticPrompt = '', array $platformIds = []): string
    {
        $searchQuestion = $this->searchQuestion($question, $diagnosticPrompt);
        $command = $this->command();
        $timeoutSeconds = max(30, (int) config('geoflow.ai_web_workbench.timeout_seconds', 420));
        $this->extendPhpRuntime($timeoutSeconds + 60);
        $timeoutMs = $timeoutSeconds * 1000;

        $processCommand = [
            $command,
            'run',
            '--question',
            $searchQuestion,
            '--json',
            '--timeout-ms',
            (string) $timeoutMs,
        ];
        foreach ($this->normalizePlatformIds($platformIds) as $platformId) {
            $processCommand[] = '--platform';
            $processCommand[] = $platformId;
        }

        $result = Process::timeout($timeoutSeconds + 30)
            ->env($this->environment())
            ->run($processCommand);

        $output = trim($result->output());
        $payload = $this->extractJsonPayload($output);

        if ($result->failed() && $payload === []) {
            throw new RuntimeException('本机多平台搜索工作台调用失败: '.$this->errorPreview($result->errorOutput(), $output));
        }

        if ((bool) ($payload['ok'] ?? false) !== true) {
            $error = trim((string) ($payload['error'] ?? ''));
            throw new RuntimeException('本机多平台搜索工作台未完成: '.($error !== '' ? $error : $this->errorPreview($result->errorOutput(), $output)));
        }

        $answer = $this->formatAnswer($brandProfile, $searchQuestion, $payload);
        if ($answer === '') {
            throw new RuntimeException('本机多平台搜索工作台没有返回可用于 GEO 诊断的回答');
        }

        return $answer;
    }

    public function commandPath(): string
    {
        return $this->command();
    }

    /**
     * @return array<string, mixed>
     */
    public function runCli(string $question, bool $showWorker = false, array $platformIds = []): array
    {
        $question = trim($question);
        if ($question === '') {
            throw new InvalidArgumentException('请输入要发送给多平台 AI 网页对话工作台的问题');
        }

        $timeoutSeconds = max(30, (int) config('geoflow.ai_web_workbench.timeout_seconds', 420));
        $this->extendPhpRuntime($timeoutSeconds + 60);
        $timeoutMs = $timeoutSeconds * 1000;
        $command = [
            $this->command(),
            'run',
            '--question',
            $question,
            '--json',
            '--timeout-ms',
            (string) $timeoutMs,
        ];
        foreach ($this->normalizePlatformIds($platformIds) as $platformId) {
            $command[] = '--platform';
            $command[] = $platformId;
        }
        if ($showWorker) {
            $command[] = '--show';
        }

        $result = Process::timeout($timeoutSeconds + 30)
            ->env($this->environment())
            ->run($command);

        return $this->cliResult('run', $command, $result->exitCode(), $result->output(), $result->errorOutput());
    }

    /**
     * @param  array<int, mixed>  $platformIds
     */
    public function startLoginCheck(array $platformIds = []): void
    {
        $timeoutSeconds = max(20, (int) config('geoflow.ai_web_workbench.login_check_timeout_seconds', 90));
        $timeoutMs = $timeoutSeconds * 1000;
        $shellCommand = 'nohup '.escapeshellarg($this->command())
            .' check-logins --json --timeout-ms '.(string) $timeoutMs;

        foreach ($this->normalizePlatformIds($platformIds) as $platformId) {
            $shellCommand .= ' --platform '.escapeshellarg($platformId);
        }

        $shellCommand .= ' >/tmp/geo-ai-web-workbench-login-check.log 2>&1 &';

        $result = Process::timeout(5)
            ->env($this->environment())
            ->run([
                'bash',
                '-lc',
                $shellCommand,
            ]);

        if ($result->failed()) {
            throw new RuntimeException('登录状态检测启动失败: '.$this->errorPreview($result->errorOutput(), $result->output()));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function exportCli(string $taskId): array
    {
        $taskId = trim($taskId);
        if ($taskId === '') {
            throw new InvalidArgumentException('请输入要导出的工作台任务 ID');
        }

        $command = [
            $this->command(),
            'export',
            $taskId,
            '--json',
        ];
        $result = Process::timeout(60)
            ->env($this->environment())
            ->run($command);

        return $this->cliResult('export', $command, $result->exitCode(), $result->output(), $result->errorOutput());
    }

    public function dataDir(): string
    {
        $configured = trim((string) config('geoflow.ai_web_workbench.data_dir', ''));
        if ($configured !== '') {
            return $configured;
        }

        $command = [
            $this->command(),
            'data-dir',
        ];
        $result = Process::timeout(15)
            ->env($this->environment())
            ->run($command);

        if ($result->failed()) {
            return '';
        }

        return trim($result->output());
    }

    /**
     * @return array{ok: bool, tasks: list<array<string, mixed>>, platforms: list<array<string, mixed>>, error?: string}
     */
    public function status(int $limit = 5): array
    {
        try {
            $result = Process::timeout(15)
                ->env($this->environment())
                ->run([
                    $this->command(),
                    'status',
                    '--limit',
                    (string) max(1, min(20, $limit)),
                    '--json',
                ]);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'tasks' => [],
                'platforms' => [],
                'error' => $this->errorPreview($exception->getMessage(), ''),
            ];
        }

        $payload = $this->extractJsonPayload(trim($result->output()));
        if ($result->failed() || (bool) ($payload['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'tasks' => [],
                'platforms' => [],
                'error' => $this->errorPreview($result->errorOutput(), $result->output()),
            ];
        }

        return [
            'ok' => true,
            'tasks' => collect((array) ($payload['tasks'] ?? []))
                ->filter(static fn (mixed $task): bool => is_array($task))
                ->values()
                ->all(),
            'platforms' => collect((array) ($payload['platforms'] ?? []))
                ->filter(static fn (mixed $platform): bool => is_array($platform))
                ->values()
                ->all(),
        ];
    }

    public function openUi(?string $platformId = null): void
    {
        $command = $this->command();
        $platformIds = $this->normalizePlatformIds([$platformId]);
        $shellCommand = 'nohup '.escapeshellarg($command).' ui';
        if ($platformIds !== []) {
            $shellCommand .= ' --platform '.escapeshellarg($platformIds[0]);
        }
        $shellCommand .= ' >/tmp/geo-ai-web-workbench-ui.log 2>&1 &';

        $result = Process::timeout(5)
            ->env($this->environment())
            ->run([
                'bash',
                '-lc',
                $shellCommand,
            ]);

        if ($result->failed()) {
            throw new RuntimeException('搜索工作台启动失败: '.$this->errorPreview($result->errorOutput(), $result->output()));
        }
    }

    /**
     * @param  list<string>  $command
     * @return array<string, mixed>
     */
    private function cliResult(string $action, array $command, int $exitCode, string $output, string $errorOutput): array
    {
        $output = trim($output);
        $errorOutput = trim($errorOutput);
        $payload = $this->extractJsonPayload($output);
        $ok = $exitCode === 0 && ((bool) ($payload['ok'] ?? true));

        if (! $ok && $payload === []) {
            throw new RuntimeException($this->errorPreview($errorOutput, $output));
        }

        return [
            'ok' => $ok,
            'action' => $action,
            'command' => implode(' ', $command),
            'exit_code' => $exitCode,
            'payload' => $payload,
            'output' => mb_substr($output, 0, 6000),
            'error' => mb_substr($errorOutput, 0, 2000),
            'task_id' => (string) ($payload['taskId'] ?? $payload['task_id'] ?? ''),
            'markdown_path' => (string) ($payload['markdownPath'] ?? $payload['markdown_path'] ?? ''),
            'completed_count' => (int) ($payload['completedCount'] ?? $payload['completed_count'] ?? 0),
            'sent_count' => (int) ($payload['sentCount'] ?? $payload['sent_count'] ?? 0),
            'manual_count' => (int) ($payload['manualCount'] ?? $payload['manual_count'] ?? 0),
        ];
    }

    /**
     * @param  array<int, mixed>  $platformIds
     * @return list<string>
     */
    private function normalizePlatformIds(array $platformIds): array
    {
        return collect($platformIds)
            ->map(static fn (mixed $platformId): string => trim((string) $platformId))
            ->filter(static fn (string $platformId): bool => $platformId !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function searchQuestion(string $question, string $diagnosticPrompt): string
    {
        $question = trim($question);
        if ($question !== '') {
            return $question;
        }

        if (preg_match('/(?:用户问题|搜索问题)：(.+)/u', $diagnosticPrompt, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return mb_substr(trim($diagnosticPrompt), 0, 180);
    }

    private function command(): string
    {
        $configured = trim((string) config('geoflow.ai_web_workbench.command', ''));
        if ($configured !== '') {
            return $configured;
        }

        $home = trim((string) (getenv('HOME') ?: ''));
        $localBin = $home !== '' ? $home.'/.local/bin/ai-web-workbench' : '';
        if ($localBin !== '' && is_executable($localBin)) {
            return $localBin;
        }

        return 'ai-web-workbench';
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        $env = [
            'PATH' => $this->processPath(),
        ];
        $dataDir = trim((string) config('geoflow.ai_web_workbench.data_dir', ''));
        if ($dataDir !== '') {
            $env['WORKBENCH_DATA_DIR'] = $dataDir;
        }

        return $env;
    }

    private function processPath(): string
    {
        $home = trim((string) (getenv('HOME') ?: ''));
        $paths = array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')));
        $nvmNodePaths = $home !== '' ? (glob($home.'/.nvm/versions/node/*/bin') ?: []) : [];
        rsort($nvmNodePaths);

        return collect([
            ...$nvmNodePaths,
            $home !== '' ? $home.'/.local/bin' : '',
            ...$paths,
            '/opt/homebrew/bin',
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
            '/usr/sbin',
            '/sbin',
        ])
            ->filter()
            ->unique()
            ->implode(PATH_SEPARATOR);
    }

    private function extendPhpRuntime(int $seconds): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(max(60, $seconds));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractJsonPayload(string $output): array
    {
        if ($output === '') {
            return [];
        }

        $decoded = json_decode($output, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $lines = preg_split('/\R/u', $output) ?: [];
        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded) && array_key_exists('ok', $decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function formatAnswer(BrandProfile $brandProfile, string $question, array $payload): string
    {
        $runs = collect((array) ($payload['runs'] ?? []))
            ->filter(static fn (mixed $run): bool => is_array($run))
            ->values();
        $answeredRuns = $runs->filter(static fn (array $run): bool => trim((string) ($run['answerText'] ?? '')) !== '');

        $lines = [
            '本机多平台 AI 搜索工作台结果',
            '搜索问题：'.$question,
            '目标品牌：'.$brandProfile->brand_name,
        ];

        $taskId = trim((string) ($payload['taskId'] ?? ''));
        if ($taskId !== '') {
            $lines[] = '工作台任务：'.$taskId;
        }

        $markdownPath = trim((string) ($payload['markdownPath'] ?? ''));
        if ($markdownPath !== '') {
            $lines[] = '导出记录：'.$markdownPath;
        }

        if ($answeredRuns->isEmpty() && $markdownPath !== '' && is_readable($markdownPath)) {
            $markdown = trim((string) file_get_contents($markdownPath));
            if ($markdown !== '') {
                $lines[] = '';
                $lines[] = $markdown;

                return trim(implode("\n", $lines));
            }
        }

        foreach ($answeredRuns as $run) {
            $platformName = trim((string) ($run['platformName'] ?? $run['platformId'] ?? '未知平台'));
            $answerText = trim((string) ($run['answerText'] ?? ''));
            $lines[] = '';
            $lines[] = '### '.$platformName;
            $lines[] = $answerText;

            $citations = collect((array) ($run['citations'] ?? []))
                ->map(function (mixed $citation): string {
                    if (is_array($citation)) {
                        return trim((string) ($citation['url'] ?? ''));
                    }

                    return trim((string) $citation);
                })
                ->filter(static fn (string $url): bool => $url !== '')
                ->unique()
                ->values()
                ->all();
            if ($citations !== []) {
                $lines[] = '引用来源：'.implode('、', $citations);
            }
        }

        $manualRuns = $runs->filter(static fn (array $run): bool => trim((string) ($run['answerText'] ?? '')) === '');
        if ($manualRuns->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '未自动完成的平台：';
            foreach ($manualRuns as $run) {
                $platformName = trim((string) ($run['platformName'] ?? $run['platformId'] ?? '未知平台'));
                $status = trim((string) ($run['status'] ?? ''));
                $error = trim((string) ($run['errorMessage'] ?? ''));
                $lines[] = '- '.$platformName.($status !== '' ? '：'.$status : '').($error !== '' ? '，'.$error : '');
            }
        }

        return trim(implode("\n", $lines));
    }

    private function errorPreview(string $stderr, string $stdout): string
    {
        $message = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
        $message = preg_replace('/\s+/u', ' ', $message) ?: $message;

        return $message !== '' ? mb_substr($message, 0, 300) : '无错误输出';
    }
}
