<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\AdminActivityLog;
use Illuminate\Http\Request;
use Throwable;

/**
 * 后台管理员操作日志记录器。
 *
 * 对齐 bak/includes/functions.php 的 log_admin_activity / log_admin_request_if_needed：
 * - 仅记录后台管理员上下文；
 * - 自动脱敏密码、密钥等敏感字段；
 * - 对超长文本做长度摘要，避免日志膨胀。
 */
final class AdminActivityLogger
{
    /**
     * 记录管理员操作日志。
     *
     * @param  array{
     *   request_method?:string,
     *   page?:string,
     *   target_type?:string,
     *   target_id?:int|null,
     *   ip_address?:string,
     *   details?:array<string,mixed>|string
     * }  $context
     */
    public static function log(Admin $admin, string $action, array $context = []): void
    {
        $details = $context['details'] ?? [];
        if (is_array($details)) {
            // 统一在入库前做脱敏，避免误把明文密码/密钥打进审计表。
            $details = json_encode(self::sanitizePayload($details), JSON_UNESCAPED_UNICODE);
        } else {
            $details = trim((string) $details);
        }

        try {
            AdminActivityLog::query()->create([
                'admin_id' => (int) $admin->id,
                'admin_username' => (string) ($admin->username ?? ''),
                'admin_role' => (string) ($admin->role ?? 'admin'),
                'action' => trim($action),
                'request_method' => strtoupper(trim((string) ($context['request_method'] ?? 'GET'))),
                'page' => trim((string) ($context['page'] ?? '')),
                'target_type' => trim((string) ($context['target_type'] ?? '')),
                'target_id' => isset($context['target_id']) ? (int) $context['target_id'] : null,
                'ip_address' => trim((string) ($context['ip_address'] ?? '')),
                'details' => $details,
            ]);
        } catch (Throwable) {
            // 日志写入失败不能影响主流程。
        }
    }

    /**
     * 从当前请求推导上下文并写入日志。
     *
     * @param  array<string,mixed>  $details
     */
    public static function logFromRequest(Request $request, Admin $admin, string $action, array $details = []): void
    {
        // 尝试从路由参数中推断目标对象（如 taskId/libraryId），用于日志检索和追踪。
        [$targetType, $targetId] = self::guessTarget($request);

        self::log($admin, $action, [
            'request_method' => (string) $request->method(),
            'page' => self::resolvePage($request),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip_address' => (string) ($request->ip() ?? ''),
            'details' => $details,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private static function sanitizePayload(array $payload): array
    {
        $sensitiveKeys = [
            'password',
            'current_password',
            'new_password',
            'confirm_password',
            'api_key',
            '_token',
            'csrf_token',
        ];

        $result = [];
        foreach ($payload as $key => $value) {
            $field = (string) $key;
            if (in_array($field, $sensitiveKeys, true)) {
                $result[$field] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $result[$field] = self::sanitizePayload($value);

                continue;
            }

            if (is_bool($value) || $value === null) {
                $result[$field] = $value;

                continue;
            }

            $text = trim((string) $value);
            if ($text === '') {
                $result[$field] = '';

                continue;
            }

            // 与 bak 保持一致：对内容型长文本仅记录长度摘要。
            if (preg_match('/content|prompt|description|bio|note|words|html/i', $field) === 1) {
                $result[$field] = '[text:'.mb_strlen($text).' chars]';

                continue;
            }

            if (mb_strlen($text) > 180) {
                $text = mb_substr($text, 0, 180).'...';
            }

            $result[$field] = $text;
        }

        return $result;
    }

    /**
     * @return array{0:string,1:int|null}
     */
    private static function guessTarget(Request $request): array
    {
        $route = $request->route();
        if (! $route) {
            return ['', null];
        }

        foreach ((array) $route->parameters() as $name => $value) {
            if (! str_ends_with((string) $name, 'Id')) {
                continue;
            }

            // 与现有后台命名约定保持一致：仅识别 xxxId 参数作为 target。
            $targetType = substr((string) $name, 0, -2);
            $targetId = is_numeric($value) ? (int) $value : null;

            return [$targetType, $targetId];
        }

        return ['', null];
    }

    /**
     * 从请求路径提取页面标识（近似 bak 的 basename(PHP_SELF)）。
     */
    private static function resolvePage(Request $request): string
    {
        $path = trim((string) $request->path(), '/');
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);

        return (string) end($segments);
    }
}
