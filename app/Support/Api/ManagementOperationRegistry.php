<?php

namespace App\Support\Api;

final class ManagementOperationRegistry
{
    public const PROTOCOL_VERSION = '1.0';

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'capabilities' => self::operation('capabilities', 'GET', 'capabilities'),
            'auth.session' => self::operation('auth.session', 'GET', 'auth/session'),
            'auth.logout' => self::operation('auth.logout', 'POST', 'auth/logout'),
            'updater.status' => self::operation('updater.status', 'GET', 'management/updater/status', 'updater:read'),
            'updater.plan' => self::operation('updater.plan', 'POST', 'management/updater/plans', 'updater:plan', ['body' => ['type' => 'object', 'required' => ['instance_id', 'action']]]),
            'updater.recovery-points' => self::operation('updater.recovery-points', 'GET', 'management/updater/recovery-points', 'updater:read'),
            'updater.requests.lookup' => self::operation('updater.requests.lookup', 'GET', 'management/updater/requests/{requestId}', 'updater:read', ['path' => ['type' => 'object', 'required' => ['requestId']]]),
            'updater.operations.show' => self::operation('updater.operations.show', 'GET', 'management/updater/operations/{operationId}', 'updater:read', ['path' => ['type' => 'object', 'required' => ['operationId']]]),
            'updater.operations.create' => array_replace(self::operation('updater.operations.create', 'POST', 'management/updater/operations', null, ['body' => ['type' => 'object', 'required' => ['instance_id', 'action', 'client_request_id', 'plan_id', 'plan_sha256', 'expected_epoch', 'allow_maintenance', 'confirm_host_access']]]), ['idempotent' => true, 'receipt' => true, 'action_scopes' => ['update' => 'updater:update', 'backup' => 'updater:backup', 'restore' => 'updater:restore', 'switch-back' => 'updater:update']]),
            'sites.list' => self::operation('sites.list', 'GET', 'management/sites', 'sites:read'),
            'sites.show' => self::operation('sites.show', 'GET', 'management/sites/{site}', 'sites:read', ['path' => ['type' => 'object', 'required' => ['site']]]),
            'operations.lookup' => self::operation('operations.lookup', 'GET', 'management/operations/lookup', null, ['query' => ['type' => 'object', 'required' => ['client_request_id']]]),
            'operations.show' => self::operation('operations.show', 'GET', 'management/operations/{operation}', null, ['path' => ['type' => 'object', 'required' => ['operation']]]),
            'tasks.enqueue' => self::operation('tasks.enqueue', 'POST', 'tasks/{task}/enqueue', 'tasks:write', ['path' => ['type' => 'object', 'required' => ['task']], 'body' => ['type' => 'object']]) + ['receipt' => true],
            'themes.list' => self::operation('themes.list', 'GET', 'management/themes', 'themes:read'),
            'themes.contract' => self::operation('themes.contract', 'GET', 'management/theme-contract', 'themes:read'),
            'theme-workspaces.create' => self::operation('theme-workspaces.create', 'POST', 'management/theme-workspaces', 'themes:write', ['body' => ['type' => 'object', 'required' => ['site', 'theme']]]),
            'theme-workspaces.show' => self::operation('theme-workspaces.show', 'GET', 'management/theme-workspaces/{workspace}', 'themes:read', ['path' => ['type' => 'object', 'required' => ['workspace']]]),
            'theme-workspaces.file' => self::operation('theme-workspaces.file', 'GET', 'management/theme-workspaces/{workspace}/files', 'themes:read', ['path' => ['type' => 'object', 'required' => ['workspace']], 'query' => ['type' => 'object', 'required' => ['path']]]),
            'theme-workspaces.change' => self::operation('theme-workspaces.change', 'POST', 'management/theme-workspaces/{workspace}/changes', 'themes:write', ['path' => ['type' => 'object', 'required' => ['workspace']], 'body' => ['type' => 'object', 'required' => ['expected_version', 'changes']]]),
            'theme-workspaces.authorize-code' => self::operation('theme-workspaces.authorize-code', 'POST', 'management/theme-workspaces/{workspace}/code-authorizations', 'themes:code', ['path' => ['type' => 'object', 'required' => ['workspace']], 'body' => ['type' => 'object', 'required' => ['password']]]),
            'theme-workspaces.preview' => self::operation('theme-workspaces.preview', 'POST', 'management/theme-workspaces/{workspace}/previews', 'themes:read', ['path' => ['type' => 'object', 'required' => ['workspace']]]),
            'theme-workspaces.contract' => self::operation('theme-workspaces.contract', 'GET', 'management/theme-workspaces/{workspace}/contract', 'themes:read', ['path' => ['type' => 'object', 'required' => ['workspace']]]),
            'theme-workspaces.discard' => self::operation('theme-workspaces.discard', 'POST', 'management/theme-workspaces/{workspace}/discard', 'themes:write', ['path' => ['type' => 'object', 'required' => ['workspace']], 'body' => ['type' => 'object', 'required' => ['expected_version']]]),
        ];
    }

    /** @return array<string, mixed> */
    public static function get(string $name): array
    {
        if (! isset(self::all()[$name])) {
            throw new \InvalidArgumentException('Unknown management operation: '.$name);
        }

        return self::all()[$name];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private static function operation(string $name, string $method, string $path, ?string $scope = null, array $input = []): array
    {
        return [
            'name' => $name, 'method' => $method, 'path' => $path,
            'auth' => true, 'idempotent' => $name === 'tasks.enqueue',
            'scope' => $scope, 'transport' => 'json',
            'input_schema' => ['type' => 'object', 'properties' => $input, 'additionalProperties' => false],
        ];
    }
}
