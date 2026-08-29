@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6 px-4 sm:px-0">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0 flex-1">
                <div class="sr-only">
                    <h1>{{ __('admin_pages.hosted_sites') }}</h1>
                    <p>管理共享部署下的二级站点、发布容量、质量门禁和索引状态。</p>
                </div>
                <x-admin.v3.distribution-subnav active="hosted-sites" />
            </div>
            <a href="{{ route('admin.distribution.hosted-sites.create') }}" class="inline-flex min-h-10 items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-transform active:scale-[.96] hover:bg-blue-700 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
                <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                创建托管站点
            </a>
        </header>

        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-y border-gray-200 py-4 text-sm">
            <span class="text-gray-600">站点总数 <strong class="ml-1 tabular-nums text-gray-900">{{ $profiles->total() }}</strong></span>
            <span class="text-gray-600">待分配请求 <strong class="ml-1 tabular-nums text-amber-700">{{ $pendingAllocationCount }}</strong></span>
        </div>

        @if ($profiles->isEmpty())
            <div class="py-16 text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-500"><i data-lucide="network" class="h-6 w-6"></i></div>
                <h2 class="mt-4 text-base font-semibold text-gray-900">还没有托管站点</h2>
                <p class="mt-1 text-sm text-gray-600">先创建一个维护状态的灰度站点，再执行预检和上线。</p>
            </div>
        @else
            <div class="overflow-hidden border-y border-gray-200 bg-white">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm" data-sticky-actions>
                        <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-5 py-3">站点</th>
                                <th class="px-5 py-3">服务</th>
                                <th class="px-5 py-3">质量与索引</th>
                                <th class="px-5 py-3 text-right">容量</th>
                                <th class="px-5 py-3 text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($profiles as $profile)
                                <tr>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-900">{{ $profile->channel?->name }}</div>
                                        <div class="mt-1 text-gray-500">{{ $profile->hostname }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span @class([
                                            'inline-flex rounded-full px-2.5 py-1 text-xs font-medium',
                                            'bg-emerald-50 text-emerald-700' => $profile->serving_status === 'online',
                                            'bg-amber-50 text-amber-700' => $profile->serving_status === 'maintenance',
                                            'bg-gray-100 text-gray-600' => $profile->serving_status === 'archived',
                                        ])>{{ $profile->serving_status }}</span>
                                        <div class="mt-2 text-xs text-gray-500">渠道 {{ $profile->channel?->status }}</div>
                                    </td>
                                    <td class="px-5 py-4 text-gray-700">
                                        <div>{{ $profile->quality_status }}</div>
                                        <div class="mt-1 text-xs text-gray-500">{{ $profile->indexing_status }}</div>
                                    </td>
                                    <td class="px-5 py-4 text-right tabular-nums text-gray-700">
                                        <div>今日 {{ (int) $profile->today_used_count }} / {{ (int) $profile->daily_publish_limit }}</div>
                                        <div class="mt-1 text-xs text-gray-500">累计 {{ (int) $profile->published_count }} 已发布，{{ (int) $profile->reserved_count }} 预留</div>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <a href="{{ route('admin.distribution.hosted-sites.show', $profile->channel) }}" class="inline-flex min-h-10 items-center font-medium text-blue-700 hover:text-blue-900 focus-visible:ring-2 focus-visible:ring-blue-500">查看详情</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            {{ $profiles->links() }}
        @endif
    </div>
@endsection
