@extends('theme.geoflow-template-21-enterprise-signature.layout')

@section('bodyClass', 'ent-body--article ent-body--about')

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
            'mainEntity' => [
                $schemaAtType => 'SoftwareApplication',
                'name' => 'GEOFlow',
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem' => 'Web',
                'url' => $repositoryUrl,
            ],
        ];
    @endphp
    <x-json-ld :data="$aboutSchema" />
@endpush

@section('content')
    <article class="ent-article ent-about">
        <header class="ent-article-hero ent-about-hero">
            <div class="ent-article-shell">
                <span class="ent-about-hero__label">开源项目</span>
                <h1>关于 GEOFlow</h1>
                <p class="ent-article-hero__excerpt">把可信知识、AI 内容工程与多站点分发连接起来，为持续运营的 GEO 内容资产提供一套开放的工作流。</p>
                <div class="ent-about-hero__actions">
                    <a href="#about-purpose" class="ent-text-link">了解项目 <i data-lucide="arrow-down" aria-hidden="true"></i></a>
                    <a href="{{ $repositoryUrl }}" class="ent-text-link" target="_blank" rel="noopener noreferrer">GitHub 仓库 <i data-lucide="arrow-up-right" aria-hidden="true"></i></a>
                </div>
            </div>
        </header>

        <div class="ent-article-layout ent-about-layout">
            <div id="ent-article-content" class="ent-prose ent-about-prose" data-ent-article-content>
                @include('site.partials.about-content')
            </div>

            <aside class="ent-article-toc" data-ent-article-toc aria-labelledby="ent-about-toc-title" hidden>
                <h2 id="ent-about-toc-title">页面目录</h2>
                <nav aria-label="关于页面段落" data-ent-toc-list></nav>
            </aside>
        </div>
    </article>
@endsection
