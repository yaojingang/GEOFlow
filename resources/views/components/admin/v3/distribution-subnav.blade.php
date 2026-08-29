@props([
    'active' => '',
])

@php
    $items = [
        [
            'key' => 'hosted-sites',
            'label' => __('admin_pages.hosted_sites'),
            'route' => 'admin.distribution.hosted-sites.index',
            'icon' => 'network',
            'active' => $active === 'hosted-sites',
        ],
        [
            'key' => 'distribution-jobs',
            'label' => __('admin.distribution.button.jobs'),
            'route' => 'admin.distribution.jobs',
            'icon' => 'list-checks',
            'active' => $active === 'distribution-jobs',
        ],
        [
            'key' => 'sync-all',
            'label' => __('admin.distribution.button.sync_settings_all'),
            'route' => 'admin.distribution.sync-settings-all.preview',
            'icon' => 'scan-search',
            'active' => $active === 'sync-all',
        ],
    ];
@endphp

<x-admin.v3.section-subnav
    :items="$items"
    :label="__('admin.distribution.page_heading')"
    name="distribution"
    embedded
/>
