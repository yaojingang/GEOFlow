@props(['src', 'poster' => null, 'label' => '播放视频', 'width' => 1280, 'height' => 720])

<div {{ $attributes->class(['site-video']) }}>
    <button type="button" data-site-video-play aria-label="{{ $label }}" style="position:relative;width:100%;padding:0;border:0;cursor:pointer;background:#111;color:#fff">
        @if($poster)
            <img src="{{ $poster }}" alt="" loading="lazy" width="{{ $width }}" height="{{ $height }}" style="display:block;width:100%;height:auto">
        @else
            <span style="display:block;aspect-ratio:{{ $width }} / {{ $height }}"></span>
        @endif
        <span aria-hidden="true" style="position:absolute;inset:0;display:grid;place-items:center;font-size:3rem">▶</span>
    </button>
    <video data-site-video-src="{{ $src }}" controls playsinline preload="none" width="{{ $width }}" height="{{ $height }}" hidden style="width:100%;height:auto"></video>
</div>
<script src="{{ asset('js/site-video.js') }}" defer></script>
