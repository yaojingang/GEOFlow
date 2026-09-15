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
