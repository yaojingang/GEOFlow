@extends('admin.layouts.app')

@php
    $statusLabels = [
        'draft' => '草稿',
        'ready' => '待转文章',
        'converted' => '已转文章',
    ];
    $brief = (array) ($draft->writingTask?->brief ?? []);
    $briefSource = match ($brief['source'] ?? '') {
        'reference_content' => '参考内容简报',
        'reference_imitation' => '结构仿写草稿',
        default => 'GEO 诊断报告',
    };
    $referenceCount = count((array) ($brief['references'] ?? []));
    $latestAudit = $draft->audits->first();
    $hasReport = ! empty($report);
    $backUrl = $backUrl ?? ($hasReport ? route('admin.geo.reports.show', ['taskId' => (int) $task->id]) : route('admin.geo.citation-sources.index'));
    $backLabel = $backLabel ?? ($hasReport ? '返回诊断报告' : '返回引用来源');
    $updateRoute = $updateRoute ?? ($hasReport ? route('admin.geo.reports.article-drafts.update', ['taskId' => (int) $task->id, 'draftId' => (int) $draft->id]) : route('admin.geo.article-drafts.update', ['draftId' => (int) $draft->id]));
    $convertRoute = $convertRoute ?? ($hasReport ? route('admin.geo.reports.article-drafts.convert', ['taskId' => (int) $task->id, 'draftId' => (int) $draft->id]) : route('admin.geo.article-drafts.convert', ['draftId' => (int) $draft->id]));
    $publishableRoute = $publishableRoute ?? null;
    $layoutRoute = $layoutRoute ?? null;
    $visualPackRoute = $visualPackRoute ?? null;
    $visualInsertRoute = $visualInsertRoute ?? null;
    $publishPackageRoute = $publishPackageRoute ?? null;
    $yixiaoerDistributeRoute = $yixiaoerDistributeRoute ?? null;
    $visualPackage = (array) ($brief['visual_publish_package'] ?? []);
    $visualItems = (array) ($visualPackage['items'] ?? []);
    $visualSop = (array) ($visualPackage['publish_sop'] ?? []);
    $publishPackage = (array) ($brief['publish_package'] ?? []);
    $latestYixiaoerRecord = $draft->publishRecords
        ->first(fn ($record) => $record->publishTarget?->type === 'yixiaoer');
    $yixiaoerStatusLabels = [
        'submitted' => '已提交蚁小二',
        'published' => '分发成功',
        'partial_success' => '部分成功',
        'failed' => '分发失败',
    ];
    $previewMarkdown = (string) old('content_markdown', $draft->content_markdown);
    $previewHtml = \App\Support\Site\ArticleHtmlPresenter::markdownToHtml($previewMarkdown);
    $sourceTitle = (string) ($report?->title ?? $brief['source_title'] ?? $draft->writingTask?->title ?? '引用来源仿写');
    $sourceScore = (int) ($report?->total_score ?? $brief['source_score'] ?? 0);
    $failedChecks = (array) ($latestAudit?->failed_checks ?? []);
    $hasBlockingFailure = count(array_intersect($failedChecks, ['forbidden_terms', 'brand_mentioned'])) > 0;
    $readinessLabel = $hasBlockingFailure
        ? '禁止发布'
        : (($latestAudit && (int) $latestAudit->score >= 80 && (($brief['source'] ?? '') !== 'reference_content' || $referenceCount > 0)) ? '可发布' : '需要补充');
    $readinessClass = match ($readinessLabel) {
        '可发布' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        '禁止发布' => 'bg-red-50 text-red-700 border-red-200',
        default => 'bg-amber-50 text-amber-700 border-amber-200',
    };
@endphp

