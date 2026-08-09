@extends('site.layout')

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $aboutSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'AboutPage',
            'name' => '关于 GEOFlow',
            'description' => $pageDescription,
            'url' => $canonicalUrl ?? route('site.about'),
        ];
    @endphp
    <x-json-ld :data="$aboutSchema" />
@endpush

@section('content')
    <div class="site-container article-page px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
        <article class="article-shell article-detail-shell">
            <div class="article-detail-pad">
                <header class="article-rail mb-10">
                    <p class="text-sm font-medium text-blue-600 mb-4">开源项目</p>
                    <h1 class="article-hero-title font-semibold text-gray-900 mb-4 leading-tight">关于 GEOFlow</h1>
                    <p class="article-kicker text-gray-600 max-w-3xl">
                        把可信知识、AI 内容工程与多站点分发连接起来，为持续运营的 GEO 内容资产提供一套开放的工作流。
                    </p>
                    <a href="{{ $repositoryUrl }}" class="inline-flex items-center mt-6 text-blue-600 font-medium" target="_blank" rel="noopener noreferrer">
                        GitHub 仓库
                        <i data-lucide="arrow-up-right" class="w-4 h-4 ml-2" aria-hidden="true"></i>
                    </a>
                </header>

                <div class="article-prose article-rail max-w-none">
                    @include('site.partials.about-content')
                </div>
            </div>
        </article>
    </div>
@endsection
