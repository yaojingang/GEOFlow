@props(['items' => []])
<nav class="gf-context-nav" aria-label="{{ __('admin.ui_v3.settings_navigation') }}">
    <div class="gf-context-nav__inner">
        @foreach ($items as $item)
            <a href="{{ \App\Support\AdminWeb::routePath($item['route']) }}" @if($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
        @endforeach
    </div>
</nav>
