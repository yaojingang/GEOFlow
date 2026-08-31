@props([
    'knowledgeBase',
    'active' => 'current',
])

@php
    $knowledgeBaseId = (int) $knowledgeBase->getKey();
    $items = [
        [
            'key' => 'knowledge-current',
            'label' => __('admin.knowledge_navigation.current'),
            'route' => 'admin.knowledge-bases.detail',
            'parameters' => ['knowledgeBaseId' => $knowledgeBaseId],
            'active' => $active === 'current',
        ],
        [
            'key' => 'knowledge-chunks',
            'label' => __('admin.knowledge_navigation.chunks'),
            'route' => 'admin.knowledge-bases.chunks.index',
            'parameters' => ['knowledgeBaseId' => $knowledgeBaseId],
            'active' => $active === 'chunks',
        ],
        [
            'key' => 'knowledge-facts',
            'label' => __('admin.knowledge_navigation.facts'),
            'route' => 'admin.knowledge-bases.facts.index',
            'parameters' => ['knowledgeBaseId' => $knowledgeBaseId],
            'active' => $active === 'facts',
        ],
    ];
@endphp

<x-admin.v3.section-subnav
    :items="$items"
    :label="__('admin.knowledge_navigation.label', ['name' => $knowledgeBase->name])"
    name="knowledge-base"
    embedded
/>
