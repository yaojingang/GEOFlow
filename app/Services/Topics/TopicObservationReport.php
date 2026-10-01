<?php

namespace App\Services\Topics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Offline evidence ledger. Never treats a brand/domain match as a page citation. */
final class TopicObservationReport
{
    public const SURFACES = ['google_ai_overview', 'google_ai_mode', 'gemini', 'chatgpt_search', 'perplexity'];

    public function template(): array
    {
        $start = CarbonImmutable::now()->startOfDay();

        return ['version' => 1, 'protocol' => ['frozen_at' => now()->toIso8601String(), 'timezone' => config('app.timezone'), 'language' => 'zh-CN', 'region' => 'CN', 'baseline_start' => $start->toDateString(), 'baseline_end' => $start->addDays(6)->toDateString(), 'observation_start' => $start->addDays(7)->toDateString(), 'observation_end' => $start->addDays(34)->toDateString(), 'notes' => '同主题实验组和保持组配对；每表面、每窗口、每问法使用独立会话重复三次。'],
            'questions' => array_map(fn ($intent, $index) => ['id' => 'q'.($index + 1), 'intent' => $intent, 'text' => '请填写并冻结本主题的'.$intent.'问法', 'holdout' => $index === 4], ['definition', 'comparison', 'steps', 'freshness', 'scope'], array_keys(['definition', 'comparison', 'steps', 'freshness', 'scope'])),
            'pages' => [], 'index_checks' => [], 'samples' => []];
    }

