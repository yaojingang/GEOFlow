@php
    $reverbApp = config('reverb.apps.apps.0', []);
    $reverbHost = (string) (config('reverb.servers.reverb.hostname') ?: config('app.url'));
    $reverbParsedHost = parse_url($reverbHost, PHP_URL_HOST);
    $reverbPath = trim((string) config('reverb.servers.reverb.path', ''));
    if ($reverbPath !== '' && ! str_starts_with($reverbPath, '/')) {
        $reverbPath = '/'.$reverbPath;
    }
    $reverbRuntimeConfig = [
        'enabled' => (string) config('broadcasting.default') === 'reverb',
        'key' => (string) ($reverbApp['key'] ?? ''),
        'host' => $reverbParsedHost ? (string) $reverbParsedHost : $reverbHost,
        'port' => (int) (config('reverb.apps.apps.0.options.port') ?: 443),
        'scheme' => (string) (config('reverb.apps.apps.0.options.scheme') ?: 'https'),
        'path' => rtrim($reverbPath, '/'),
        'authEndpoint' => \App\Support\AdminWeb::appPath('/broadcasting/auth'),
    ];
@endphp
<script>
    window.ADMIN_BASE_PATH = @json('/'.\App\Support\AdminWeb::basePath());
    window.GEOFLOW_REVERB_CONFIG = @json($reverbRuntimeConfig, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    window.adminUrl = function (path) {
        const base = window.ADMIN_BASE_PATH || '';
        if (!path) return base + '/';
        return base + '/' + String(path).replace(/^\/+/, '');
    };
    if (! window.GeoFlowAdminUi?.refreshIcons && typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>
