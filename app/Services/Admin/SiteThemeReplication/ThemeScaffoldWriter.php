<?php

namespace App\Services\Admin\SiteThemeReplication;

use App\Models\SiteThemeReplication;
use Illuminate\Support\Facades\Storage;

class ThemeScaffoldWriter
{
    /**
     * @param  array<string, mixed>  $blueprint
     * @return array<string, mixed>
     */
    public function write(SiteThemeReplication $replication, int $version, array $blueprint): array
    {
        $themeId = (string) $replication->theme_id;
        $root = "geoflow-theme-replications/{$replication->id}/draft/{$version}";
        $viewsPath = $root.'/views';
        $assetsPath = $root.'/assets';

        $files = [
            'views/manifest.json' => $this->json([
                'name' => $replication->name,
                'id' => $themeId,
                'version' => 'draft-'.$version,
                'mode' => 'replicated',
                'description' => (string) (($blueprint['theme'] ?? [])['description'] ?? ''),
                'source_reference_url' => [
                    'home' => $replication->home_url,
                    'category' => $replication->category_url,
                    'article' => $replication->article_url,
                ],
                'created_by' => 'GEOFlow Theme Replication',
                'notes' => $blueprint['notes'] ?? [],
            ]),
            'views/tokens.json' => $this->json($blueprint['tokens'] ?? []),
            'views/mapping.json' => $this->json([
                'home' => 'home.blade.php',
                'category' => 'category.blade.php',
                'article' => 'article.blade.php',
                'components' => $blueprint['components'] ?? [],
            ]),
            'views/layout.blade.php' => $this->layoutBlade($themeId),
            'views/home.blade.php' => $this->homeBlade($themeId),
            'views/category.blade.php' => $this->categoryBlade($themeId),
            'views/article.blade.php' => $this->articleBlade($themeId),
            'views/partials/header.blade.php' => $this->headerBlade(),
            'views/partials/footer.blade.php' => $this->footerBlade(),
            'views/partials/article-card.blade.php' => $this->articleCardBlade(),
            'assets/theme.css' => (string) (($blueprint['assets'] ?? [])['theme_css'] ?? ''),
            'assets/theme.js' => (string) (($blueprint['assets'] ?? [])['theme_js'] ?? ''),
        ];

        $fileRecords = [];
        foreach ($files as $relative => $content) {
            $path = $root.'/'.$relative;
            Storage::disk('local')->put($path, $content);
            $fileRecords[] = [
                'path' => $relative,
                'storage_path' => $path,
                'bytes' => strlen($content),
                'checksum' => hash('sha256', $content),
            ];
        }

        return [
            'root_path' => $root,
            'views_path' => $viewsPath,
            'assets_path' => $assetsPath,
            'files' => $fileRecords,
            'written_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<mixed>  $value
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }

    private function layoutBlade(string $themeId): string
    {
        return <<<BLADE
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ \$pageTitle ?? \$siteName }}</title>
    <meta name="description" content="{{ \$pageDescription ?? '' }}">
    @if(!empty(\$siteKeywords))
        <meta name="keywords" content="{{ \$siteKeywords }}">
    @endif
    @if(!empty(\$siteFavicon))
        <link rel="icon" href="{{ \$siteFavicon }}">
    @endif
    <link rel="canonical" href="{{ \$canonicalUrl ?? url()->current() }}">
    @stack('head')
    <link rel="stylesheet" href="{{ \$themeAssetBaseUrl ?? asset('themes/{$themeId}/theme.css') }}">
</head>
<body class="rep-body">
    @include('theme.{$themeId}.partials.header')
    <main>
        @yield('content')
    </main>
    @include('theme.{$themeId}.partials.footer')
    @stack('scripts')
    <script src="{{ \$themeScriptUrl ?? asset('themes/{$themeId}/theme.js') }}" defer></script>
</body>
</html>
BLADE;
    }

    private function homeBlade(string $themeId): string
    {
        return <<<BLADE
@extends('theme.{$themeId}.layout')

@section('content')
    <section class="rep-shell rep-hero">
        <h1>{{ \$siteTitle }}</h1>
        <p>{{ \$siteSubtitle !== '' ? \$siteSubtitle : \$siteDescription }}</p>
    </section>

    <section class="rep-shell rep-grid">
        @forelse(\$articles as \$article)
            @include('theme.{$themeId}.partials.article-card', ['article' => \$article, 'showFeaturedBadge' => false])
        @empty
            <article class="rep-card">
                <h2>{{ __('site.home_empty_title') }}</h2>
                <p class="rep-summary">{{ __('site.home_empty_desc') }}</p>
            </article>
        @endforelse
    </section>

    @if(\$articles->hasPages())
        <div class="rep-shell rep-pagination">
            {{ \$articles->onEachSide(1)->links() }}
        </div>
    @endif
@endsection
BLADE;
    }

    private function categoryBlade(string $themeId): string
    {
        return <<<BLADE
@extends('theme.{$themeId}.layout')

@section('content')
    <section class="rep-shell rep-hero">
        <h1>{{ \$category->name }}</h1>
        @if(trim((string) \$category->description) !== '')
            <p>{{ \$category->description }}</p>
        @endif
    </section>

    <section class="rep-shell rep-grid">
        @forelse(\$articles as \$article)
            @include('theme.{$themeId}.partials.article-card', ['article' => \$article, 'showFeaturedBadge' => false])
        @empty
            <article class="rep-card">
                <h2>{{ __('site.home_empty_title') }}</h2>
                <p class="rep-summary">{{ __('site.home_empty_desc') }}</p>
                <a class="rep-link" href="{{ route('site.home') }}">{{ __('site.back_home') }}</a>
            </article>
        @endforelse
    </section>
@endsection
BLADE;
    }

    private function articleBlade(string $themeId): string
    {
        return <<<BLADE
@extends('theme.{$themeId}.layout')

@section('content')
    <article class="rep-shell rep-detail">
        @if(\$article->category)
            <a class="rep-chip" href="{{ route('site.category', \$article->category->slug) }}">{{ \$article->category->name }}</a>
        @endif
        <h1>{{ \$article->title }}</h1>
        <div class="rep-meta">
            {{ __('site.article_published_on', ['date' => (\$article->published_at ?? \$article->created_at)?->format('Y-m-d') ?? '']) }}
        </div>
        @if(\$excerptPlain !== '')
            <p class="rep-summary">{{ \$excerptPlain }}</p>
        @endif
        <div class="rep-content">
            {!! \$contentHtml !!}
        </div>
        @if(count(\$tags) > 0)
            <div class="rep-tags">
                @foreach(\$tags as \$tag)
                    <span class="rep-chip">{{ \$tag }}</span>
                @endforeach
            </div>
        @endif
    </article>
@endsection
BLADE;
    }

    private function headerBlade(): string
    {
        return <<<'BLADE'
<header class="rep-header">
    <div class="rep-shell rep-header__bar">
        <a class="rep-brand" href="{{ route('site.home') }}">{{ $siteTitle ?? config('app.name') }}</a>
        <nav class="rep-nav">
            <a href="{{ route('site.home') }}">{{ __('front.nav.home') }}</a>
            <a href="{{ route('site.archive') }}">{{ __('site.archive_title') }}</a>
        </nav>
    </div>
</header>
BLADE;
    }

    private function footerBlade(): string
    {
        return <<<'BLADE'
<footer class="rep-footer">
    <div class="rep-shell">
        <div>{{ $siteTitle ?? config('app.name') }}</div>
    </div>
</footer>
BLADE;
    }

    private function articleCardBlade(): string
    {
        return <<<'BLADE'
<article class="rep-card">
    @if(!empty($showFeaturedBadge))
        <div class="rep-chip">{{ __('site.home_featured_badge') }}</div>
    @endif
    <h2>
        <a href="{{ route('site.article', $article->slug) }}">{{ $article->title }}</a>
    </h2>
    @if($article->category)
        <div class="rep-meta">{{ $article->category->name }}</div>
    @endif
    <p class="rep-summary">{{ $cardSummaries[$article->id] ?? '' }}</p>
    <a class="rep-link" href="{{ route('site.article', $article->slug) }}">{{ __('site.home_read_more') }}</a>
</article>
BLADE;
    }
}
