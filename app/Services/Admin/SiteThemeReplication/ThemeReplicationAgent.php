<?php

namespace App\Services\Admin\SiteThemeReplication;

use App\Models\SiteThemeReplication;

class ThemeReplicationAgent
{
    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    public function generateBlueprint(SiteThemeReplication $replication, array $analysis, ?string $feedback = null): array
    {
        $colors = (array) (($analysis['tokens'] ?? [])['colors'] ?? []);
        $fontFamilies = (array) (($analysis['tokens'] ?? [])['font_families'] ?? []);
        $primary = $this->safeColor((string) ($colors[0] ?? '#2563eb'), '#2563eb');
        $accent = $this->safeColor((string) ($colors[1] ?? '#f97316'), '#f97316');
        $font = $this->safeFont((string) ($fontFamilies[0] ?? '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'));
        $feedbackOptions = $this->feedbackOptions((string) $feedback);

        return [
            'theme' => [
                'name' => (string) $replication->name,
                'id' => (string) $replication->theme_id,
                'description' => 'A GEOFlow theme draft generated from public reference page structure and style signals.',
                'mode' => 'replicated',
            ],
            'tokens' => [
                'colors' => [
                    'primary' => $primary,
                    'accent' => $accent,
                    'background' => '#f8fafc',
                    'surface' => '#ffffff',
                    'text' => '#111827',
                    'muted' => '#6b7280',
                    'border' => '#e5e7eb',
                ],
                'typography' => [
                    'font_family' => $font,
                    'heading_weight' => (string) $feedbackOptions['heading_weight'],
                    'body_line_height' => '1.8',
                ],
                'radius' => [
                    'card' => '8px',
                    'button' => '8px',
                ],
                'spacing' => [
                    'container' => '1120px',
                    'section' => (string) $feedbackOptions['section_spacing'],
                ],
            ],
            'components' => [
                ['name' => 'site_header', 'role' => 'layout.header'],
                ['name' => 'article_card', 'role' => 'home.latest_articles'],
                ['name' => 'article_detail', 'role' => 'article.content'],
                ['name' => 'site_footer', 'role' => 'layout.footer'],
            ],
            'assets' => [
                'theme_css' => $this->buildCss($primary, $accent, $font, $feedbackOptions),
                'theme_js' => $this->buildJs(),
            ],
            'notes' => array_values(array_filter([
                'Generated from abstracted layout, typography, color, and component signals.',
                'No third-party images, scripts, trademarks, or protected copy are embedded.',
                $feedback !== null && trim($feedback) !== '' ? 'Iteration feedback: '.mb_substr(trim($feedback), 0, 180) : null,
            ])),
        ];
    }

