@extends('admin.layouts.app')

@php
    $roleMap = ['student' => '学生', 'parent' => '家长', 'tutor' => '老师', 'organization' => '机构'];
    $roleLabel = fn(?string $role) => $roleMap[$role] ?? ($role ?? '—');
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-900">撮合大厅</h1>
            <p class="mt-1 text-sm text-gray-600">学生 / 家长 / 老师 / 机构 四方档案、需求供给与匹配记录（只读）</p>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-5">
            @foreach ([
                ['label' => '档案', 'value' => (int) ($stats->profiles ?? 0)],
                ['label' => '需求', 'value' => (int) ($stats->requirements ?? 0)],
                ['label' => '供给', 'value' => (int) ($stats->offers ?? 0)],
                ['label' => '匹配记录', 'value' => (int) ($stats->records ?? 0)],
                ['label' => '待跟进', 'value' => (int) ($stats->pending ?? 0)],
            ] as $card)
                <div class="rounded-lg border border-gray-200 bg-white p-4">
                    <div class="text-sm text-gray-500">{{ $card['label'] }}</div>
                    <div class="mt-1 text-2xl font-bold text-gray-900">{{ $card['value'] }}</div>
                </div>
            @endforeach
        </div>

        <div class="mb-8 rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4"><h2 class="font-semibold text-gray-900">四方档案</h2></div>
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr><th class="px-5 py-2">ID</th><th class="px-5 py-2">角色</th><th class="px-5 py-2">名称</th><th class="px-5 py-2">地区</th><th class="px-5 py-2">学段</th><th class="px-5 py-2">科目</th><th class="px-5 py-2">状态</th></tr>
                </thead>
                <tbody>
                @forelse ($profiles as $row)
                    <tr class="border-t border-gray-100">
                        <td class="px-5 py-2">{{ $row->id }}</td>
                        <td class="px-5 py-2">{{ $roleLabel($row->role) }}</td>
                        <td class="px-5 py-2">{{ $row->name }}</td>
                        <td class="px-5 py-2">{{ $row->region ?: '—' }}</td>
                        <td class="px-5 py-2">{{ $row->grade_level ?: '—' }}</td>
                        <td class="px-5 py-2">{{ $row->subjects ?: '—' }}</td>
                        <td class="px-5 py-2">{{ $row->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-5 py-6 text-center text-gray-400">暂无档案</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mb-8 rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4"><h2 class="font-semibold text-gray-900">需求</h2></div>
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr><th class="px-5 py-2">ID</th><th class="px-5 py-2">提出方</th><th class="px-5 py-2">标题</th><th class="px-5 py-2">地区</th><th class="px-5 py-2">学段</th><th class="px-5 py-2">科目</th><th class="px-5 py-2">状态</th></tr>
                </thead>
                <tbody>
                @forelse ($requirements as $row)
                    <tr class="border-t border-gray-100">
                        <td class="px-5 py-2">{{ $row->id }}</td>
                        <td class="px-5 py-2">{{ $row->profile_name ?: '—' }}（{{ $roleLabel($row->role) }}）</td>
                        <td class="px-5 py-2">{{ $row->title }}</td>
                        <td class="px-5 py-2">{{ $row->region ?: '—' }}</td>
                        <td class="px-5 py-2">{{ $row->grade_level ?: '—' }}</td>
                        <td class="px-5 py-2">{{ $row->subjects ?: '—' }}</td>
                        <td class="px-5 py-2">{{ $row->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-5 py-6 text-center text-gray-400">暂无需求</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mb-8 rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4"><h2 class="font-semibold text-gray-900">供给</h2></div>
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr><th class="px-5 py-2">ID</th><th class="px-5 py-2">提供方</th><th class="px-5 py-2">标题</th><th class="px-5 py-2">地区</th><th class="px-5 py-2">学段</th><th class="px-5 py-2">科目</th><th class="px-5 py-2">状态</th></tr>
                </thead>
                <tbody>
                @forelse ($offers as $row)
                    <tr class="border-t border-gray-100">
                        <td class="px-5 py-2">{{ $row->id }}</td>
                        <td class="px-5 py-2">{{ $row->profile_name ?: '—' }}（{{ $roleLabel($row->role) }}）</td>
                        <td class="px-5 py-2">{{ $row->title }}</td>
                        <td class="px-5 py-2">{{ $row->region ?: '—' }}</td>
                        <td class="px-5 py-2">{{ $row->grade_level ?: '—' }}</td>
                        <td class="px-5 py-2">{{ $row->subjects ?: '—' }}</td>
                        <td class="px-5 py-2">{{ $row->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-5 py-6 text-center text-gray-400">暂无供给</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mb-8 rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-5 py-4"><h2 class="font-semibold text-gray-900">匹配记录</h2></div>
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr><th class="px-5 py-2">ID</th><th class="px-5 py-2">需求</th><th class="px-5 py-2">供给</th><th class="px-5 py-2">评分</th><th class="px-5 py-2">状态</th><th class="px-5 py-2">时间</th></tr>
                </thead>
                <tbody>
                @forelse ($matches as $row)
                    <tr class="border-t border-gray-100">
                        <td class="px-5 py-2">{{ $row->id }}</td>
                        <td class="px-5 py-2">{{ $row->requirement ?: '—' }}</td>
                        <td class="px-5 py-2">{{ $row->offer ?: '—' }}</td>
                        <td class="px-5 py-2">{{ $row->score !== null ? number_format((float) $row->score, 2) : '—' }}</td>
                        <td class="px-5 py-2">{{ $row->status }}</td>
                        <td class="px-5 py-2">{{ $row->created_at }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-6 text-center text-gray-400">暂无匹配记录</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
