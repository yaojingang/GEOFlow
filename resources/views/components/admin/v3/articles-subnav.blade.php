@props(['active' => 'article-list'])

@php
    $items = [
        ['key' => 'article-list', 'label' => __('admin.articles.list_title'), 'route' => 'admin.articles.index', 'active' => $active === 'article-list'],
        ['key' => 'categories', 'label' => __('admin.button.category_manage'), 'route' => 'admin.categories.index', 'active' => $active === 'categories'],
        ['key' => 'review', 'label' => __('admin.button.review_center'), 'route' => 'admin.articles.index', 'parameters' => ['review_status' => 'pending'], 'fragment' => 'article-list', 'active' => $active === 'review'],
        ['key' => 'trash', 'label' => __('admin.button.trash'), 'route' => 'admin.articles.index', 'parameters' => ['trashed' => 1], 'active' => $active === 'trash'],
    ];
@endphp

<x-admin.v3.section-subnav
    :items="$items"
    :label="__('admin.articles.page_title')"
    name="articles"
    embedded
/>
