@props(['items' => []])

<x-admin.v3.section-subnav
    :items="$items"
    :label="__('admin.ui_v3.settings_navigation')"
    name="settings"
/>
