<?php

return [
    'enabled' => (bool) env('ZEROPOINT_SITE_ENABLED', false),
    'booking_form_slug' => env('ZEROPOINT_BOOKING_FORM_SLUG', 'zeropoint-booking'),
    'site_name' => env('ZEROPOINT_SITE_NAME', '正负零'),
    'tagline' => env('ZEROPOINT_SITE_TAGLINE', '归零 · 溯源 · 共生'),
    'approval_gates' => ['facts', 'medical', 'compliance', 'brand'],
];
