@props([
    'active' => '',
])

@php
    $admin = auth('admin')->user();
    $canUseUrlImport = $admin instanceof \App\Models\Admin && $admin->canManageProtectedWorkflows();
    $items = [
        [
            'key' => 'knowledge-bases',
            'label' => __('admin.materials.knowledge_bases'),
            'route' => 'admin.knowledge-bases.index',
            'active' => $active === 'knowledge-bases',
        ],
        [
            'key' => 'keywords',
            'label' => __('admin.materials.keyword_libraries'),
            'route' => 'admin.keyword-libraries.index',
            'active' => $active === 'keywords',
        ],
        [
            'key' => 'titles',
            'label' => __('admin.materials.title_libraries'),
            'route' => 'admin.title-libraries.index',
            'active' => $active === 'titles',
        ],
        [
            'key' => 'images',
            'label' => __('admin.materials.image_libraries'),
            'route' => 'admin.image-libraries.index',
            'active' => $active === 'images',
        ],
        [
            'key' => 'authors',
            'label' => __('admin.materials.author_manage'),
            'route' => 'admin.authors.index',
            'active' => $active === 'authors',
        ],
    ];

    if ($canUseUrlImport) {
        $items[] = [
            'key' => 'url-import',
            'label' => __('admin.materials.url_import'),
            'route' => 'admin.url-import',
            'active' => $active === 'url-import',
        ];
    }
@endphp

<x-admin.v3.section-subnav
    :items="$items"
    :label="__('admin.materials.page_title')"
    name="materials"
    embedded
/>
