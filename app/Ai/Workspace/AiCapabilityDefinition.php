<?php

namespace App\Ai\Workspace;

use App\Models\Admin;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AiCapabilityDefinition
{
    /**
     * @param  array<string,array<string,mixed>>  $inputSchema
     * @param  array<string,mixed>  $resultContract
     * @param  list<string>  $routePatterns
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $maturity,
        public string $risk,
        public string $permission,
        public string $executionScope,
        public string $dataClassification,
        public string $cost,
        public string $approvalPolicy,
        public string $version,
        public array $inputSchema,
        public array $resultContract,
        public array $routePatterns,
        public string $handler,
    ) {
        if (! in_array($maturity, ['advisory', 'read_ready', 'draft_ready', 'execute_ready', 'restricted'], true)) {
            throw new InvalidArgumentException('Invalid capability maturity: '.$maturity);
        }

        if (! in_array($risk, ['low', 'medium', 'high', 'critical'], true)) {
            throw new InvalidArgumentException('Invalid capability risk: '.$risk);
        }

        $contracts = [
            'permission' => [$permission, ['admin', 'super_admin']],
            'execution scope' => [$executionScope, ['none', 'internal_read', 'internal_write', 'external_read', 'external_write']],
            'data classification' => [$dataClassification, ['public', 'internal', 'confidential', 'restricted']],
            'cost' => [$cost, ['none', 'low', 'medium', 'high']],
            'approval policy' => [$approvalPolicy, ['none', 'once', 'per_step', 'target_matrix', 'blocked']],
        ];
        foreach ($contracts as $label => [$value, $allowed]) {
            if (! in_array($value, $allowed, true)) {
                throw new InvalidArgumentException(sprintf('Invalid capability %s: %s', $label, $value));
            }
        }
        if (preg_match('/\A\d+\.\d+\.\d+\z/', $version) !== 1) {
            throw new InvalidArgumentException('Invalid capability version: '.$version);
        }
        if ($resultContract === [] || $routePatterns === [] || $handler === '') {
            throw new InvalidArgumentException('Capability contract is incomplete: '.$key);
        }
    }

    /** @param array<string,mixed> $definition */
    public static function fromArray(string $key, array $definition): self
    {
        return new self(
            key: $key,
            name: (string) ($definition['name'] ?? $key),
            description: (string) ($definition['description'] ?? ''),
            maturity: (string) ($definition['maturity'] ?? 'restricted'),
            risk: (string) ($definition['risk'] ?? 'critical'),
            permission: (string) ($definition['permission'] ?? 'super_admin'),
            executionScope: (string) ($definition['execution_scope'] ?? 'none'),
            dataClassification: (string) ($definition['data_classification'] ?? 'restricted'),
            cost: (string) ($definition['cost'] ?? 'none'),
            approvalPolicy: (string) ($definition['approval_policy'] ?? 'blocked'),
            version: (string) ($definition['version'] ?? '1.0.0'),
            inputSchema: (array) ($definition['input_schema'] ?? []),
            resultContract: (array) ($definition['result_contract'] ?? []),
            routePatterns: array_values(array_map('strval', (array) ($definition['route_patterns'] ?? []))),
            handler: (string) ($definition['handler'] ?? 'restricted'),
        );
    }

    public function allows(Admin $admin): bool
    {
        return $this->permission === 'admin' || ($this->permission === 'super_admin' && $admin->isSuperAdmin());
    }

    public function coversRoute(string $routeName): bool
    {
        foreach ($this->routePatterns as $pattern) {
            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    public function requiresApproval(): bool
    {
        return ! in_array($this->approvalPolicy, ['none', 'blocked'], true);
    }

    public function isExecutable(): bool
    {
        return in_array($this->maturity, ['read_ready', 'draft_ready', 'execute_ready'], true);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'maturity' => $this->maturity,
            'risk' => $this->risk,
            'permission' => $this->permission,
            'execution_scope' => $this->executionScope,
            'data_classification' => $this->dataClassification,
            'cost' => $this->cost,
            'approval_policy' => $this->approvalPolicy,
            'version' => $this->version,
            'input_schema' => $this->inputSchema,
            'result_contract' => $this->resultContract,
            'route_patterns' => $this->routePatterns,
        ];
    }
}
