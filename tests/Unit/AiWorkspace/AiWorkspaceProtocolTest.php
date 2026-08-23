<?php

namespace Tests\Unit\AiWorkspace;

use App\Ai\Agents\GeoHubAgent;
use App\Ai\Agents\GeoHubPlanDrafterAgent;
use App\Ai\Workspace\AiCapabilityRegistry;
use App\Ai\Workspace\AiIntentResolution;
use App\Ai\Workspace\AiPayloadDigest;
use App\Ai\Workspace\AiPlanCompiler;
use App\Ai\Workspace\AiWorkspaceErrorSanitizer;
use App\Events\Admin\AiWorkspaceRunUpdated;
use App\Http\Requests\Admin\AiWorkspace\UpdatePlanRequest;
use App\Models\Admin;
use App\Services\AiWorkspace\AiIntentResolver;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

final class AiWorkspaceProtocolTest extends TestCase
{
    public function test_every_admin_route_is_registered_or_explicitly_excluded(): void
    {
        $registry = app(AiCapabilityRegistry::class);
        $unregistered = [];
        $governed = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = (string) $route->getName();
            if ($name !== '' && str_starts_with($name, 'admin.')) {
                $capability = $registry->findForRoute($name);
                $excluded = $registry->routeIsExcluded($name);
                if (! $capability && ! $excluded) {
                    $unregistered[] = $name;
                }
                $methods = array_values(array_diff($route->methods(), ['HEAD']));
                sort($methods);
                $governed[$name] = [
                    'methods' => $methods,
                    'capability' => $capability?->key,
                    'excluded' => $excluded,
                ];
            }
        }
        ksort($governed);