    private function safeColor(string $value, string $fallback): string
    {
        $value = trim($value);

        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) ? $value : $fallback;
    }

    private function safeFont(string $value): string
    {
        $value = trim(preg_replace('/[^a-zA-Z0-9,\-_"\'\s.]/', '', $value) ?? '');

        return $value !== '' ? $value : '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
    }

    /**
     * @return array{hero_font:string,card_padding:string,detail_padding:string,section_spacing:string,heading_weight:string,background:string,surface:string,text:string,muted:string,border:string}
     */
    private function feedbackOptions(string $feedback): array
    {
        $text = mb_strtolower($feedback);
        $compact = str_contains($text, '紧凑') || str_contains($text, 'compact') || str_contains($text, 'dense');
        $spacious = str_contains($text, '宽松') || str_contains($text, '留白') || str_contains($text, 'spacious');
        $dark = str_contains($text, '深色') || str_contains($text, '暗色') || str_contains($text, 'dark');
        $bold = str_contains($text, '醒目') || str_contains($text, '更强') || str_contains($text, 'bold');

        return [
            'hero_font' => $compact ? '44px' : ($spacious ? '56px' : '52px'),
            'card_padding' => $compact ? '18px' : ($spacious ? '28px' : '22px'),
            'detail_padding' => $compact ? '34px' : ($spacious ? '52px' : '44px'),
            'section_spacing' => $compact ? '24px' : ($spacious ? '48px' : '32px'),
            'heading_weight' => $bold ? '900' : '800',
            'background' => $dark ? '#111827' : '#f8fafc',
            'surface' => $dark ? '#1f2937' : '#ffffff',
            'text' => $dark ? '#f9fafb' : '#111827',
            'muted' => $dark ? '#d1d5db' : '#6b7280',
            'border' => $dark ? '#374151' : '#e5e7eb',
        ];
    }

    /**
     * @param  array{hero_font:string,card_padding:string,detail_padding:string,section_spacing:string,heading_weight:string,background:string,surface:string,text:string,muted:string,border:string}  $options
     */
    private function buildCss(string $primary, string $accent, string $font, array $options): string
    {
        return <<<CSS
:root {
    --rep-primary: {$primary};
    --rep-accent: {$accent};
    --rep-bg: {$options['background']};
    --rep-surface: {$options['surface']};
    --rep-text: {$options['text']};
    --rep-muted: {$options['muted']};
    --rep-border: {$options['border']};
    --rep-radius: 8px;
    font-family: {$font};
}

.rep-body {
    margin: 0;
    background: var(--rep-bg);
    color: var(--rep-text);
}

.rep-shell {
    width: min(1120px, calc(100% - 40px));
    margin: 0 auto;
}

.rep-header,
.rep-footer {
    background: var(--rep-surface);
    border-bottom: 1px solid var(--rep-border);
}

.rep-header__bar {
    min-height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
}

.rep-brand {
    color: var(--rep-text);
    text-decoration: none;
    font-size: 20px;
    font-weight: 800;
}

.rep-nav {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
}

.rep-nav a {
    color: var(--rep-muted);
    text-decoration: none;
    font-size: 14px;
}

.rep-nav a:hover {
    color: var(--rep-primary);
}

.rep-hero {
    padding: {$options['section_spacing']} 0 28px;
}

.rep-hero h1 {
    margin: 0 0 12px;
    font-size: {$options['hero_font']};
    line-height: 1.08;
    letter-spacing: 0;
    font-weight: {$options['heading_weight']};
}

.rep-hero p {
    max-width: 720px;
    margin: 0;
    color: var(--rep-muted);
    line-height: 1.8;
}

.rep-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 18px;
    padding-bottom: 44px;
}

.rep-card,
.rep-detail {
    background: var(--rep-surface);
    border: 1px solid var(--rep-border);
    border-radius: var(--rep-radius);
    box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
}

.rep-card {
    padding: {$options['card_padding']};
}

.rep-card h2,
.rep-card h3 {
    margin: 0 0 10px;
    line-height: 1.25;
}

.rep-card a {
    color: var(--rep-text);
    text-decoration: none;
}

.rep-card a:hover,
.rep-link {
    color: var(--rep-primary);
}

.rep-meta,
.rep-summary {
    color: var(--rep-muted);
    line-height: 1.75;
}

.rep-chip {
    display: inline-flex;
    align-items: center;
    border: 1px solid color-mix(in srgb, var(--rep-primary) 22%, white);
    background: color-mix(in srgb, var(--rep-primary) 8%, white);
    color: var(--rep-primary);
    border-radius: 999px;
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 700;
}

.rep-detail {
    margin: 28px auto 48px;
    padding: {$options['detail_padding']};
}

.rep-detail h1 {
    margin: 0 0 16px;
    font-size: 48px;
    line-height: 1.12;
}

.rep-content {
    font-size: 17px;
    line-height: 1.9;
}

.rep-content img {
    max-width: 100%;
    height: auto;
    border-radius: var(--rep-radius);
}

.rep-tags {
    margin-top: 28px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.rep-pagination {
    padding-bottom: 40px;
}

.rep-footer {
    border-top: 1px solid var(--rep-border);
    border-bottom: 0;
    padding: 28px 0;
    color: var(--rep-muted);
    font-size: 13px;
}

@media (max-width: 720px) {
    .rep-header__bar {
        align-items: flex-start;
        flex-direction: column;
        padding: 16px 0;
    }

    .rep-hero h1 {
        font-size: 34px;
    }

    .rep-detail {
        padding: 24px;
    }

    .rep-detail h1 {
        font-size: 34px;
    }
}
CSS;
    }

    private function buildJs(): string
    {
        return <<<JS
document.addEventListener('DOMContentLoaded', () => {
    document.documentElement.classList.add('rep-ready');
});
JS;
    }
}
