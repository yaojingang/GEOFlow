<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\LeadForm;
use App\Models\PublicationSnapshot;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\ZeroPoint\PublicPageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ZeroPointPublicSiteController extends Controller
{
    public function home(Request $request): View|Response
    {
        $this->ensureEnabled();
        $snapshot = $this->activeSnapshot('home');
        if (! $snapshot) {
            return $this->unavailable();
        }

        $healthPages = $this->activeSnapshots()
            ->filter(fn (PublicationSnapshot $item): bool => $item->page?->area === 'health')
            ->take(4);

        return view('theme.zeropoint.home', $this->viewData($snapshot, [
            'healthPages' => $healthPages,
            'bookingAvailable' => $this->bookingForm() !== null,
        ]));
    }

    public function show(string $slug): View
    {
        $this->ensureEnabled();
        $snapshot = $this->activeSnapshot($slug);
        if (! $snapshot) {
            throw new NotFoundHttpException('页面尚未发布。');
        }

        return view('theme.zeropoint.page', $this->viewData($snapshot));
    }

    public function healthIndex(): View
    {
        $this->ensureEnabled();
        $pages = $this->activeSnapshots()
            ->filter(fn (PublicationSnapshot $item): bool => $item->page?->area === 'health');

        return view('theme.zeropoint.health-index', [
            ...$this->baseData(),
            'pages' => $pages,
            'pageTitle' => '健康知识 · 正负零',
            'pageDescription' => '经人工审核、标注边界与更新时间的健康知识。线上内容不能替代面诊。',
            'canonicalUrl' => route('site.zeropoint.health.index'),
            'isPlaceholder' => $pages->contains(fn (PublicationSnapshot $item): bool => (bool) data_get($item->payload, 'is_placeholder')),
            'robotsDirective' => $pages->contains(fn (PublicationSnapshot $item): bool => (bool) data_get($item->payload, 'is_placeholder')) ? 'noindex,nofollow' : 'index,follow',
            'schemaData' => $this->collectionSchema('健康知识', route('site.zeropoint.health.index'), $pages),
        ]);
    }

    public function healthShow(string $slug): View
    {
        $this->ensureEnabled();
        $snapshot = $this->activeSnapshot($slug);
        if (! $snapshot || $snapshot->page?->area !== 'health') {
            throw new NotFoundHttpException('健康知识页面尚未发布。');
        }

        return view('theme.zeropoint.page', $this->viewData($snapshot));
    }

    public function search(Request $request): View
    {
        $this->ensureEnabled();
        $query = trim(mb_substr((string) $request->query('q', ''), 0, 80));
        $results = collect();

        if ($query !== '') {
            $needle = mb_strtolower($query);
            $results = $this->activeSnapshots()->filter(function (PublicationSnapshot $snapshot) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', [
                    (string) data_get($snapshot->payload, 'title', ''),
                    (string) data_get($snapshot->payload, 'summary', ''),
                    (string) data_get($snapshot->payload, 'body', ''),
                ]));

                return str_contains($haystack, $needle);
            });
        }

        return view('theme.zeropoint.search', [
            ...$this->baseData(),
            'query' => $query,
            'results' => $results,
            'pageTitle' => ($query !== '' ? '搜索：'.$query : '站内搜索').' · 正负零',
            'pageDescription' => '搜索正负零已审核发布的机构信息与健康知识。',
            'canonicalUrl' => route('site.zeropoint.search'),
            'robotsDirective' => 'noindex,follow',
            'schemaData' => null,
            'isPlaceholder' => false,
        ]);
    }

    public function sitemap(): Response
    {
        $this->ensureEnabled();

        return response()
            ->view('theme.zeropoint.sitemap', [
                'snapshots' => $this->activeSnapshots()->reject(
                    fn (PublicationSnapshot $snapshot): bool => (bool) data_get($snapshot->payload, 'is_placeholder')
                ),
            ], 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(): Response
    {
        $this->ensureEnabled();
        $body = implode("\n", [
            'User-agent: OAI-SearchBot',
            'Allow: /',
            'Disallow: /geo_admin/',
            '',
            'User-agent: GPTBot',
            'Disallow: /',
            '',
            'User-agent: *',
            'Allow: /',
            'Disallow: /geo_admin/',
            'Disallow: /forms/',
            '',
            'Sitemap: '.route('site.zeropoint.sitemap'),
            '',
        ]);

        return response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function llms(): Response
    {
        $this->ensureEnabled();
        $lines = [
            '# 正负零',
            '',
            '> 面向消费者的机构公开信息与健康教育网站。公开页面来自经过审核的活动发布快照。',
            '',
            '## Content boundaries',
            '',
            '- 网站内容不构成在线诊断，不能替代有资质医师的面诊判断。',
            '- 占位内容、搜索结果和预约表单不进入本清单。',
            '- 页面标注的版本和发布日期用于识别当前公开快照。',
            '',
            '## Published pages',
            '',
        ];

        $this->activeSnapshots()
            ->reject(fn (PublicationSnapshot $snapshot): bool => (bool) data_get($snapshot->payload, 'is_placeholder'))
            ->each(function (PublicationSnapshot $snapshot) use (&$lines): void {
                $slug = (string) data_get($snapshot->payload, 'slug');
                $area = (string) data_get($snapshot->payload, 'area');
                $lines[] = '- ['.(string) data_get($snapshot->payload, 'title').']('.$this->urlFor($slug, $area).'): '.(string) data_get($snapshot->payload, 'summary');
            });

        return response(implode("\n", $lines)."\n", 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function viewData(PublicationSnapshot $snapshot, array $extra = []): array
    {
        $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
        $slug = (string) data_get($payload, 'slug');
        $area = (string) data_get($payload, 'area');
        $canonical = $this->urlFor($slug, $area);

        $title = (string) data_get($payload, 'seo_title') ?: (string) data_get($payload, 'title');

        return [
            ...$this->baseData(),
            'snapshot' => $snapshot,
            'page' => $payload,
            'bodyHtml' => ArticleHtmlPresenter::markdownToHtml((string) data_get($payload, 'body', '')),
            'pageTitle' => str_contains($title, '正负零') ? $title : $title.' · 正负零',
            'pageDescription' => (string) data_get($payload, 'meta_description', data_get($payload, 'summary', '')),
            'canonicalUrl' => $canonical,
            'isPlaceholder' => (bool) data_get($payload, 'is_placeholder', false),
            'robotsDirective' => (bool) data_get($payload, 'is_placeholder', false) ? 'noindex,nofollow' : 'index,follow',
            'schemaData' => $this->schemaFor($snapshot, $canonical),
            ...$extra,
        ];
    }

    /** @return array<string, mixed> */
    private function baseData(): array
    {
        return [
            'siteName' => (string) config('zeropoint.site_name', '正负零'),
            'tagline' => (string) config('zeropoint.tagline', '归零 · 溯源 · 共生'),
            'bookingAvailable' => $this->bookingForm() !== null,
        ];
    }

    private function activeSnapshot(string $slug): ?PublicationSnapshot
    {
        $snapshot = PublicationSnapshot::query()
            ->with('page')
            ->where('is_active', true)
            ->whereHas('page', fn ($query) => $query->where('slug', $slug))
            ->latest('published_at')
            ->first();

        return $snapshot && $this->snapshotIsCurrentlyEligible($snapshot) ? $snapshot : null;
    }

    /** @return Collection<int, PublicationSnapshot> */
    private function activeSnapshots(): Collection
    {
        return PublicationSnapshot::query()
            ->with('page')
            ->where('is_active', true)
            ->whereHas('page')
            ->orderBy('public_page_id')
            ->get()
            ->filter(fn (PublicationSnapshot $snapshot): bool => $this->snapshotIsCurrentlyEligible($snapshot))
            ->unique('public_page_id')
            ->values();
    }

    private function bookingForm(): ?LeadForm
    {
        return LeadForm::query()
            ->where('slug', (string) config('zeropoint.booking_form_slug'))
            ->where('status', LeadForm::STATUS_ACTIVE)
            ->first();
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('zeropoint.enabled', false), 404);
    }

    private function snapshotIsCurrentlyEligible(PublicationSnapshot $snapshot): bool
    {
        $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
        if ((string) data_get($payload, 'area') !== 'institution' || (bool) data_get($payload, 'is_placeholder')) {
            return true;
        }

        $facts = data_get($payload, '_fact_fingerprints', []);
        if (! is_array($facts) || $facts === []) {
            return false;
        }

        return collect($facts)->every(function (mixed $fact): bool {
            if (! is_array($fact)) {
                return false;
            }

            $expiresAt = trim((string) ($fact['expires_at'] ?? ''));

            return (int) ($fact['evidence_level'] ?? 0) >= 3
                && (string) ($fact['visibility'] ?? '') === 'public'
                && in_array((string) ($fact['status'] ?? ''), ['approved', 'published'], true)
                && ($expiresAt === '' || $expiresAt >= now()->toDateString());
        });
    }

    private function unavailable(): Response
    {
        return response()
            ->view('theme.zeropoint.unavailable', [
                ...$this->baseData(),
                'pageTitle' => '内容审核中 · 正负零',
                'pageDescription' => '正负零公开信息正在审核。',
                'robotsDirective' => 'noindex,nofollow',
                'canonicalUrl' => route('site.home'),
                'schemaData' => null,
                'isPlaceholder' => true,
            ], 503);
    }

    private function urlFor(string $slug, string $area): string
    {
        if ($slug === 'home') {
            return route('site.home');
        }

        if ($area === 'health') {
            return route('site.zeropoint.health.show', ['slug' => $slug]);
        }

        return PublicPageUrl::for($slug, $area);
    }

    /** @return array<string, mixed> */
    private function schemaFor(PublicationSnapshot $snapshot, string $canonical): array
    {
        $payload = $snapshot->payload;
        if ((string) data_get($payload, 'area') === 'health') {
            return [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => (string) data_get($payload, 'title'),
                'description' => (string) data_get($payload, 'summary'),
                'datePublished' => $snapshot->published_at?->toAtomString(),
                'dateModified' => $snapshot->updated_at?->toAtomString(),
                'mainEntityOfPage' => $canonical,
                'publisher' => ['@type' => 'Organization', 'name' => '正负零', 'url' => route('site.home')],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => (string) data_get($payload, 'title'),
            'description' => (string) data_get($payload, 'summary'),
            'url' => $canonical,
            'isPartOf' => ['@type' => 'WebSite', 'name' => '正负零', 'url' => route('site.home')],
        ];
    }

    /**
     * @param  Collection<int, PublicationSnapshot>  $pages
     * @return array<string, mixed>
     */
    private function collectionSchema(string $name, string $url, Collection $pages): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $name,
            'url' => $url,
            'hasPart' => $pages->map(fn (PublicationSnapshot $item): array => [
                '@type' => 'Article',
                'name' => (string) data_get($item->payload, 'title'),
                'url' => $this->urlFor((string) data_get($item->payload, 'slug'), 'health'),
            ])->values()->all(),
        ];
    }
}
