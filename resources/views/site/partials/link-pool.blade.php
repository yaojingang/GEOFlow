@if(!empty($recommendationBlocks))
    @once
        @push('head')
            <link rel="stylesheet" href="/assets/css/link-pool.css">
        @endpush
        @push('scripts')
            <script src="/js/link-pool.js" defer></script>
        @endpush
    @endonce
    @foreach($recommendationBlocks as $block)
        @if(!empty($block['items']))
            @php
                request()->attributes->set('link_pool.placement_ids', array_values(array_unique(array_merge(
                    (array) request()->attributes->get('link_pool.placement_ids', []),
                    array_filter(array_column($block['items'], 'placement_id')),
                ))));
            @endphp
            <section class="link-pool" aria-labelledby="link-pool-title-{{ $loop->iteration }}" data-link-pool data-csrf="{{ csrf_token() }}">
                <div class="link-pool__header">
                    <span class="link-pool__eyebrow">GEOFlow · Discover</span>
                    <h2 id="link-pool-title-{{ $loop->iteration }}">{{ $block['heading'] }}</h2>
                </div>
                <ul class="link-pool__list">
                    @foreach($block['items'] as $item)
                        <li class="link-pool__item" @if(!empty($item['placement_id'])) data-placement-id="{{ $item['placement_id'] }}" @endif>
                            <span class="link-pool__number" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <a class="link-pool__link" href="{{ $item['url'] }}" @if(($item['target_site_key'] ?? '') !== app(\App\Services\Site\LinkPoolSiteService::class)->currentKey()) rel="noopener" @endif>
                                <span>{{ $item['title'] }}</span><span class="link-pool__arrow" aria-hidden="true">↗</span>
                            </a>
                            @if(($item['target_site_key'] ?? '') !== app(\App\Services\Site\LinkPoolSiteService::class)->currentKey())
                                <span class="link-pool__site">{{ $item['target_site_name'] ?? 'GEOFlow 站点' }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endforeach
@endif
