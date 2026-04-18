<?php

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

function app_supported_locales(): array {
    return [
        'zh-CN' => '简体中文',
        'en' => 'English',
    ];
}

function app_default_locale(): string {
    return 'zh-CN';
}

function app_locale_session_key(): string {
    return 'app_locale';
}

function app_normalize_locale(?string $locale): string {
    $locale = trim((string) $locale);
    if ($locale === '') {
        return app_default_locale();
    }

    if ($locale === 'zh' || $locale === 'zh_CN') {
        return 'zh-CN';
    }

    if ($locale === 'en_US' || $locale === 'en-GB') {
        return 'en';
    }

    $supported = app_supported_locales();
    if (isset($supported[$locale])) {
        return $locale;
    }

    $short = strtolower(substr($locale, 0, 2));
    if ($short === 'en') {
        return 'en';
    }
    if ($short === 'zh') {
        return 'zh-CN';
    }

    return app_default_locale();
}

function app_boot_locale(): void {
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    $reopenedSession = false;
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
        $reopenedSession = true;
    }

    $sessionKey = app_locale_session_key();

    if (isset($_GET['lang'])) {
        $_SESSION[$sessionKey] = app_normalize_locale((string) $_GET['lang']);
        if ($reopenedSession && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        return;
    }

    if (!empty($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = app_normalize_locale((string) $_SESSION[$sessionKey]);
        if ($reopenedSession && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        return;
    }

    $acceptLanguage = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if (str_starts_with($acceptLanguage, 'en') || str_contains($acceptLanguage, ',en')) {
        $_SESSION[$sessionKey] = 'en';
        if ($reopenedSession && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        return;
    }

    $_SESSION[$sessionKey] = app_default_locale();
    if ($reopenedSession && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function app_locale(): string {
    app_boot_locale();
    return app_normalize_locale((string) ($_SESSION[app_locale_session_key()] ?? app_default_locale()));
}

function app_html_lang(): string {
    return app_locale() === 'zh-CN' ? 'zh-CN' : 'en';
}

function app_locale_label(?string $locale = null): string {
    $locale = app_normalize_locale($locale ?? app_locale());
    $supported = app_supported_locales();
    return $supported[$locale] ?? $supported[app_default_locale()];
}

function app_load_messages(string $locale): array {
    static $cache = [];

    $locale = app_normalize_locale($locale);
    if (isset($cache[$locale])) {
        return $cache[$locale];
    }

    $file = __DIR__ . '/../lang/' . $locale . '.php';
    $messages = [];
    if (is_file($file)) {
        $loaded = require $file;
        if (is_array($loaded)) {
            $messages = $loaded;
        }
    }

    $cache[$locale] = $messages;
    return $messages;
}

function __(string $key, array $vars = [], ?string $locale = null): string {
    $locale = app_normalize_locale($locale ?? app_locale());
    $messages = app_load_messages($locale);
    $fallbackMessages = $locale === app_default_locale() ? $messages : app_load_messages(app_default_locale());
    $message = $messages[$key] ?? $fallbackMessages[$key] ?? $key;

    if ($vars) {
        $replace = [];
        foreach ($vars as $varKey => $value) {
            $replace['{' . $varKey . '}'] = (string) $value;
        }
        $message = strtr($message, $replace);
    }

    return $message;
}

function app_locale_switch_url(string $locale): string {
    $locale = app_normalize_locale($locale);
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = (string) (parse_url($requestUri, PHP_URL_PATH) ?? '');
    $query = [];

    parse_str((string) (parse_url($requestUri, PHP_URL_QUERY) ?? ''), $query);
    $query['lang'] = $locale;

    $queryString = http_build_query($query);
    return $path . ($queryString !== '' ? '?' . $queryString : '');
}
