<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DeploymentWorkflowContractTest extends TestCase
{
    public function test_workflow_builds_and_pushes_the_php_production_image_for_amd64(): void
    {
        $workflow = $this->workflow();

        self::assertStringContainsString('actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803 # v6', $workflow);
        self::assertStringContainsString('shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # v2', $workflow);
        self::assertStringContainsString('actions/setup-node@249970729cb0ef3589644e2896645e5dc5ba9c38 # v6', $workflow);
        self::assertStringContainsString('docker/setup-buildx-action@f87e5991a6d7451dcb8d9637bfbc97413f497069 # v4', $workflow);
        self::assertStringContainsString('docker/login-action@dbcb813823bdd20940b903addbd779551569679f # v4', $workflow);
        self::assertStringContainsString('docker/build-push-action@c3c9e263c25d99ce0380d002d59b67737d91b0dc # v7', $workflow);
        self::assertStringContainsString('file: docker/Dockerfile.prod', $workflow);
        self::assertStringContainsString('file: docker/Dockerfile.sae-web', $workflow);
        self::assertStringContainsString('platforms: linux/amd64', $workflow);
        self::assertMatchesRegularExpression('/provenance:\s*false/', $workflow);
        self::assertMatchesRegularExpression('/sbom:\s*false/', $workflow);
        self::assertStringContainsString(':${{ github.sha }}', $workflow);
        self::assertStringContainsString(':${{ github.sha }}-web', $workflow);
        self::assertStringContainsString('GEOFLOW_APP_IMAGE=${{ env.ACR_LOGIN_REGISTRY }}', $workflow);
        self::assertStringContainsString('ACR_LOGIN_REGISTRY }}/${{ env.ACR_NAMESPACE }}/${{ env.ACR_REPOSITORY }}:${{ github.sha }}', $workflow);
    }

    public function test_workflow_supports_main_push_and_manual_role_selection(): void
    {
        $workflow = $this->workflow();

        self::assertStringContainsString("push:\n    branches:\n      - main", $workflow);
        self::assertStringContainsString('workflow_dispatch:', $workflow);

        foreach (['deploy_web', 'deploy_worker', 'deploy_ai_quality_front', 'deploy_ai_quality_backfill', 'deploy_ai_optimization', 'deploy_knowledge', 'deploy_scheduler', 'deploy_reverb'] as $input) {
            self::assertStringContainsString("      {$input}:", $workflow);
        }
        self::assertStringContainsString('      run_release:', $workflow);

        foreach (['worker', 'knowledge', 'scheduler', 'reverb'] as $role) {
            self::assertStringContainsString('default: false', $workflow);
            self::assertStringContainsString('DEPLOY_'.strtoupper($role), $workflow);
        }

        self::assertStringContainsString(
            'DEPLOY_WEB: ${{ github.event_name == \'push\' && \'true\' || inputs.deploy_web }}',
            $workflow,
        );
        self::assertStringContainsString(
            'DEPLOY_WORKER: ${{ github.event_name == \'workflow_dispatch\' && inputs.deploy_worker || \'false\' }}',
            $workflow,
        );
    }

    public function test_workflow_uses_configurable_acr_and_aliyun_credentials(): void
    {
        $workflow = $this->workflow();

        foreach ([
            'vars.ACR_LOGIN_REGISTRY',
            'vars.ACR_IMAGE_REGISTRY',
            'vars.ACR_NAMESPACE',
            'vars.ACR_REPOSITORY',
            'vars.SAE_REGION_ID',
            'secrets.ACR_USERNAME',
            'secrets.ACR_PASSWORD',
            'secrets.ALIYUN_SAE_AK_ID',
            'secrets.ALIYUN_SAE_AK_SECRET',
            'secrets.SAE_RELEASE_APP_ID',
        ] as $configuration) {
            self::assertStringContainsString($configuration, $workflow);
        }

        self::assertStringContainsString('https://github.com/aliyun/aliyun-cli/releases/download/v3.5.1/aliyun-cli-linux-3.5.1-amd64.tgz', $workflow);
        self::assertStringContainsString('sha256sum --check --status', $workflow);
        self::assertStringContainsString('aliyun sae DeployApplication', $workflow);
        self::assertStringContainsString('aliyun sae DescribeApplicationStatus', $workflow);
        self::assertStringContainsString('--ImageUrl "$image"', $workflow);
        self::assertStringContainsString('app_image=', $workflow);
        self::assertStringContainsString('web_image=', $workflow);
    }

    public function test_each_optional_role_requires_a_switch_and_an_app_id(): void
    {
        $workflow = $this->workflow();

        foreach (['web', 'worker', 'ai-quality-front', 'ai-quality-backfill', 'ai-optimization', 'knowledge', 'scheduler', 'reverb'] as $role) {
            $secret = 'secrets.SAE_'.strtoupper($role).'_APP_ID';
            $secret = match ($role) {
                'ai-quality-front' => 'secrets.SAE_AI_QUALITY_FRONT_APP_ID',
                'ai-quality-backfill' => 'secrets.SAE_AI_QUALITY_BACKFILL_APP_ID',
                'ai-optimization' => 'secrets.SAE_AI_OPTIMIZATION_APP_ID',
                default => $secret,
            };
            self::assertStringContainsString($secret, $workflow);
            $switch = match ($role) {
                'ai-quality-front' => 'AI_QUALITY_FRONT',
                'ai-quality-backfill' => 'AI_QUALITY_BACKFILL',
                'ai-optimization' => 'AI_OPTIMIZATION',
                default => strtoupper($role),
            };
            self::assertStringContainsString(
                'deploy_if_requested '.$role.' "$DEPLOY_'.$switch.'" "$SAE_'.$switch.'_APP_ID"',
                $workflow,
            );
        }

        self::assertStringContainsString('if [[ "$enabled" != "true" ]]; then', $workflow);
        self::assertStringContainsString('if [[ -z "$app_id" ]]; then', $workflow);
        self::assertStringContainsString('未配置 SAE 应用 ID，跳过 SAE 部署', $workflow);
    }

    public function test_workflow_never_runs_database_installation_or_migration(): void
    {
        $workflow = $this->workflow();

        self::assertStringNotContainsString('artisan migrate', $workflow);
        self::assertStringNotContainsString('geoflow:install', $workflow);
        self::assertStringNotContainsString('migrate --force', $workflow);
    }

    public function test_release_is_manual_protected_and_runs_before_resident_deployments(): void
    {
        $workflow = $this->workflow();

        self::assertStringContainsString('needs: [build, release]', $workflow);
        self::assertStringContainsString("if: \${{ github.event_name == 'workflow_dispatch' && inputs.run_release == true }}", $workflow);
        self::assertStringContainsString('environment: production', $workflow);
        self::assertStringContainsString('DescribeApplicationStatus', $workflow);
    }

    private function workflow(): string
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/deploy-sae.yml');

        self::assertIsString($workflow);

        return $workflow;
    }
}
