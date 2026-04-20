<?php
/**
 * GEOFlow - 更新检查辅助
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

function geoflow_update_check_interval_seconds() {
    return 12 * 60 * 60;
}

function geoflow_update_release_type($type) {
    $type = strtolower(trim((string) $type));
    if (!in_array($type, ['feature', 'fix', 'security'], true)) {
        return 'feature';
    }
    return $type;
}

function geoflow_normalize_update_payload(array $payload) {
    return [
        'latest_version' => trim((string) ($payload['latest_version'] ?? '')),
        'release_date' => trim((string) ($payload['release_date'] ?? '')),
        'release_type' => geoflow_update_release_type($payload['release_type'] ?? ''),
        'title_zh' => trim((string) ($payload['title_zh'] ?? '')),
        'title_en' => trim((string) ($payload['title_en'] ?? '')),
        'summary_zh' => trim((string) ($payload['summary_zh'] ?? '')),
        'summary_en' => trim((string) ($payload['summary_en'] ?? '')),
        'changelog_url_zh' => trim((string) ($payload['changelog_url_zh'] ?? '')),
        'changelog_url_en' => trim((string) ($payload['changelog_url_en'] ?? '')),
        'min_upgrade_from' => trim((string) ($payload['min_upgrade_from'] ?? '')),
        'upgrade_tip_zh' => trim((string) ($payload['upgrade_tip_zh'] ?? '')),
        'upgrade_tip_en' => trim((string) ($payload['upgrade_tip_en'] ?? '')),
        'published' => !array_key_exists('published', $payload) || !empty($payload['published']),
    ];
}

function geoflow_fetch_update_metadata_json($url, $timeoutSeconds = 8) {
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }

    $raw = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(3, (int) $timeoutSeconds));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        if (function_exists('apply_curl_network_defaults')) {
            apply_curl_network_defaults($ch);
        }
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hasError = curl_errno($ch) !== 0;
        curl_close($ch);
        if ($hasError || $httpCode >= 400 || !is_string($raw) || $raw === '') {
            $raw = false;
        }
    }

    if ($raw === false && ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => max(3, (int) $timeoutSeconds),
                'header' => "Accept: application/json\r\nUser-Agent: GEOFlow/" . APP_VERSION . "\r\n",
            ],
        ]);
        $fetched = @file_get_contents($url, false, $context);
        if (is_string($fetched) && $fetched !== '') {
            $raw = $fetched;
        }
    }

    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    $payload = geoflow_normalize_update_payload($decoded);
    if ($payload['latest_version'] === '' || !$payload['published']) {
        return null;
    }

    return $payload;
}

function geoflow_get_cached_update_payload() {
    $raw = get_setting('update_latest_payload', '');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    return geoflow_normalize_update_payload($decoded);
}

function geoflow_store_update_payload(array $payload, $checkedAt = null) {
    $normalized = geoflow_normalize_update_payload($payload);
    $timestamp = $checkedAt ?: time();

    set_setting('update_latest_version', $normalized['latest_version']);
    set_setting('update_latest_payload', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    set_setting('update_last_checked_at', (string) $timestamp);
}

function geoflow_update_last_checked_timestamp() {
    $value = (string) get_setting('update_last_checked_at', '');
    if ($value === '' || !ctype_digit($value)) {
        return null;
    }
    return (int) $value;
}

function geoflow_get_update_state($forceRefresh = false) {
    $payload = geoflow_get_cached_update_payload();
    $lastCheckedAt = geoflow_update_last_checked_timestamp();
    $now = time();

    $shouldRefresh = $forceRefresh
        || empty($payload)
        || $lastCheckedAt === null
        || ($now - $lastCheckedAt) >= geoflow_update_check_interval_seconds();

    if ($shouldRefresh) {
        $remotePayload = geoflow_fetch_update_metadata_json(APP_UPDATE_METADATA_URL);
        if (is_array($remotePayload) && !empty($remotePayload)) {
            geoflow_store_update_payload($remotePayload, $now);
            $payload = $remotePayload;
            $lastCheckedAt = $now;
        } elseif ($lastCheckedAt === null) {
            $lastCheckedAt = $now;
            set_setting('update_last_checked_at', (string) $lastCheckedAt);
        }
    }

    $latestVersion = trim((string) ($payload['latest_version'] ?? ''));
    $ignoredVersion = trim((string) get_setting('update_ignored_version', ''));
    $isUpdateAvailable = $latestVersion !== '' && version_compare($latestVersion, APP_VERSION, '>');
    $isIgnored = $isUpdateAvailable && $ignoredVersion !== '' && version_compare($ignoredVersion, $latestVersion, '==');

    return [
        'current_version' => APP_VERSION,
        'current_version_date' => APP_VERSION_DATE,
        'latest_version' => $latestVersion,
        'payload' => $payload,
        'last_checked_at' => $lastCheckedAt,
        'ignored_version' => $ignoredVersion,
        'is_update_available' => $isUpdateAvailable,
        'is_ignored' => $isIgnored,
    ];
}

function geoflow_get_update_copy(array $updateState, $locale = null) {
    $locale = $locale ?: app_locale();
    $payload = $updateState['payload'] ?? [];
    $useEnglish = strpos((string) $locale, 'en') === 0;

    $title = trim((string) ($useEnglish ? ($payload['title_en'] ?? '') : ($payload['title_zh'] ?? '')));
    $summary = trim((string) ($useEnglish ? ($payload['summary_en'] ?? '') : ($payload['summary_zh'] ?? '')));
    $upgradeTip = trim((string) ($useEnglish ? ($payload['upgrade_tip_en'] ?? '') : ($payload['upgrade_tip_zh'] ?? '')));
    $changelogUrl = trim((string) ($useEnglish ? ($payload['changelog_url_en'] ?? '') : ($payload['changelog_url_zh'] ?? '')));

    return [
        'title' => $title,
        'summary' => $summary,
        'upgrade_tip' => $upgradeTip,
        'changelog_url' => $changelogUrl,
        'release_type' => geoflow_update_release_type($payload['release_type'] ?? ''),
        'release_date' => trim((string) ($payload['release_date'] ?? '')),
    ];
}
