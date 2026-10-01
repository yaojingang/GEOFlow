<?php

namespace Tests\Feature\Topics;

use App\Services\Topics\TopicObservationReport;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class TopicObservationReportTest extends TestCase
{
    public function test_empty_ledger_reports_missing_samples_as_unavailable(): void
    {
        $service = app(TopicObservationReport::class);
        $report = $service->build($service->template());
        $this->assertNull($report['index_coverage']['rate']);
        $this->assertSame('暂无可计算结果', $report['index_coverage']['display']);
        $this->assertSame([], $report['results']);
    }

    public function test_page_citations_failures_and_support_use_their_own_denominators(): void
    {
        $data = $this->ledger();
        $data['samples'] = [$this->sample('valid', 1), $this->sample('no_ai_answer', 2), $this->sample('timeout', 3)];
        $data['samples'][2]['retrieval_completed'] = false;
        $data['samples'][2]['appeared_in_search'] = false;
        $report = app(TopicObservationReport::class)->build($data);
        $row = $report['results'][0];
        $this->assertSame(3, $row['samples']);
        $this->assertSame(2, $row['valid_retrievals']);
        $this->assertSame(1, $row['valid_answers']);
        $this->assertSame(1, $row['cited_answers']);
        $this->assertSame(1, $row['supported_claim_pairs']);
        $this->assertSame(1, $row['observed_use_answers']);
        $this->assertSame(['no_ai_answer' => 1, 'timeout' => 1], $row['excluded']);
        $this->assertSame(1, $report['index_coverage']['denominator']);
    }

    public function test_another_page_on_same_domain_is_not_a_topic_citation(): void
    {
        $data = $this->ledger();
        $sample = $this->sample('valid', 1);
        $sample['citations'][0]['url'] = 'https://example.test/other';
        $sample['citations'][0]['canonical_url'] = 'https://example.test/other';
        $data['samples'] = [$sample];
        $report = app(TopicObservationReport::class)->build($data);
        $this->assertSame(0, $report['results'][0]['cited_answers']);
        $this->assertNull($report['results'][0]['metrics']['observed_use']['rate']);
    }

    public function test_reused_sessions_cannot_inflate_the_sample(): void
    {
        $data = $this->ledger();
        $data['samples'] = [$this->sample('valid', 1), $this->sample('valid', 2)];
        $data['samples'][1]['session_id'] = $data['samples'][0]['session_id'];
        $this->expectException(ValidationException::class);
        app(TopicObservationReport::class)->build($data);
    }

    public function test_unproved_canonical_mapping_is_rejected(): void
    {
        $data = $this->ledger();
        $sample = $this->sample('valid', 1);
        $sample['citations'][0]['url'] = 'https://example.test/alias';
        $data['samples'] = [$sample];
        $this->expectException(ValidationException::class);
        app(TopicObservationReport::class)->build($data);
    }

    public function test_report_command_uses_real_json_input_and_never_overwrites_output(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'topic-ledger-');
        $output = $file.'.report.json';
        try {
            file_put_contents($file, json_encode($this->ledger()));
            $this->artisan('topics:observations-report', ['input' => $file, '--output' => $output])->assertSuccessful();
            $this->assertSame(1, json_decode(file_get_contents($output), true)['index_coverage']['numerator']);
            $this->artisan('topics:observations-report', ['input' => $file, '--output' => $output])->expectsOutput('输出文件已存在或目录不可写，请选择新文件。')->assertFailed();
        } finally {
            unlink($file);
            if (is_file($output)) {
                unlink($output);
            }
        }
    }

    private function ledger(): array
    {
        $data = app(TopicObservationReport::class)->template();
        $data['protocol'] = array_replace($data['protocol'], ['frozen_at' => now()->subDays(36)->toIso8601String(), 'baseline_start' => now()->subDays(35)->toDateString(), 'baseline_end' => now()->subDays(29)->toDateString(), 'observation_start' => now()->subDays(28)->toDateString(), 'observation_end' => now()->subDay()->toDateString()]);
        $data['pages'] = [['id' => 'p1', 'pair_id' => 'pair1', 'group' => 'experiment', 'canonical_url' => 'https://example.test/topics/guide', 'source_revision' => 'revision-1']];
        $data['index_checks'] = [['page_id' => 'p1', 'checked_at' => now()->toIso8601String(), 'status' => 'indexed', 'evidence' => 'URL 检查平台截图编号 index-1']];

        return $data;
    }

    private function sample(string $status, int $replicate): array
    {
        $text = '来源事实';

        return ['id' => 'sample-'.$replicate, 'page_id' => 'p1', 'question_id' => 'q1', 'surface' => 'chatgpt_search', 'window' => 'observation', 'window_id' => 'week4', 'replicate' => $replicate, 'sampled_at' => now()->subDay()->toIso8601String(), 'model_version' => 'recorded-version', 'language' => 'zh-CN', 'region' => 'CN', 'login_mode' => 'signed-in', 'search_mode' => 'search', 'session_id' => 'session-'.$replicate, 'status' => $status, 'retrieval_completed' => true, 'appeared_in_search' => true, 'ai_search_triggered' => true, 'answer' => $status === 'valid' ? '引用来源的答案' : '', 'evidence' => 'observation-screenshot-1', 'citations' => $status === 'valid' ? [['url' => 'https://example.test/topics/guide', 'canonical_url' => 'https://example.test/topics/guide', 'claim_text' => '来源支持的陈述', 'source_revision' => 'revision-1', 'review_status' => 'supported', 'used_content' => true, 'usage_evidence' => '回答使用来源中的步骤', 'fragment' => ['field' => 'content', 'start' => 0, 'end' => mb_strlen($text), 'text' => $text, 'sha256' => hash('sha256', $text)]]] : []];
    }

    public function test_two_phases_use_independent_week_one_samples(): void
    {
        $data = $this->ledger();
        $first = $this->sample('valid', 1);
        $first['window'] = 'baseline';
        $first['window_id'] = 'week1';
        $first['sampled_at'] = now()->subDays(34)->toIso8601String();
        $second = $this->sample('valid', 1);
        $second['id'] = 'second-phase';
        $second['session_id'] = 'second-session';
        $second['window_id'] = 'week1';
        $second['sampled_at'] = now()->subDays(27)->toIso8601String();
        $data['samples'] = [$first, $second];
        $report = app(TopicObservationReport::class)->build($data);
        $this->assertCount(2, $report['results']);
    }

    public function test_duplicate_claims_and_no_ai_answers_preserve_real_denominators(): void
    {
        $data = $this->ledger();
        $a = $this->sample('valid', 1);
        $a['citations'][] = $a['citations'][0];
        $b = $this->sample('no_ai_answer', 2);
        $b['ai_search_triggered'] = false;
        $c = $this->sample('no_ai_answer', 3);
        $c['ai_search_triggered'] = false;
        $data['samples'] = [$a, $b, $c];
        $row = app(TopicObservationReport::class)->build($data)['results'][0];
        $this->assertSame(1, $row['reviewed_claim_pairs']);
        $this->assertSame(3, $row['metrics']['search_trigger']['denominator']);
        $this->assertSame(1, $row['metrics']['search_trigger']['numerator']);
    }

    public function test_samples_outside_their_frozen_window_are_rejected(): void
    {
        $data = $this->ledger();
        $sample = $this->sample('valid', 1);
        $sample['window'] = 'baseline';
        $data['samples'] = [$sample];
        $this->expectException(ValidationException::class);
        app(TopicObservationReport::class)->build($data);
    }
}
