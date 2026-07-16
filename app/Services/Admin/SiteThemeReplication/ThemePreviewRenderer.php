<?php

namespace App\Services\Admin\SiteThemeReplication;

use Illuminate\Http\Response;
use InvalidArgumentException;

class ThemePreviewRenderer
{
    private const CONTENT_SECURITY_POLICY = "default-src 'none'; style-src 'unsafe-inline'; script-src 'none'; connect-src 'none'; img-src 'none'; font-src 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox";

    public function render(string $page): Response
    {
        $page = $this->normalizePage($page);

        return response()->view('admin.site-theme-replications.preview-safe', [
            'page' => $page,
            'pageLabel' => __('admin.theme_replication.preview.'.$page),
            'title' => __('admin.theme_replication.safe_preview.'.$page.'_title'),
            'description' => __('admin.theme_replication.safe_preview.'.$page.'_description'),
            'cards' => $this->cards($page),
        ], 200, [
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function normalizePage(string $page): string
    {
        return match ($page) {
            'home', 'category', 'article' => $page,
            default => throw new InvalidArgumentException('Unsupported preview page.'),
        };
    }

    /**
     * @return list<array{title:string,description:string}>
     */
    private function cards(string $page): array
    {
        return [
            [
                'title' => __('admin.theme_replication.safe_preview.'.$page.'_card_one_title'),
                'description' => __('admin.theme_replication.safe_preview.'.$page.'_card_one_description'),
            ],
            [
                'title' => __('admin.theme_replication.safe_preview.'.$page.'_card_two_title'),
                'description' => __('admin.theme_replication.safe_preview.'.$page.'_card_two_description'),
            ],
            [
                'title' => __('admin.theme_replication.safe_preview.'.$page.'_card_three_title'),
                'description' => __('admin.theme_replication.safe_preview.'.$page.'_card_three_description'),
            ],
        ];
    }
}
