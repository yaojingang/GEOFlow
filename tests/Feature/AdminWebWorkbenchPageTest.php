<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AdminWebWorkbenchPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_authenticated_admin_can_open_web_workbench_first_level_page(): void
    {
        Process::fake([
            '*' => Process::result(json_encode([
                'ok' => true,
                'platforms' => [
                    [
                        'platformId' => 'chatgpt',
                        'platformName' => 'ChatGPT',
                        'loginOk' => true,
                        'loginStatus' => '已登录',
                        'loginHint' => '可直接检测',
                        'runCount' => 10,
                        'completedCount' => 10,
                    ],
                    [
                        'platformId' => 'kimi',
                        'platformName' => 'Kimi',
                        'loginOk' => false,
                        'loginStatus' => '需要登录',
                        'loginHint' => '请打开工作台 UI 登录 Kimi 后再检测',
                        'lastError' => 'Kimi 当前未登录（已尝试 1 次）',
                    ],
                ],
                'tasks' => [[
                    'id' => 'task-web-workbench-1',
                    'title' => '重庆涪陵全屋定制怎么选',
                    'status' => '完成',
                    'runCount' => 2,
                    'completedCount' => 2,
                    'markdownPath' => '/tmp/task-web-workbench-1.md',
                ]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '', 0),
        ]);

        $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.web-workbench.index'))
            ->assertOk()
            ->assertSee('AI网页工作台')
            ->assertSee('多平台 AI 网页对话工作台')
            ->assertSee('直接调用 CLI')
            ->assertSee('运行 CLI')
            ->assertSee('平台监视')
            ->assertSee('ChatGPT')
            ->assertSee('Kimi')
            ->assertSee('需要登录')
            ->assertSee('单平台检测')
            ->assertSee('name="platform_ids[]"', false)
            ->assertSee('task-web-workbench-1')
            ->assertSee(route('admin.web-workbench.run'), false)
            ->assertSee(route('admin.web-workbench.export'), false);
    }

    public function test_admin_can_run_web_workbench_cli_from_first_level_page(): void
    {
        Process::fake([
            '*' => Process::result(json_encode([
                'ok' => true,
                'taskId' => 'task-cli-run',
                'markdownPath' => '/tmp/task-cli-run.md',
                'sentCount' => 2,
                'completedCount' => 2,
                'runs' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '', 0),
        ]);

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.web-workbench.run'), [
                'question' => '重庆涪陵全屋定制客户为什么只问价格',
                'platform_ids' => ['chatgpt'],
            ])
            ->assertRedirect(route('admin.web-workbench.index'))
            ->assertSessionHas('message', 'CLI 运行完成')
            ->assertSessionHas('web_workbench_cli_result');

        Process::assertRan(function ($process): bool {
            $command = is_array($process->command) ? $process->command : [$process->command];

            return in_array('run', $command, true)
                && in_array('--question', $command, true)
                && in_array('重庆涪陵全屋定制客户为什么只问价格', $command, true)
                && in_array('--platform', $command, true)
                && in_array('chatgpt', $command, true)
                && in_array('--json', $command, true);
        });
    }

    public function test_admin_can_export_web_workbench_task_from_first_level_page(): void
    {
        Process::fake([
            '*' => Process::result(json_encode([
                'ok' => true,
                'taskId' => 'task-cli-export',
                'markdownPath' => '/tmp/task-cli-export.md',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '', 0),
        ]);

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.web-workbench.export'), [
                'task_id' => 'task-cli-export',
            ])
            ->assertRedirect(route('admin.web-workbench.index'))
            ->assertSessionHas('message', '任务导出完成')
            ->assertSessionHas('web_workbench_cli_result');

        Process::assertRan(function ($process): bool {
            $command = is_array($process->command) ? $process->command : [$process->command];

            return in_array('export', $command, true)
                && in_array('task-cli-export', $command, true)
                && in_array('--json', $command, true);
        });
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'web_workbench_admin',
            'password' => 'secret-123',
            'email' => 'web-workbench-admin@example.com',
            'display_name' => 'Workbench Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}
