<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Topics\TopicNamespaceGuard;
use App\Services\Topics\TopicSiteSettings;
use App\Services\Topics\TopicTemplateCatalog;
use App\Services\Topics\TopicThemeCompatibility;
use App\Support\TopicAdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class TopicSettingsController extends Controller
{
    public function __construct(private readonly TopicSiteSettings $settings) {}

    public function index(Request $request): View|JsonResponse
    {
        $site = TopicAdminContext::site($request);
        if ($request->expectsJson()) {
            return response()->json(['template_options' => app(TopicTemplateCatalog::class)->options($site)])->header('Cache-Control', 'private, no-store');
        }

        return view('admin.topics.settings', TopicAdminContext::viewData($site) + ['settings' => $this->settings->get($site), 'namespaceConflicts' => app(TopicNamespaceGuard::class)->conflicts($site), 'rollbackState' => app(TopicThemeCompatibility::class)->rollbackState($site), 'canEdit' => TopicAdminContext::actor()->canManageProtectedWorkflows(), 'returnTopic' => $request->integer('topic'), 'formToken' => $request->query('form_token'), 'listReturn' => is_string($request->query('list_return')) ? $request->query('list_return') : null]);
    }

    public function rollback(Request $request): RedirectResponse
    {
        $site = TopicAdminContext::site($request);
        abort_unless(TopicAdminContext::actor()->canManageProtectedWorkflows(), 403);
        $request->validate(['binding_version' => ['required', 'integer', 'min:1']]);
        app(TopicThemeCompatibility::class)->rollback($site, TopicAdminContext::actor(), $request->integer('binding_version'));

        return redirect()->route('admin.topics.settings', ['site' => $site])->with('message', '已恢复上一版设计，并保留经过验证的专题展示能力。');
    }

    public function store(Request $request): RedirectResponse
    {
        $site = TopicAdminContext::site($request);
        abort_unless(TopicAdminContext::actor()->canManageProtectedWorkflows(), 403);
        $input = $request->only(['channel_name', 'home_limit', 'default_template']);
        if (array_key_exists('channel_name', $input) && trim((string) $input['channel_name']) === '') {
            $input['channel_name'] = '专题';
        }
        foreach (['enabled', 'home_enabled', 'navigation_enabled', 'require_review'] as $key) {
            $input[$key] = $request->boolean($key);
        }$this->settings->save($site, $input, TopicAdminContext::actor());

        return redirect()->route('admin.topics.settings', ['site' => $site, 'topic' => $request->integer('topic'), 'form_token' => $request->input('form_token'), 'list_return' => is_string($request->input('list_return')) ? $request->input('list_return') : null])->with('message', '专题设置已保存。');
    }
}