@push('styles')
    <style>
        .geo-draft-preview {
            color: #1f2937;
            font-size: 15px;
            line-height: 1.85;
        }

        .geo-draft-preview h1 {
            margin: 0 0 18px;
            color: #111827;
            font-size: 26px;
            line-height: 1.3;
            font-weight: 800;
        }

        .geo-draft-preview h2 {
            margin: 28px 0 12px;
            border-left: 4px solid #2563eb;
            padding-left: 12px;
            color: #111827;
            font-size: 20px;
            line-height: 1.4;
            font-weight: 800;
        }

        .geo-draft-preview h3 {
            margin: 22px 0 10px;
            color: #111827;
            font-size: 17px;
            line-height: 1.45;
            font-weight: 700;
        }

        .geo-draft-preview p,
        .geo-draft-preview ul,
        .geo-draft-preview ol,
        .geo-draft-preview blockquote,
        .geo-draft-preview table {
            margin: 14px 0;
        }

        .geo-draft-preview blockquote {
            border-left: 4px solid #d1d5db;
            padding: 10px 0 10px 14px;
            color: #4b5563;
            background: #f9fafb;
        }

        .geo-draft-preview img {
            display: block;
            max-width: min(100%, 820px);
            height: auto;
            margin: 22px auto;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
        }

        .geo-draft-preview .article-table-wrap {
            max-width: 100%;
            overflow-x: auto;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }

        .geo-draft-preview table,
        .geo-draft-preview .article-table {
            width: max(680px, 100%);
            border-collapse: collapse;
            font-size: 14px;
            line-height: 1.65;
        }

        .geo-draft-preview th,
        .geo-draft-preview td {
            border: 1px solid #e5e7eb;
            padding: 10px 12px;
            vertical-align: top;
        }

        .geo-draft-preview th {
            background: #f3f4f6;
            font-weight: 800;
        }
    </style>
