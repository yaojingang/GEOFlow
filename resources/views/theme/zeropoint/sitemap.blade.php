{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach($snapshots as $snapshot)
    @php
        $slug = (string) data_get($snapshot->payload, 'slug');
        $area = (string) data_get($snapshot->payload, 'area');
        $loc = \App\Support\ZeroPoint\PublicPageUrl::for($slug, $area);
    @endphp
    <url><loc>{{ $loc }}</loc><lastmod>{{ $snapshot->published_at?->toAtomString() }}</lastmod></url>
@endforeach
</urlset>
