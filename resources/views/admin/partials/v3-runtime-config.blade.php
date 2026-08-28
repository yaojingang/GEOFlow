@php
    $reverbApp = config('reverb.apps.apps.0', []);
    $reverbHost = (string) (config('reverb.servers.reverb.hostname') ?: config('app.url'));
    $reverbPath = trim((string) config('reverb.servers.reverb.path', ''));
    $runtimeConfig = [
        'adminBasePath' => '/'.\App\Support\AdminWeb::basePath(),
        'reverb' => [
            'enabled' => (string) config('broadcasting.default') === 'reverb',
            'key' => (string) ($reverbApp['key'] ?? ''),
            'host' => (string) (parse_url($reverbHost, PHP_URL_HOST) ?: $reverbHost),
            'port' => (int) (config('reverb.apps.apps.0.options.port') ?: 443),
            'scheme' => (string) (config('reverb.apps.apps.0.options.scheme') ?: 'https'),
            'path' => rtrim($reverbPath !== '' && ! str_starts_with($reverbPath, '/') ? '/'.$reverbPath : $reverbPath, '/'),
            'authEndpoint' => \App\Support\AdminWeb::appPath('/broadcasting/auth'),
        ],
        'copySuccess' => __('admin.ui_v3.copy_success'),
        'copyFailed' => __('admin.ui_v3.copy_failed'),
    ];
@endphp
<script type="application/json" id="geoflow-runtime-config">@json($runtimeConfig, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)</script>