    public function build(array $input): array
    {
        $data = Validator::make($input, [
            'version' => ['required', 'in:1'], 'protocol' => ['required', 'array:frozen_at,timezone,language,region,baseline_start,baseline_end,observation_start,observation_end,notes'],
            'protocol.frozen_at' => ['required', 'date'], 'protocol.timezone' => ['nullable', 'timezone'], 'protocol.language' => ['required', 'string', 'max:30'], 'protocol.region' => ['required', 'string', 'max:80'],
            'protocol.baseline_start' => ['required', 'date_format:Y-m-d'], 'protocol.baseline_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:protocol.baseline_start'], 'protocol.observation_start' => ['required', 'date_format:Y-m-d', 'after:protocol.baseline_end'], 'protocol.observation_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:protocol.observation_start'],
            'questions' => ['required', 'array', 'min:5', 'max:500'], 'questions.*' => ['array:id,intent,text,holdout'], 'questions.*.id' => ['required', 'string', 'max:100', 'distinct'], 'questions.*.intent' => ['required', 'in:definition,comparison,steps,freshness,scope'], 'questions.*.text' => ['required', 'string', 'max:2000'], 'questions.*.holdout' => ['required', 'boolean'],
            'pages' => ['present', 'array', 'max:1000'], 'pages.*' => ['array:id,pair_id,group,canonical_url,source_revision,source_revisions,notes'], 'pages.*.id' => ['required', 'string', 'max:100', 'distinct'], 'pages.*.pair_id' => ['required', 'string', 'max:100'], 'pages.*.group' => ['required', 'in:experiment,control'], 'pages.*.canonical_url' => ['required', 'url:http,https', 'max:2048', 'distinct'], 'pages.*.source_revision' => ['required', 'string', 'max:200'], 'pages.*.source_revisions' => ['nullable', 'array', 'max:200'], 'pages.*.source_revisions.*' => ['string', 'max:200'],
            'index_checks' => ['present', 'array', 'max:10000'], 'index_checks.*' => ['array:page_id,checked_at,status,evidence'], 'index_checks.*.page_id' => ['required', 'string'], 'index_checks.*.checked_at' => ['required', 'date'], 'index_checks.*.status' => ['required', 'in:indexed,not_indexed,unverified'], 'index_checks.*.evidence' => ['nullable', 'string', 'max:5000'],
            'samples' => ['present', 'array', 'max:10000'], 'samples.*' => ['array:id,page_id,question_id,surface,window_id,window,replicate,sampled_at,model_version,language,region,login_mode,search_mode,session_id,status,reason,retrieval_completed,appeared_in_search,ai_search_triggered,answer,evidence,citations,content_changes'],
            'samples.*.id' => ['required', 'string', 'distinct'], 'samples.*.page_id' => ['required', 'string'], 'samples.*.question_id' => ['required', 'string'], 'samples.*.surface' => ['required', 'in:'.implode(',', self::SURFACES)], 'samples.*.window' => ['required', 'in:baseline,observation'], 'samples.*.window_id' => ['required', 'string', 'max:100'], 'samples.*.replicate' => ['required', 'integer', 'between:1,3'], 'samples.*.sampled_at' => ['required', 'date'],
            'samples.*.model_version' => ['required', 'string', 'max:200'], 'samples.*.language' => ['required', 'string', 'max:30'], 'samples.*.region' => ['required', 'string', 'max:80'], 'samples.*.login_mode' => ['required', 'string', 'max:100'], 'samples.*.search_mode' => ['required', 'string', 'max:100'], 'samples.*.session_id' => ['required', 'string', 'max:200'],
            'samples.*.status' => ['required', 'in:valid,no_ai_answer,timeout,blocked,unavailable,pending'], 'samples.*.reason' => ['nullable', 'string', 'max:2000'], 'samples.*.retrieval_completed' => ['required', 'boolean'], 'samples.*.appeared_in_search' => ['required', 'boolean'], 'samples.*.ai_search_triggered' => ['required', 'boolean'], 'samples.*.answer' => ['nullable', 'string', 'max:50000'], 'samples.*.evidence' => ['nullable', 'string', 'max:5000'], 'samples.*.citations' => ['present', 'array', 'max:100'],
            'samples.*.citations.*' => ['array:url,canonical_url,resolution_evidence,claim_text,source_revision,fragment,review_status,used_content,usage_evidence'],
            'samples.*.citations.*.url' => ['required', 'url:http,https', 'max:2048'], 'samples.*.citations.*.canonical_url' => ['required', 'url:http,https', 'max:2048'], 'samples.*.citations.*.resolution_evidence' => ['nullable', 'string', 'max:5000'], 'samples.*.citations.*.claim_text' => ['required', 'string', 'max:5000'], 'samples.*.citations.*.source_revision' => ['required', 'string', 'max:200'],
            'samples.*.citations.*.review_status' => ['required', 'in:supported,unsupported,unreadable,unreviewed'], 'samples.*.citations.*.used_content' => ['required', 'boolean'], 'samples.*.citations.*.usage_evidence' => ['nullable', 'string', 'max:5000'], 'samples.*.citations.*.fragment' => ['nullable', 'array:field,start,end,sha256,text'], 'samples.*.citations.*.fragment.field' => ['required_with:samples.*.citations.*.fragment', 'string'], 'samples.*.citations.*.fragment.start' => ['required_with:samples.*.citations.*.fragment', 'integer', 'min:0'], 'samples.*.citations.*.fragment.end' => ['required_with:samples.*.citations.*.fragment', 'integer', 'min:1'], 'samples.*.citations.*.fragment.sha256' => ['required_with:samples.*.citations.*.fragment', 'regex:/^[a-f0-9]{64}$/D'], 'samples.*.citations.*.fragment.text' => ['required_with:samples.*.citations.*.fragment', 'string', 'max:2000'],
        ])->validate();
        $this->assertProtocol($data);
        $pages = collect($data['pages'])->keyBy('id');
        $questions = collect($data['questions'])->keyBy('id');
        $rows = [];
        $seen = [];
        $sessions = [];
        foreach ($data['samples'] as $index => $sample) {
            $page = $pages->get($sample['page_id']);
            $question = $questions->get($sample['question_id']);
            if (! $page || ! $question) {
                $this->invalid('samples.'.$index, '观测必须对应已登记页面及冻结问法。');
            }
            $zone = $data['protocol']['timezone'] ?? config('app.timezone');
            $sampled = CarbonImmutable::parse($sample['sampled_at'])->setTimezone($zone);
            $frozen = CarbonImmutable::parse($data['protocol']['frozen_at']);
            $start = CarbonImmutable::parse($data['protocol'][$sample['window'].'_start'], $zone)->startOfDay();
            $end = CarbonImmutable::parse($data['protocol'][$sample['window'].'_end'], $zone)->addDay()->startOfDay();
            $expectedWindow = 'week'.(intdiv((int) $start->diffInDays($sampled->startOfDay(), false), 7) + 1);
            if ($sampled->lessThan($frozen) || $sampled->lessThan($start) || $sampled->greaterThanOrEqualTo($end) || $sample['window_id'] !== $expectedWindow || ($sample['status'] !== 'pending' && $sampled->isFuture())) {
                $this->invalid('samples.'.$index, '采样时间、冻结记录和每七天窗口需一致；窗口编号使用 week1、week2 等。');
            }
            if ($sample['language'] !== $data['protocol']['language'] || $sample['region'] !== $data['protocol']['region']) {
                $this->invalid('samples.'.$index, '同一试点需保持语言和地区一致。');
            }
            $identity = implode(':', [$sample['page_id'], $sample['question_id'], $sample['surface'], $sample['window'], $sample['window_id'], $sample['replicate']]);
            if (isset($seen[$identity]) || isset($sessions[$sample['surface'].':'.$sample['session_id']])) {
                $this->invalid('samples.'.$index, '重复采样或复用会话，请保留独立会话记录。');
            }$seen[$identity] = true;
            $sessions[$sample['surface'].':'.$sample['session_id']] = true;
            $key = implode(':', [$sample['window'], $sample['window_id'], $sample['surface'], $page['group'], $question['holdout'] ? 'holdout' : 'tuning', $question['intent'], $sample['login_mode'], $sample['search_mode'], $sample['model_version']]);
            $rows[$key] ??= ['window' => $sample['window'], 'window_id' => $sample['window_id'], 'surface' => $sample['surface'], 'group' => $page['group'], 'question_set' => $question['holdout'] ? 'holdout' : 'tuning', 'intent' => $question['intent'], 'login_mode' => $sample['login_mode'], 'search_mode' => $sample['search_mode'], 'model_version' => $sample['model_version'], 'samples' => 0, 'completed_samples' => 0, 'valid_retrievals' => 0, 'search_appearances' => 0, 'valid_answers' => 0, 'ai_search_answers' => 0, 'cited_answers' => 0, 'observed_use_answers' => 0, 'reviewed_claim_pairs' => 0, 'supported_claim_pairs' => 0, 'unreadable_claim_pairs' => 0, 'unreviewed_claim_pairs' => 0, 'excluded' => []];
            $row = &$rows[$key];
            $row['samples']++;
            if (in_array($sample['status'], ['valid', 'no_ai_answer'], true)) {
                if (trim($sample['evidence'] ?? '') === '') {
                    $this->invalid('samples.'.$index, '完成采样需要保留平台观测证据。');
                }$row['completed_samples']++;
                $row['ai_search_answers'] += (int) $sample['ai_search_triggered'];
            }
            if (in_array($sample['status'], ['valid', 'no_ai_answer'], true) && $sample['retrieval_completed']) {
                $row['valid_retrievals']++;
                $row['search_appearances'] += (int) $sample['appeared_in_search'];
            }
            if ($sample['status'] !== 'valid') {
                $row['excluded'][$sample['status']] = ($row['excluded'][$sample['status']] ?? 0) + 1;

                continue;
            }
            if (trim($sample['answer'] ?? '') === '' || trim($sample['evidence'] ?? '') === '') {
                $this->invalid('samples.'.$index, '有效答案必须保留回答和观测证据。');
            }
            $row['valid_answers']++;
            $cited = false;
            $used = false;
            $claimPairs = [];
            foreach ($sample['citations'] as $ci => $citation) {
                if ($citation['url'] !== $citation['canonical_url'] && trim($citation['resolution_evidence'] ?? '') === '') {
                    $this->invalid('samples.'.$index.'.citations.'.$ci, 'URL 归一必须保留实际重定向或 canonical 证据。');
                }
                if ($this->url($citation['canonical_url']) !== $this->url($page['canonical_url'])) {
                    continue;
                }$cited = true;
                $review = $citation['review_status'];
                $pair = hash('sha256', json_encode([$this->url($citation['canonical_url']), preg_replace('/\s+/u', ' ', trim($citation['claim_text'])), $citation['source_revision']], JSON_THROW_ON_ERROR));
                if (isset($claimPairs[$pair])) {
                    if ($claimPairs[$pair] !== $review) {
                        $this->invalid('samples.'.$index.'.citations.'.$ci, '同一引用陈述出现冲突的复核结果，请合并为一条记录。');
                    }

                    continue;
                }$claimPairs[$pair] = $review;
                if (in_array($review, ['supported', 'unsupported'], true)) {
                    if (! in_array($citation['source_revision'], array_merge([$page['source_revision']], $page['source_revisions'] ?? []), true)) {
                        $this->invalid('samples.'.$index.'.citations.'.$ci, '引用复核应绑定登记的来源版本，版本变化请新增观察记录。');
                    }
                    $fragment = $citation['fragment'] ?? null;
                    if (! $fragment || $fragment['end'] <= $fragment['start'] || mb_strlen($fragment['text']) !== $fragment['end'] - $fragment['start'] || ! hash_equals(hash('sha256', $fragment['text']), $fragment['sha256'])) {
                        $this->invalid('samples.'.$index.'.citations.'.$ci, '已复核陈述需要可定位且校验一致的原文片段。');
                    }
                    $row['reviewed_claim_pairs']++;
                    $row['supported_claim_pairs'] += (int) ($review === 'supported');
                } else {
                    $row[$review.'_claim_pairs']++;
                }
                if ($citation['used_content']) {
                    if (trim($citation['usage_evidence'] ?? '') === '') {
                        $this->invalid('samples.'.$index.'.citations.'.$ci, '观测使用需记录具体使用的事实、步骤或边界。');
                    }$used = $used || $review === 'supported';
                }
            }
            $row['cited_answers'] += (int) $cited;
            $row['observed_use_answers'] += (int) ($cited && $used);
            unset($row);
        }
        $indices = [];
        foreach ($data['index_checks'] as $check) {
            if (! $pages->has($check['page_id'])) {
                $this->invalid('index_checks', '索引核验必须对应登记页面。');
            }if ($check['status'] !== 'unverified' && trim($check['evidence'] ?? '') === '') {
                $this->invalid('index_checks', '索引状态必须有目标平台证据。');
            }$prior = $indices[$check['page_id']] ?? null;
            if (! $prior || strtotime($check['checked_at']) > strtotime($prior['checked_at'])) {
                $indices[$check['page_id']] = $check;
            }
        }
        $checked = collect($indices)->whereIn('status', ['indexed', 'not_indexed']);
        foreach ($rows as &$row) {
            $row['metrics'] = ['retrieval_appearance' => $this->rate($row['search_appearances'], $row['valid_retrievals']), 'citation_selection' => $this->rate($row['cited_answers'], $row['valid_answers']), 'citation_support' => $this->rate($row['supported_claim_pairs'], $row['reviewed_claim_pairs']), 'observed_use' => $this->rate($row['observed_use_answers'], $row['cited_answers']), 'search_trigger' => $this->rate($row['ai_search_answers'], $row['completed_samples'])];
        }unset($row);

        return ['version' => 1, 'generated_at' => now()->toIso8601String(), 'protocol' => $data['protocol'], 'planned_pages' => $pages->count(), 'index_coverage' => $this->rate($checked->where('status', 'indexed')->count(), $checked->count()), 'unverified_pages' => $pages->count() - $checked->count(), 'results' => array_values($rows), 'limitations' => ['此报告计算提供的观测记录；URL 引用与同域或品牌出现分别处理。', '各搜索表面、意图、窗口、实验组与保持组、留出问法分别报告；重复采样存在相关性。', '观测使用是代理指标；无法推断页面的因果贡献。', '外部索引及真实平台采样需要实际证据，空样本显示暂无可计算结果。']];
    }

    private function assertProtocol(array $data): void
    {
        $protocol = $data['protocol'];
        $baseline = CarbonImmutable::parse($protocol['baseline_start']);
        $baselineEnd = CarbonImmutable::parse($protocol['baseline_end']);
        $observation = CarbonImmutable::parse($protocol['observation_start']);
        $observationEnd = CarbonImmutable::parse($protocol['observation_end']);
        if ($baseline->diffInDays($baselineEnd) < 6 || $observation->diffInDays($observationEnd) < 27) {
            $this->invalid('protocol', '基线至少七天，观察期至少二十八天；可先保留空采样记录。');
        }
        $intents = array_values(array_unique(array_column($data['questions'], 'intent')));
        if (array_diff(['definition', 'comparison', 'steps', 'freshness', 'scope'], $intents) !== [] || count(array_filter($data['questions'], fn ($q) => (bool) $q['holdout'])) * 5 !== count($data['questions'])) {
            $this->invalid('questions', '冻结问法需覆盖五种意图，并保留 20% 问法用于评估。');
        }
    }

    private function rate(int $numerator, int $denominator): array
    {
        return ['numerator' => $numerator, 'denominator' => $denominator, 'rate' => $denominator ? round($numerator / $denominator, 6) : null, 'display' => $denominator ? ($numerator.' / '.$denominator) : '暂无可计算结果'];
    }

    private function url(string $url): string
    {
        $parts = parse_url($url);

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