        self::assertSame([], $unregistered, 'Unregistered admin routes: '.implode(', ', $unregistered));
        self::assertSame((int) config('ai-workspace.route_governance.count'), count($governed));
        self::assertSame(
            (string) config('ai-workspace.route_governance.sha256'),
            hash('sha256', json_encode($governed, JSON_UNESCAPED_SLASHES)),
            'Admin route governance changed. Review the exact route/method/capability mapping, then update the snapshot.',
        );
    }

    public function test_capabilities_declare_the_full_control_contract(): void
    {
        $registry = app(AiCapabilityRegistry::class);

        self::assertGreaterThanOrEqual(8, $registry->all()->count());
        foreach ($registry->all() as $capability) {
            self::assertNotSame('', $capability->version);
            self::assertContains($capability->maturity, ['advisory', 'read_ready', 'draft_ready', 'execute_ready', 'restricted']);
            self::assertContains($capability->risk, ['low', 'medium', 'high', 'critical']);
            self::assertContains($capability->dataClassification, ['public', 'internal', 'confidential', 'restricted']);
            self::assertNotSame([], $capability->resultContract);
            self::assertNotSame([], $capability->routePatterns);
        }

        self::assertSame([], (array) (new GeoHubAgent)->tools());
        self::assertSame([], (array) (new GeoHubPlanDrafterAgent('', ''))->tools());
    }

    public function test_sensitive_routes_resolve_to_the_narrowest_controlled_capability(): void
    {
        $registry = app(AiCapabilityRegistry::class);
        $expected = [
            'admin.articles.force-delete' => 'managed.operations',
            'admin.articles.trash.empty' => 'managed.operations',
            'admin.distribution.destroy' => 'managed.operations',
            'admin.distribution.rotate-secret' => 'managed.operations',
            'admin.leads.update' => 'managed.operations',
            'admin.site-settings.update' => 'managed.operations',
            'admin.tasks.delete' => 'managed.operations',
            'admin.tasks.update' => 'managed.operations',
            'admin.tasks.toggle-status' => 'task.status.change',
            'admin.distribution.sync-settings' => 'distribution.site_settings_sync',
            'admin.distribution.sync-settings.preview' => 'distribution.preview',
        ];

        foreach ($expected as $route => $capability) {
            self::assertSame($capability, $registry->findForRoute($route)?->key, $route);
        }
    }

    public function test_composite_score_and_clarification_threshold_are_server_controlled(): void
    {
        config()->set('ai-workspace.resolution_threshold', 0.85);
        $resolution = new AiIntentResolution('workflow', 'task.draft', [], [], [], [], 0.8, 0.8, 0.8);

        self::assertSame(0.8, $resolution->score());
        self::assertTrue($resolution->requiresClarification());
    }

    public function test_rule_resolver_identifies_supported_intents_and_missing_parameters(): void
    {
        config()->set('ai-workspace.runtime_enabled', false);
        $resolver = app(AiIntentResolver::class);
        $cases = json_decode((string) file_get_contents(base_path('tests/Fixtures/ai-workspace-intents.json')), true, 512, JSON_THROW_ON_ERROR);
        $correct = 0;
        $missingCorrect = 0;
        $missingExpected = 0;

        foreach ($cases as $case) {
            $resolution = $resolver->resolve($case['prompt']);
            $actual = $resolution->mode === 'answer' ? 'answer' : ($resolution->candidates[0]['key'] ?? '');
            $correct += $actual === $case['expected'] ? 1 : 0;
            if (($case['missing'] ?? []) !== []) {
                $missingExpected++;
                $missingCorrect += array_diff($case['missing'], $resolution->missingParameters) === [] ? 1 : 0;
            }
        }

        self::assertGreaterThanOrEqual(0.9, $correct / count($cases));
        self::assertGreaterThanOrEqual(0.95, $missingCorrect / $missingExpected);
    }

    public function test_rule_resolver_keeps_compound_workflow_in_user_order(): void
    {
        config()->set('ai-workspace.runtime_enabled', false);

        $resolution = app(AiIntentResolver::class)->resolve('先生成日报，然后启动任务 12');

        self::assertFalse($resolution->requiresClarification());
        self::assertSame(
            ['analytics.daily_report', 'task.status.change'],
            array_column($resolution->workflowSteps, 'capability'),
        );
        self::assertSame(['operation-1', 'operation-2'], array_column($resolution->workflowSteps, 'operation_id'));
        self::assertSame(12, $resolution->workflowSteps[1]['parameters']['task_id']);
    }

    public function test_model_candidates_are_deduplicated_and_ranked_by_confidence(): void
    {
        $resolver = app(AiIntentResolver::class);
        $method = new \ReflectionMethod($resolver, 'fromModel');
        $resolution = $method->invoke($resolver, [
            'mode' => 'workflow',
            'intent' => '生成运营报告',
            'candidate_capabilities' => [
                ['key' => 'analytics.daily_report', 'confidence' => 0.51, 'reason' => 'low'],
                ['key' => 'analytics.weekly_report', 'confidence' => 0.96, 'reason' => 'high'],
                ['key' => 'analytics.daily_report', 'confidence' => 0.4, 'reason' => 'duplicate'],
            ],
            'requested_steps' => [
                [
                    'operation_id' => 'daily',
                    'capability' => 'analytics.daily_report',
                    'parameters' => [],
                    'missing_parameters' => [],
                ],
                [
                    'operation_id' => 'weekly',
                    'capability' => 'analytics.weekly_report',
                    'parameters' => [],
                    'missing_parameters' => [],
                ],
            ],
            'known_parameters' => [],
            'missing_parameters' => [],
            'ambiguities' => [],
            'semantic_confidence' => 0.96,
            'object_confidence' => 0.96,
            'completeness_confidence' => 0.96,
        ], '生成运营报告');

        self::assertSame(
            ['analytics.weekly_report', 'analytics.daily_report'],
            array_column($resolution->candidates, 'key'),
        );
        self::assertSame(
            ['analytics.daily_report', 'analytics.weekly_report'],
            array_column($resolution->workflowSteps, 'capability'),
        );
        self::assertSame(['daily', 'weekly'], array_column($resolution->workflowSteps, 'operation_id'));
    }

    public function test_rule_resolver_keeps_repeated_capability_operations_distinct(): void
    {
        config()->set('ai-workspace.runtime_enabled', false);

        $resolution = app(AiIntentResolver::class)->resolve('启动任务 12，然后停止任务 13');

        self::assertFalse($resolution->requiresClarification());
        self::assertSame(['task.status.change', 'task.status.change'], array_column($resolution->workflowSteps, 'capability'));
        self::assertSame([12, 13], array_column(array_column($resolution->workflowSteps, 'parameters'), 'task_id'));
        self::assertSame(['start', 'stop'], array_column(array_column($resolution->workflowSteps, 'parameters'), 'action'));
    }

    public function test_model_resolver_keeps_repeated_capability_operations_distinct(): void
    {
        $resolver = app(AiIntentResolver::class);
        $method = new \ReflectionMethod($resolver, 'fromModel');
        $resolution = $method->invoke($resolver, [
            'mode' => 'workflow',
            'intent' => '分别启停任务',
            'candidate_capabilities' => [[
                'key' => 'task.status.change',
                'confidence' => 0.98,
                'reason' => '两个任务状态操作',
            ]],
            'requested_steps' => [
                [
                    'operation_id' => 'start-task-12',
                    'capability' => 'task.status.change',
                    'parameters' => ['task_id' => 12, 'action' => 'start'],
                    'missing_parameters' => [],
                ],
                [
                    'operation_id' => 'stop-task-13',
                    'capability' => 'task.status.change',
                    'parameters' => ['task_id' => 13, 'action' => 'stop'],
                    'missing_parameters' => [],
                ],
            ],
            'known_parameters' => [],
            'missing_parameters' => [],
            'ambiguities' => [],
            'semantic_confidence' => 0.98,
            'object_confidence' => 0.98,
            'completeness_confidence' => 0.98,
        ], '启动任务 12，然后停止任务 13');

        self::assertSame(['start-task-12', 'stop-task-13'], array_column($resolution->workflowSteps, 'operation_id'));
        self::assertSame(['task.status.change', 'task.status.change'], array_column($resolution->workflowSteps, 'capability'));
        self::assertSame([12, 13], array_column(array_column($resolution->workflowSteps, 'parameters'), 'task_id'));
        self::assertSame(['start', 'stop'], array_column(array_column($resolution->workflowSteps, 'parameters'), 'action'));
    }

    public function test_plan_compiler_rejects_unknown_restricted_and_invalid_plans(): void
    {
        $compiler = app(AiPlanCompiler::class);
        $admin = new Admin(['role' => 'super_admin', 'status' => 'active']);

        $plan = $compiler->compile($admin, 'task.draft', [[
            'capability' => 'task.draft',
            'parameters' => ['name' => '内容增长计划', 'article_limit' => 8],
        ]]);
        self::assertSame('medium', $plan->riskLevel);
        self::assertTrue($plan->requiresApproval());
        self::assertSame(['name' => '内容增长计划'], array_intersect_key($plan->steps[0]['target_summary'], ['name' => true]));

        $this->expectException(ValidationException::class);
        $compiler->compile($admin, 'task.draft', [[
            'capability' => 'task.draft',
            'parameters' => [],
        ]]);
    }

    public function test_plan_compiler_blocks_restricted_capabilities_and_digest_is_canonical(): void
    {
        $compiler = app(AiPlanCompiler::class);
        $admin = new Admin(['role' => 'super_admin']);
        self::assertSame(AiPayloadDigest::make(['a' => 1, 'b' => 2]), AiPayloadDigest::make(['b' => 2, 'a' => 1]));

        $this->expectException(InvalidArgumentException::class);
        $compiler->compile($admin, 'admin.governance', [[
            'capability' => 'admin.governance',
            'parameters' => [],
        ]]);
    }

    public function test_plan_compiler_and_editor_share_the_same_step_limit(): void
    {
        config()->set('ai-workspace.max_plan_steps', 100);
        $admin = new Admin(['role' => 'super_admin', 'status' => 'active']);
        $steps = array_fill(0, 101, [
            'capability' => 'analytics.daily_report',
            'parameters' => [],
        ]);

        try {
            app(AiPlanCompiler::class)->compile($admin, 'oversized plan', $steps);
            self::fail('Oversized plans must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('step limit', $exception->getMessage());
        }

        self::assertContains(
            'max:100',
            (new UpdatePlanRequest)->rules()['step_parameters'],
        );
    }

    public function test_runtime_errors_are_redacted_before_persistence(): void
    {
        $message = AiWorkspaceErrorSanitizer::clean(
            'Bearer secret-token Basic basic-secret api_key=sk-live password:guess '
            .'{"access_token":"json-secret","password":"json-password"} '
            .'https://user:pass@example.com/fail?token=query-secret standalone sk-project-secret',
        );

        self::assertStringNotContainsString('secret-token', $message);
        self::assertStringNotContainsString('basic-secret', $message);
        self::assertStringNotContainsString('sk-live', $message);
        self::assertStringNotContainsString('guess', $message);
        self::assertStringNotContainsString('json-secret', $message);
        self::assertStringNotContainsString('json-password', $message);
        self::assertStringNotContainsString('query-secret', $message);
        self::assertStringNotContainsString('sk-project-secret', $message);
        self::assertStringNotContainsString('user:pass', $message);
    }

    public function test_realtime_run_updates_only_broadcast_compact_identifiers(): void
    {
        $event = new AiWorkspaceRunUpdated(
            9,
            '0191f912-7084-7ae1-a6bb-e6ea14a71f11',
            '0191f912-7084-7ae1-a6bb-e6ea14a71f22',
            'running',
            4,
            8,
        );

        self::assertSame([
            'run_id' => '0191f912-7084-7ae1-a6bb-e6ea14a71f11',
            'conversation_id' => '0191f912-7084-7ae1-a6bb-e6ea14a71f22',
            'state' => 'running',
            'version' => 4,
            'sequence' => 8,
        ], $event->broadcastWith());
    }
}
