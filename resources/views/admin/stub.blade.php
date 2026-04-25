@extends('admin.layouts.app')

@section('content')
    <div class="px-4 py-6 sm:px-0">
        <h1 class="text-2xl font-bold text-gray-900">{{ $pageTitle }}</h1>
        <p class="mt-3 text-gray-600 max-w-2xl">
            本页对应 bak/admin 同名功能，已接入统一布局与路由；表单与数据库操作将按模块陆续从 bak 迁移至 Laravel 控制器与服务层。
        </p>
        @isset($stubHint)
            <p class="mt-2 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded px-3 py-2">{{ $stubHint }}</p>
        @endisset
    </div>
@endsection
