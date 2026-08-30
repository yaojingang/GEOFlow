<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\AdminActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * 后台管理员写操作日志中间件。
 *
 * 仅记录 POST/PUT/PATCH/DELETE 请求，避免把列表浏览型 GET 全量灌入日志。
 */
class LogAdminActivity
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Admin|null $admin */
        $admin = auth('admin')->user();

        // 先放行业务逻辑，确保日志失败不阻断正常响应。
        $response = $next($request);

        $method = strtoupper((string) $request->method());
        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        if (! $admin instanceof Admin) {
            return $response;
        }

        $requestedAction = $request->attributes->get('admin_activity_action');
        $action = is_string($requestedAction)
            && preg_match('/\A[a-z0-9._:-]{1,40}\z/i', $requestedAction) === 1
                ? $requestedAction
                : 'submit';
        $routeName = (string) ($request->route()?->getName() ?? '');
        // 组合路由名 + action，便于后续按模块和操作类型筛选审计日志。
        $fullAction = mb_substr($routeName !== '' ? $routeName.':'.$action : $action, 0, 120);

        $isAiWorkspace = Str::startsWith($routeName, 'admin.ai-workspace.');
        $isKnowledgeFacts = Str::startsWith($routeName, 'admin.knowledge-bases.facts.')
            || Str::startsWith($routeName, 'admin.knowledge-bases.fact-values.')
            || Str::startsWith($routeName, 'admin.knowledge-bases.fact-evidences.')
            || Str::startsWith($routeName, 'admin.knowledge-bases.fact-revisions.')
            || Str::startsWith($routeName, 'admin.knowledge-bases.fact-generation.');
        $details = $isKnowledgeFacts
            ? $this->knowledgeFactAuditSummary($request, $response)
            : ($isAiWorkspace
            ? $this->aiWorkspaceAuditSummary($request, $response)
            : $request->except([
                'password', 'password_confirmation', 'package_password',
                'current_password', 'current_admin_password', 'updater_authorization_code',
                'new_password', 'confirm_password',
            ]));
        $explicitDetails = $request->attributes->get('admin_activity_details');
        if (is_array($explicitDetails) && ! $isKnowledgeFacts) {
            $details = array_replace($details, $explicitDetails);
        }
        if (! array_key_exists('success', $details)) {
            $errors = session()->get('errors');
            $details['success'] = $response->getStatusCode() < 400
                && (! is_object($errors) || ! method_exists($errors, 'any') || ! $errors->any());
        }
        AdminActivityLogger::logFromRequest($request, $admin, $fullAction, $details);

        return $response;
    }

    /** @return array<string,mixed> */
    private function knowledgeFactAuditSummary(Request $request, Response $response): array
    {
        $details = [];
        foreach (['knowledgeBaseId', 'factId', 'valueId', 'revisionId', 'runId'] as $parameter) {
            $value = $request->route($parameter);
            if (is_scalar($value) && (string) $value !== '') {
                $details[Str::snake($parameter)] = mb_substr((string) $value, 0, 80);
            }
        }
        foreach (['review_status', 'mode', 'target_count', 'action', 'lock_version'] as $field) {
            $value = $request->input($field);
            if (is_scalar($value)) {
                $details[$field] = mb_substr((string) $value, 0, 80);
            }
        }
        $details['http_status'] = $response->getStatusCode();

        return $details;
    }

    /** @return array<string,mixed> */
    private function aiWorkspaceAuditSummary(Request $request, Response $response): array
    {
        $details = [];
        foreach (['conversation', 'run', 'approval', 'step'] as $parameter) {
            $value = $request->route($parameter);
            if (is_string($value) && $value !== '') {
                $details[$parameter.'_id'] = mb_substr($value, 0, 80);
            }
        }

        $payload = method_exists($response, 'getData') ? $response->getData(true) : null;
        $data = is_array($payload) && is_array($payload['data'] ?? null) ? $payload['data'] : [];
        foreach (['id', 'state', 'plan_version'] as $field) {
            if (isset($data[$field]) && (is_string($data[$field]) || is_numeric($data[$field]))) {
                $details[$field === 'id' ? 'result_id' : $field] = $data[$field];
            }
        }

        return $details;
    }
}
