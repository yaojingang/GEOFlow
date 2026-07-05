@php
    $modules = collect($homepageModules ?? [])->filter(fn ($module) => is_array($module) && !empty($module['enabled']))->values();
    $style = array_merge([
        'accent_color' => '#2563eb',
        'background_color' => '#ffffff',
        'surface_color' => '#ffffff',
        'text_color' => '#111827',
        'muted_color' => '#6b7280',
        'container_width' => 'default',
        'section_spacing' => 'normal',
        'radius' => 'soft',
    ], is_array($homepageStyle ?? null) ? $homepageStyle : []);

    $latestArticleCollection = is_object($articles ?? null) && method_exists($articles, 'getCollection')
        ? ($articles->getCollection())
        : collect($articles ?? []);
    $articleCollections = [
        'featured' => collect($featuredArticles ?? []),
        'hot' => collect($hotArticles ?? []),
        'latest' => collect($latestArticleCollection),
    ];
    $leadFormsBySlug = collect($leadForms ?? [])->keyBy(fn ($form) => (string) ($form->slug ?? ''));

    $parseRows = static function (string $body): array {
        return collect(preg_split('/\R/u', trim($body)) ?: [])
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->map(function (string $line): array {
                $parts = array_map('trim', explode('|', $line, 3));

                return [
                    'title' => $parts[0] ?? '',
                    'text' => $parts[1] ?? '',
                    'url' => \App\Support\Site\HomepageModuleBuilder::normalizeUrl((string) ($parts[2] ?? '')),
                ];
            })
            ->values()
            ->all();
    };

    $parseChartRows = static function (string $body): array {
        return collect(preg_split('/\R/u', trim($body)) ?: [])
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->map(function (string $line): array {
                $parts = array_map('trim', explode('|', $line, 3));
                $rawValue = $parts[1] ?? '';
                $numericValue = (float) preg_replace('/[^0-9.\-]/', '', $rawValue);

                return [
                    'label' => $parts[0] ?? '',
                    'value' => $numericValue,
                    'value_label' => $rawValue,
                    'note' => $parts[2] ?? '',
                ];
            })
            ->filter(fn (array $row): bool => $row['label'] !== '')
            ->values()
            ->all();
    };

    $containerClass = match ($style['container_width'] ?? 'default') {
        'narrow' => 'geo-home-modules--narrow',
        'wide' => 'geo-home-modules--wide',
        default => 'geo-home-modules--default',
    };
    $spacingClass = match ($style['section_spacing'] ?? 'normal') {
        'compact' => 'geo-home-modules--compact',
        'relaxed' => 'geo-home-modules--relaxed',
        default => 'geo-home-modules--normal',
    };
    $radiusClass = match ($style['radius'] ?? 'soft') {
        'none' => 'geo-home-modules--radius-none',
        'round' => 'geo-home-modules--radius-round',
        default => 'geo-home-modules--radius-soft',
    };
@endphp

