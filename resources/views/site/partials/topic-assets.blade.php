@once
@php($topicThemeId=\App\Support\Site\SiteThemeViewResolver::activeThemeId())
<link rel="stylesheet" href="{{ asset($topicThemeId ? 'themes/'.$topicThemeId.'/topics.css' : 'assets/css/topics.css') }}">
<script src="{{ asset($topicThemeId ? 'themes/'.$topicThemeId.'/topics.js' : 'assets/js/topics.js') }}" defer></script>
@endonce
