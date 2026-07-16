@props(['data'])

<script type="application/ld+json">{!! Illuminate\Support\Js::encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