@if(($showHomepageModules ?? false) && $modules->isNotEmpty())
    <section
        class="geo-home-modules {{ $containerClass }} {{ $spacingClass }} {{ $radiusClass }}"
        style="--geo-home-accent: {{ e($style['accent_color']) }}; --geo-home-bg: {{ e($style['background_color']) }}; --geo-home-surface: {{ e($style['surface_color']) }}; --geo-home-text: {{ e($style['text_color']) }}; --geo-home-muted: {{ e($style['muted_color']) }};"
    >
        @foreach($modules as $module)
            @php
                $type = (string) ($module['type'] ?? 'rich_text');
                $layout = (string) ($module['layout'] ?? 'single');
                $title = trim((string) ($module['title'] ?? ''));
                $subtitle = trim((string) ($module['subtitle'] ?? ''));
                $body = trim((string) ($module['body'] ?? ''));
                $imageUrl = trim((string) ($module['image_url'] ?? ''));
                $linkText = trim((string) ($module['link_text'] ?? ''));
                $linkUrl = trim((string) ($module['link_url'] ?? ''));
                $leadFormSlug = trim((string) ($module['lead_form_slug'] ?? ''));
                $selectedLeadForm = $type === 'lead_form' && $leadFormSlug !== '' ? $leadFormsBySlug->get($leadFormSlug) : null;
                $moduleArticles = $articleCollections[(string) ($module['data_source'] ?? 'latest')] ?? collect();
                $moduleArticles = collect($moduleArticles)->take(max(1, min(12, (int) ($module['limit'] ?? 4))));
                $rows = $parseRows($body);
                $alignment = in_array(($module['alignment'] ?? 'left'), ['left', 'center'], true) ? (string) $module['alignment'] : 'left';
                $moduleClass = 'geo-home-module geo-home-module--'.$type.' geo-home-module--layout-'.$layout.' geo-home-module--align-'.$alignment;
                $moduleStyleParts = [];
                foreach ([
                    'accent_color' => '--geo-home-module-accent',
                    'surface_color' => '--geo-home-module-surface',
                    'text_color' => '--geo-home-module-text',
                    'muted_color' => '--geo-home-module-muted',
                ] as $field => $cssVar) {
                    $color = trim((string) ($module[$field] ?? ''));
                    if ($color !== '') {
                        $moduleStyleParts[] = $cssVar.': '.$color;
                    }
                }
                $moduleStyle = implode('; ', $moduleStyleParts);
            @endphp
            @continue($type === 'lead_form' && !$selectedLeadForm)

            <div class="{{ $moduleClass }}" @if($moduleStyle !== '') style="{{ $moduleStyle }}" @endif>
                @if(in_array($type, ['hero', 'rich_text', 'image_band', 'cta_band'], true))
                    <div class="geo-home-module__content">
                        @if($subtitle !== '')
                            <div class="geo-home-module__eyebrow">{{ $subtitle }}</div>
                        @endif
                        @if($title !== '')
                            <h2 class="geo-home-module__title">{{ $title }}</h2>
                        @endif
                        @if($body !== '')
                            <p class="geo-home-module__body">{{ $body }}</p>
                        @endif
                        @if($linkText !== '' && $linkUrl !== '')
                            <a href="{{ $linkUrl }}" class="geo-home-module__link">{{ $linkText }}</a>
                        @endif
                    </div>
                    @if($imageUrl !== '')
                        <div class="geo-home-module__media">
                            <img src="{{ $imageUrl }}" alt="{{ $title !== '' ? $title : $linkText }}" loading="lazy">
                        </div>
                    @endif
                @elseif($type === 'metric_band')
                    @if($title !== '')
                        <div class="geo-home-module__header">
                            <h2 class="geo-home-module__title">{{ $title }}</h2>
                            @if($subtitle !== '')
                                <p>{{ $subtitle }}</p>
                            @endif
                        </div>
                    @endif
                    <div class="geo-home-module__metrics">
                        @forelse($rows as $row)
                            <div class="geo-home-module__metric">
                                <strong>{{ $row['text'] !== '' ? $row['text'] : $row['title'] }}</strong>
                                @if($row['text'] !== '')
                                    <span>{{ $row['title'] }}</span>
                                @endif
                                @if($row['url'] !== '')
                                    <small>{{ $row['url'] }}</small>
                                @endif
                            </div>
                        @empty
                            <div class="geo-home-module__metric">
                                <strong>{{ $title }}</strong>
                                @if($body !== '')
                                    <span>{{ $body }}</span>
                                @endif
                            </div>
                        @endforelse
                    </div>
                @elseif($type === 'chart_band')
                    @php
                        $chartRows = collect($parseChartRows($body));
                        $maxValue = max(1, (float) ($chartRows->max('value') ?? 1));
                    @endphp
                    <div class="geo-home-module__header">
                        @if($title !== '')
                            <h2 class="geo-home-module__title">{{ $title }}</h2>
                        @endif
                        @if($subtitle !== '')
                            <p>{{ $subtitle }}</p>
                        @endif
                    </div>
                    <div class="geo-home-module__chart">
                        @forelse($chartRows as $row)
                            @php($barWidth = max(4, min(100, (int) round((((float) $row['value']) / $maxValue) * 100))))
                            <div class="geo-home-module__chart-row">
                                <div class="geo-home-module__chart-meta">
                                    <span>{{ $row['label'] }}</span>
                                    <strong>{{ $row['value_label'] !== '' ? $row['value_label'] : $row['value'] }}</strong>
                                </div>
                                <div class="geo-home-module__chart-track" aria-hidden="true">
                                    <span class="geo-home-module__chart-bar" style="width: {{ $barWidth }}%"></span>
                                </div>
                                @if($row['note'] !== '')
                                    <p class="geo-home-module__chart-note">{{ $row['note'] }}</p>
                                @endif
                            </div>
                        @empty
                            @if($body !== '')
                                <p class="geo-home-module__body">{{ $body }}</p>
                            @endif
                        @endforelse
                    </div>
                @elseif($type === 'feature_grid')
                    <div class="geo-home-module__header">
                        @if($title !== '')
                            <h2 class="geo-home-module__title">{{ $title }}</h2>
                        @endif
                        @if($subtitle !== '')
                            <p>{{ $subtitle }}</p>
                        @endif
                    </div>
                    <div class="geo-home-module__grid">
                        @foreach($rows as $row)
                            <div class="geo-home-module__feature">
                                @if($row['url'] !== '')
                                    <a href="{{ $row['url'] }}">{{ $row['title'] }}</a>
                                @else
                                    <strong>{{ $row['title'] }}</strong>
                                @endif
                                @if($row['text'] !== '')
                                    <p>{{ $row['text'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @elseif($type === 'article_collection')
                    <div class="geo-home-module__header">
                        @if($title !== '')
                            <h2 class="geo-home-module__title">{{ $title }}</h2>
                        @endif
                        @if($subtitle !== '')
                            <p>{{ $subtitle }}</p>
                        @endif
                    </div>
                    <div class="geo-home-module__articles">
                        @foreach($moduleArticles as $moduleArticle)
                            <a href="{{ route('site.article', $moduleArticle->slug) }}" class="geo-home-module__article">
                                <span>{{ $moduleArticle->title }}</span>
                                @if(!empty($moduleArticle->published_at))
                                    <time>{{ $moduleArticle->published_at->format('m-d') }}</time>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @elseif($type === 'lead_form')
                    @include('site.partials.lead-form', [
                        'leadForm' => $selectedLeadForm,
                        'embedded' => true,
                        'title' => $title !== '' ? $title : $selectedLeadForm->name,
                        'description' => $body !== '' ? $body : $selectedLeadForm->description,
                    ])
                @elseif($type === 'custom_html')
                    @if($title !== '')
                        <div class="geo-home-module__header">
                            <h2 class="geo-home-module__title">{{ $title }}</h2>
                            @if($subtitle !== '')
                                <p>{{ $subtitle }}</p>
                            @endif
                        </div>
                    @endif
                    <div class="geo-home-module__custom">
                        {!! (string) ($module['custom_html'] ?? '') !!}
                    </div>
                @endif
            </div>
        @endforeach
    </section>
@endif
