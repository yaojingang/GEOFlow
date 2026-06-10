<?php

namespace App\Services\Admin\SiteThemeReplication;

use App\Models\SiteThemeReplication;
use App\Models\SiteThemeReplicationVersion;
use App\Services\Admin\SiteThemeReplicationService;
use Throwable;

class ThemeReplicationPipelineService
{
    public function __construct(
        private readonly SiteThemeReplicationService $replicationService,
        private readonly ThemeReferenceFetcher $fetcher,
        private readonly ThemeReferenceAnalyzer $analyzer,
        private readonly ThemeReplicationAgent $agent,
        private readonly ThemeScaffoldWriter $writer,
        private readonly ThemeComplianceGuard $guard,
    ) {}

    public function run(int $replicationId): void
    {
        $replication = SiteThemeReplication::query()->with('aiModel')->find($replicationId);
        if (! $replication) {
            return;
        }

        if ((string) $replication->status !== SiteThemeReplication::STATUS_QUEUED) {
            return;
        }

        try {
            $this->buildReadyVersion($replication, null, true);
        } catch (Throwable $exception) {
            $this->fail($replication, $exception->getMessage(), [
                'exception' => $exception::class,
            ]);
        }
    }

    public function iterate(int $replicationId, string $feedback): void
    {
        $replication = SiteThemeReplication::query()->with('aiModel')->find($replicationId);
        if (! $replication) {
            return;
        }

        if ((string) $replication->status !== SiteThemeReplication::STATUS_ITERATING) {
            return;
        }

        try {
            $this->replicationService->log($replication, 'info', 'iterating', __('admin.theme_replication.log.iterating'));
            $this->buildReadyVersion($replication, trim($feedback), false);
        } catch (Throwable $exception) {
            $this->fail($replication, $exception->getMessage(), [
                'exception' => $exception::class,
            ]);
        }
    }

    private function buildReadyVersion(SiteThemeReplication $replication, ?string $feedback, bool $fetchReferences): void
    {
        $references = [];
        $analysis = (array) $replication->analysis_json;

        if ($fetchReferences || $analysis === []) {
            $this->transition($replication, SiteThemeReplication::STATUS_FETCHING, 'fetching', __('admin.theme_replication.log.fetching'));
            $references = $this->fetcher->fetch($replication);

            $this->transition($replication, SiteThemeReplication::STATUS_EXTRACTING, 'extracting', __('admin.theme_replication.log.extracting'));
            $analysis = $this->analyzer->analyze($replication, $references);
        }

        if ($feedback !== null && $feedback !== '') {
            $analysis['iteration'] = [
                'feedback' => $feedback,
                'previous_version' => (int) $replication->current_version,
                'requested_at' => now()->toIso8601String(),
            ];
        }

        $this->transition($replication, SiteThemeReplication::STATUS_ANALYZING, 'analyzing', __('admin.theme_replication.log.analyzing'));
        $blueprint = $this->agent->generateBlueprint($replication, $analysis, $feedback);

        $this->transition($replication, SiteThemeReplication::STATUS_GENERATING, 'generating', __('admin.theme_replication.log.generating'));
        $versionNumber = max(1, (int) $replication->current_version + 1);
        $files = $this->writer->write($replication, $versionNumber, $blueprint);

        $this->transition($replication, SiteThemeReplication::STATUS_SCANNING, 'scanning', __('admin.theme_replication.log.scanning'));
        $complianceReport = $this->guard->scan($files);
        if (empty($complianceReport['passed'])) {
            $this->fail($replication, __('admin.theme_replication.error.compliance_failed'), $complianceReport);

            return;
        }

        SiteThemeReplicationVersion::query()->create([
            'replication_id' => (int) $replication->id,
            'version' => $versionNumber,
            'prompt_hash' => hash('sha256', json_encode([
                'analysis' => $analysis,
                'feedback' => $feedback,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            'feedback' => $feedback,
            'blueprint_json' => $blueprint,
            'files_json' => $files,
            'compliance_report_json' => $complianceReport,
            'draft_views_path' => (string) ($files['views_path'] ?? ''),
            'draft_assets_path' => (string) ($files['assets_path'] ?? ''),
        ]);

        $previewSnapshot = [
            'version' => $versionNumber,
            'pages' => ['home', 'category', 'article'],
            'ready_at' => now()->toIso8601String(),
        ];

        $sourceFingerprints = (array) $replication->source_fingerprints;
        if ($references !== []) {
            $sourceFingerprints = array_merge($sourceFingerprints, [
                'fetched' => $this->referenceFingerprint($references),
            ]);
        }

        $replication->forceFill([
            'status' => SiteThemeReplication::STATUS_READY,
            'source_fingerprints' => $sourceFingerprints,
            'analysis_json' => $analysis,
            'generated_files_json' => $files,
            'preview_snapshot_json' => $previewSnapshot,
            'current_version' => $versionNumber,
            'compliance_status' => 'passed',
            'compliance_report_json' => $complianceReport,
            'iteration_count' => $feedback !== null && $feedback !== '' ? (int) $replication->iteration_count + 1 : (int) $replication->iteration_count,
            'error_message' => null,
        ])->save();

        $this->replicationService->log($replication, 'info', 'ready', __('admin.theme_replication.log.ready'), [
            'version' => $versionNumber,
            'files' => count((array) ($files['files'] ?? [])),
            'iterated' => $feedback !== null && $feedback !== '',
        ]);
    }

    private function transition(SiteThemeReplication $replication, string $status, string $step, string $message): void
    {
        $replication->forceFill([
            'status' => $status,
            'error_message' => null,
        ])->save();

        $this->replicationService->log($replication, 'info', $step, $message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fail(SiteThemeReplication $replication, string $message, array $context = []): void
    {
        $hasComplianceViolations = isset($context['violations']) && is_array($context['violations']);

        $replication->forceFill([
            'status' => SiteThemeReplication::STATUS_FAILED,
            'error_message' => mb_substr($message, 0, 2000),
            'compliance_status' => $hasComplianceViolations ? 'failed' : (string) ($replication->compliance_status ?: 'pending'),
            'compliance_report_json' => $hasComplianceViolations ? $context : $replication->compliance_report_json,
        ])->save();

        $this->replicationService->log($replication, 'error', 'failed', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $references
     * @return array<string, mixed>
     */
    private function referenceFingerprint(array $references): array
    {
        $pages = [];
        foreach ((array) ($references['pages'] ?? []) as $type => $page) {
            $pages[$type] = [
                'url' => (string) ($page['url'] ?? ''),
                'title' => (string) ($page['title'] ?? ''),
                'html_size' => (int) ($page['html_size'] ?? 0),
                'css_count' => count((array) ($page['css'] ?? [])),
            ];
        }

        return [
            'pages' => $pages,
            'fetched_at' => (string) ($references['fetched_at'] ?? now()->toIso8601String()),
        ];
    }
}
