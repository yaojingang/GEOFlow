@props(['active' => 'task-list'])

@php
    $items = [
        ['key' => 'task-list', 'label' => __('admin.tasks.page_title'), 'route' => 'admin.tasks.index', 'active' => $active === 'task-list'],
        ['key' => 'workers', 'label' => __('admin.tasks.worker.title'), 'route' => 'admin.tasks.workers', 'active' => $active === 'workers'],
        ['key' => 'jobs', 'label' => __('admin.tasks.jobs.recent'), 'route' => 'admin.tasks.jobs', 'active' => $active === 'jobs'],
    ];
@endphp

<x-admin.v3.section-subnav
    :items="$items"
    :label="__('admin.tasks.page_title')"
    name="tasks"
    embedded
/>
