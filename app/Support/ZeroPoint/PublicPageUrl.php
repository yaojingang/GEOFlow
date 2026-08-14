<?php

namespace App\Support\ZeroPoint;

final class PublicPageUrl
{
    public static function for(string $slug, string $area): string
    {
        if ($slug === 'home') {
            return route('site.home');
        }

        if ($area === 'health') {
            return route('site.zeropoint.health.show', ['slug' => $slug]);
        }

        return match ($slug) {
            'credentials' => route('site.zeropoint.credentials'),
            'contact' => route('site.zeropoint.contact'),
            'team' => route('site.zeropoint.team'),
            'first-visit' => route('site.zeropoint.first-visit'),
            'pricing-and-receipts' => route('site.zeropoint.pricing'),
            'rights-and-corrections' => route('site.zeropoint.rights'),
            default => route('site.home'),
        };
    }
}
