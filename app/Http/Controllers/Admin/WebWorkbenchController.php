<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Geo\GeoWebWorkbenchClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class WebWorkbenchController extends Controller
{
    public function index(GeoWebWorkbenchClient $client): View
    {
        return view('admin.web-workbench.index', [
            'pageTitle' => '多平台 AI 网页对话工作台',
            'activeMenu' => 'web_workbench',
            'commandPath' => $client->commandPath(),
            'dataDir' => $client->dataDir(),
            'status' => $client->status(10),
            'cliResult' => session('web_workbench_cli_result'),
        ]);
    }

    public function open(GeoWebWorkbenchClient $client): RedirectResponse
    {
        try {
            $client->openUi();
        } catch (Throwable $exception) {
            return redirect()
                ->route('admin.web-workbench.index')
                ->withErrors('工作台启动失败：'.$this->message($exception));
        }

        return redirect()
            ->route('admin.web-workbench.index')
            ->with('message', '多平台 AI 网页对话工作台已打开');
    }

    public function run(Request $request, GeoWebWorkbenchClient $client): RedirectResponse
    {
        $payload = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
            'show_worker' => ['nullable', 'boolean'],
            'platform_ids' => ['nullable', 'array'],
            'platform_ids.*' => ['string', 'max:80'],
        ], [
            'question.required' => '请输入要发送给工作台的问题',
        ]);

        try {
            $result = $client->runCli(
                (string) $payload['question'],
                (bool) ($payload['show_worker'] ?? false),
                (array) ($payload['platform_ids'] ?? []),
            );
        } catch (Throwable $exception) {
            return redirect()
                ->route('admin.web-workbench.index')
                ->withInput()
                ->withErrors('CLI 调用失败：'.$this->message($exception));
        }

        return redirect()
            ->route('admin.web-workbench.index')
            ->with('message', 'CLI 运行完成')
            ->with('web_workbench_cli_result', $result);
    }

    public function export(Request $request, GeoWebWorkbenchClient $client): RedirectResponse
    {
        $payload = $request->validate([
            'task_id' => ['required', 'string', 'max:120'],
        ], [
            'task_id.required' => '请输入要导出的任务 ID',
        ]);

        try {
            $result = $client->exportCli((string) $payload['task_id']);
        } catch (Throwable $exception) {
            return redirect()
                ->route('admin.web-workbench.index')
                ->withInput()
                ->withErrors('导出失败：'.$this->message($exception));
        }

        return redirect()
            ->route('admin.web-workbench.index')
            ->with('message', '任务导出完成')
            ->with('web_workbench_cli_result', $result);
    }

    private function message(Throwable $exception): string
    {
        return mb_substr($exception->getMessage(), 0, 800);
    }
}
