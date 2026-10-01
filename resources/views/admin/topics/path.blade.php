@extends('admin.layouts.app')
@section('topbar-title','专题地址')
@section('content')
@include('admin.topics._style')
<div class="topic-shell space-y-5"><div class="flex justify-between gap-4"><div><h1 class="text-2xl font-semibold">专题地址</h1><p class="topic-help mt-2">{{ $topic->title }} · 当前地址 /topics/{{ $topic->slug }}</p></div><a class="topic-button" href="{{ route('admin.topics.edit',['topic'=>$topic->id]) }}">返回编辑</a></div>
<p class="topic-panel topic-help">标题更新时地址保持稳定。主动修改地址会保留旧地址，并将可公开阅读的旧链接直接跳转到新地址。回收站和历史专题使用过的地址会持续保留。</p>
@foreach($errors->all() as $error)<p class="topic-error" role="alert">{{ $error }}</p>@endforeach
@if($preview)<section class="topic-panel space-y-4"><h2 class="text-xl font-semibold">确认地址变更</h2><dl><dt>原地址</dt><dd>/topics/{{ $preview['old_slug'] }}</dd><dt>新地址</dt><dd>/topics/{{ $preview['new_slug'] }}</dd></dl><p class="topic-help">旧链接返回 301 跳转。正文与来源保留，待审核版本失效，自动维护暂停。预检有效期为 10 分钟。</p><form method="POST" action="{{ route('admin.topics.paths.confirm',['topic'=>$topic->id]) }}">@csrf<input type="hidden" name="token" value="{{ $preview['token'] }}"><button class="topic-button topic-primary">确认更新地址</button></form></section>@endif
<form class="topic-panel space-y-4" method="POST" action="{{ route('admin.topics.paths.preview',['topic'=>$topic->id]) }}">@csrf<label for="new-path">新地址的最后一段</label><input id="new-path" name="path" value="{{ old('path',$preview['new_slug']??'') }}" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="120" required placeholder="例如 geo-content-guide"><p class="topic-help">使用小写英文、数字和短横线。</p><button class="topic-button topic-primary">预检地址</button></form></div>
@endsection
