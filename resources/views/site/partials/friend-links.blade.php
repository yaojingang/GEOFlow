@if(!empty($friendLinks))
    <nav class="site-friend-links" aria-label="{{ __('friend_links.title') }}">
        <h2 class="site-friend-links__title">{{ __('friend_links.title') }}</h2>
        <ul class="site-friend-links__list">
            @foreach($friendLinks as $friendLink)
                @php
                    $friendRel = array_filter([
                        $friendLink['relationship'] !== 'regular' ? $friendLink['relationship'] : null,
                        $friendLink['target'] === '_blank' ? 'noopener' : null,
                    ]);
                @endphp
                <li class="site-friend-links__item">
                    <a class="site-friend-links__link" href="{{ $friendLink['url'] }}" target="{{ $friendLink['target'] }}" @if($friendRel) rel="{{ implode(' ', $friendRel) }}" @endif>{{ $friendLink['name'] }}@if($friendLink['target'] === '_blank')<span class="site-friend-links__sr-only"> {{ __('friend_links.new_window') }}</span>@endif</a>
                </li>
            @endforeach
        </ul>
    </nav>
@endif