@endpush

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ $backUrl }}" class="inline-flex items-center gap-1 text-sm font-medium text-blue-600 hover:text-blue-700">
                    <i data-lucide="arrow-left" class="h-4 w-4"></i>
                    {{ $backLabel }}
                </a>
                <h1 class="mt-3 text-2xl font-semibold text-gray-900">编辑文章草稿</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $sourceTitle }} · {{ $organization->name }}</p>
            </div>
            <span class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-600">
                <i data-lucide="file-pen-line" class="h-4 w-4 text-blue-500"></i>
                {{ $statusLabels[$draft->status] ?? $draft->status }}
            </span>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <form method="POST" action="{{ $updateRoute }}" class="space-y-6">
                @csrf
                @method('PUT')

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-gray-900">基础内容</h2>
                    </div>
                    <div class="space-y-5 p-5">
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700">标题</label>
                            <input id="title" name="title" type="text" required value="{{ old('title', $draft->title) }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="summary" class="block text-sm font-medium text-gray-700">摘要</label>
                            <textarea id="summary" name="summary" rows="3" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('summary', $draft->summary) }}</textarea>
                        </div>
                        <div>
                            <label for="content_markdown" class="block text-sm font-medium text-gray-700">正文 Markdown</label>
                            <textarea id="content_markdown" name="content_markdown" rows="20" required class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm leading-6 focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('content_markdown', $draft->content_markdown) }}</textarea>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-gray-900">正文预览</h2>
                    </div>
                    <div class="p-5">
                        @if(trim($previewHtml) !== '')
                            <div class="geo-draft-preview">
                                {!! $previewHtml !!}
                            </div>
                        @else
                            <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center text-sm text-gray-500">暂无正文</div>
                        @endif
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-gray-900">SEO 信息</h2>
                    </div>
                    <div class="space-y-5 p-5">
                        <div>
                            <label for="seo_title" class="block text-sm font-medium text-gray-700">SEO 标题</label>
                            <input id="seo_title" name="seo_title" type="text" value="{{ old('seo_title', $draft->seo_title) }}" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="seo_description" class="block text-sm font-medium text-gray-700">SEO 描述</label>
                            <textarea id="seo_description" name="seo_description" rows="3" class="mt-2 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500">{{ old('seo_description', $draft->seo_description) }}</textarea>
                        </div>
                    </div>
                </section>

                <div class="flex flex-wrap justify-end gap-3">
                    <a href="{{ $backUrl }}" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        取消
                    </a>
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        <i data-lucide="save" class="h-4 w-4"></i>
                        保存草稿
                    </button>
                </div>
            </form>

            <aside class="space-y-4">
                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900">{{ $hasReport ? '来源报告' : '仿写来源' }}</h2>
                    <div class="mt-4 space-y-3 text-sm text-gray-600">
                        <div>
                            <div class="text-xs text-gray-500">{{ $hasReport ? '报告' : '来源标题' }}</div>
                            <div class="mt-1 font-medium text-gray-900">{{ $sourceTitle }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500">{{ $hasReport ? '综合得分' : '来源评分' }}</div>
                            <div class="mt-1 font-medium text-gray-900">{{ $sourceScore > 0 ? $sourceScore : '暂无' }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500">更新时间</div>
                            <div class="mt-1 font-medium text-gray-900">{{ $draft->updated_at?->format('Y-m-d H:i') }}</div>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-900">发布准备</h2>
                        <span class="inline-flex rounded-lg border px-2.5 py-1 text-xs font-medium {{ $readinessClass }}">{{ $readinessLabel }}</span>
                    </div>
                    <div class="mt-4 space-y-3 text-sm text-gray-600">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-gray-500">简报来源</span>
                            <span class="font-medium text-gray-900">{{ $briefSource }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-gray-500">参考来源</span>
                            <span class="font-medium text-gray-900">参考来源 {{ $referenceCount }} 条</span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-gray-500">最近审核</span>
                            <span class="font-medium text-gray-900">{{ $latestAudit ? ((int) $latestAudit->score).' 分' : '未审核' }}</span>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900">配图与发布包</h2>
                            <p class="mt-1 text-xs text-gray-500">{{ $visualItems ? '已生成 '.count($visualItems).' 个配图位' : '先生成封面、信息图、流程图和真实素材位' }}</p>
                        </div>
                        @if($visualItems)
                            <span class="inline-flex rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">已生成</span>
                        @else
                            <span class="inline-flex rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-600">待生成</span>
                        @endif
                    </div>
                    @if($visualPackRoute)
                        <div class="mt-4 space-y-3">
                            <form method="POST" action="{{ $visualPackRoute }}">
                                @csrf
                                <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100">
                                    <i data-lucide="image-plus" class="h-4 w-4"></i>
                                    生成配图与发布包
                                </button>
                            </form>
                            @if($visualItems && $visualInsertRoute)
                                <form method="POST" action="{{ $visualInsertRoute }}">
                                    @csrf
                                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                        <i data-lucide="image" class="h-4 w-4"></i>
                                        植入配图到正文
                                    </button>
                                </form>
                            @endif
                            @if($publishPackageRoute)
                                <form method="POST" action="{{ $publishPackageRoute }}">
                                    @csrf
                                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                                        <i data-lucide="package-check" class="h-4 w-4"></i>
                                        导出发布包
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endif
                    @if($visualItems)
                        <div class="mt-4 space-y-3">
                            @foreach($visualItems as $item)
                                @php
                                    $item = (array) $item;
                                @endphp
                                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <h3 class="text-sm font-semibold text-gray-900">{{ $item['title'] ?? '配图' }}</h3>
                                        <span class="rounded border border-gray-200 bg-white px-2 py-0.5 text-[11px] text-gray-500">{{ $item['source_mode'] ?? 'visual' }}</span>
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500">{{ $item['placement'] ?? '' }}</p>
                                    <p class="mt-2 text-xs leading-5 text-gray-700">{{ \Illuminate\Support\Str::limit((string) ($item['prompt'] ?? ''), 180) }}</p>
                                    @if(! empty($item['safety_note']))
                                        <p class="mt-2 rounded-md bg-white px-2 py-1.5 text-xs leading-5 text-amber-700">{{ $item['safety_note'] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        @if($visualSop)
                            <div class="mt-4 rounded-lg border border-blue-100 bg-blue-50 p-3">
                                <h3 class="text-sm font-semibold text-blue-900">发布 SOP</h3>
                                <ul class="mt-2 space-y-1 text-xs leading-5 text-blue-800">
                                    @foreach($visualSop as $step)
                                        <li>· {{ $step }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endif
                    @if($publishPackage)
                        <div class="mt-4 rounded-lg border border-emerald-100 bg-emerald-50 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-emerald-900">发布包已导出</h3>
                                <span class="rounded border border-emerald-200 bg-white px-2 py-0.5 text-[11px] text-emerald-700">{{ (int) ($publishPackage['image_count'] ?? 0) }} 张图</span>
                            </div>
                            <dl class="mt-2 space-y-1 text-xs leading-5 text-emerald-900">
                                <div>
                                    <dt class="text-emerald-700">Markdown</dt>
                                    <dd class="break-all font-mono">{{ $publishPackage['markdown_path'] ?? '' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-emerald-700">图片目录</dt>
                                    <dd class="break-all font-mono">{{ $publishPackage['image_dir'] ?? '' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-emerald-700">Manifest</dt>
                                    <dd class="break-all font-mono">{{ $publishPackage['manifest_path'] ?? '' }}</dd>
                                </div>
                            </dl>
                        </div>
                    @endif
                    @if($yixiaoerDistributeRoute)
                        <div class="mt-4 rounded-lg border border-purple-100 bg-purple-50 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-purple-900">微信公众号草稿</h3>
                                @if($latestYixiaoerRecord)
                                    <span class="rounded border border-purple-200 bg-white px-2 py-0.5 text-[11px] text-purple-700">{{ $yixiaoerStatusLabels[$latestYixiaoerRecord->status] ?? $latestYixiaoerRecord->status }}</span>
                                @else
                                    <span class="rounded border border-purple-200 bg-white px-2 py-0.5 text-[11px] text-purple-700">公众号文章</span>
                                @endif
                            </div>
                            @if($latestYixiaoerRecord)
                                <dl class="mt-2 space-y-1 text-xs leading-5 text-purple-900">
                                    <div>
                                        <dt class="text-purple-700">任务集</dt>
                                        <dd class="break-all font-mono">{{ $latestYixiaoerRecord->target_url ?: '已提交' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-purple-700">平台</dt>
                                        <dd>{{ collect((array) $latestYixiaoerRecord->platform_codes)->map(fn ($code) => ['weixingongzhonghao' => '微信公众号'][$code] ?? $code)->implode('、') }}</dd>
                                    </div>
                                    @if($latestYixiaoerRecord->error_message)
                                        <div>
                                            <dt class="text-purple-700">异常</dt>
                                            <dd class="whitespace-pre-line">{{ $latestYixiaoerRecord->error_message }}</dd>
                                        </div>
                                    @endif
                                </dl>
                            @endif
                            <form method="POST" action="{{ $yixiaoerDistributeRoute }}" class="mt-3 space-y-3">
                                @csrf
                                <div class="grid gap-2 text-xs text-purple-900">
                                    <label class="flex items-center gap-2 rounded-md border border-purple-100 bg-white px-2 py-2">
                                        <input type="checkbox" name="platform_codes[]" value="weixingongzhonghao" checked class="rounded border-purple-300 text-purple-600 focus:ring-purple-500">
                                        微信公众号
                                    </label>
                                </div>
                                <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700">
                                    <i data-lucide="send" class="h-4 w-4"></i>
                                    提交公众号草稿
                                </button>
                            </form>
                        </div>
                    @endif
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900">文章管理</h2>
                    @if($draft->article)
                        <a href="{{ route('admin.articles.edit', ['articleId' => (int) $draft->article->id]) }}" class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                            <i data-lucide="external-link" class="h-4 w-4"></i>
                            打开文章
                        </a>
                    @else
                        @if($publishableRoute)
                            <form method="POST" action="{{ $publishableRoute }}" class="mt-4">
                                @csrf
                                <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                                    <i data-lucide="sparkles" class="h-4 w-4"></i>
                                    生成可发布正文
                                </button>
                            </form>
                        @endif
                        @if($layoutRoute)
                            <form method="POST" action="{{ $layoutRoute }}" class="mt-3">
                                @csrf
                                <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-800 hover:bg-amber-100">
                                    <i data-lucide="align-left" class="h-4 w-4"></i>
                                    优化正文排版
                                </button>
                            </form>
                        @endif
                        <form method="POST" action="{{ $convertRoute }}" class="mt-4">
                            @csrf
                            <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800">
                                <i data-lucide="send" class="h-4 w-4"></i>
                                转为正式文章
                            </button>
                        </form>
                    @endif
                </section>
            </aside>
        </div>
    </div>
@endsection
