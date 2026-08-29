@props(['items' => []])

<x-admin.v3.section-subnav
    :items="$items"
    :label="__('admin.ai_configurator.heading')"
    name="ai-configurator"
/>
