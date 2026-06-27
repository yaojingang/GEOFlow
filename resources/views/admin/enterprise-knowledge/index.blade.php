@extends('admin.layouts.app')

@section('content')
    @php
        $statusStyles = [
            'draft' => 'bg-gray-100 text-gray-700',
            'reviewing' => 'bg-blue-50 text-blue-700 ring-blue-200',
            'published' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'failed' => 'bg-red-50 text-red-700 ring-red-200',
        ];
    @endphp

    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.enterprise_knowledge.index_heading') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.enterprise_knowledge.index_subtitle') }}</p>
            </div>
            <a href="{{ route('admin.enterprise-knowledge.create') }}" class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                {{ __('admin.enterprise_knowledge.new_project') }}
            </a>
        </div>

        <section class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            @foreach ([
                ['icon' => 'copy-plus', 'title' => __('admin.enterprise_knowledge.index_atom_source_title'), 'desc' => __('admin.enterprise_knowledge.index_atom_source_desc')],
                ['icon' => 'blocks', 'title' => __('admin.enterprise_knowledge.index_atom_structure_title'), 'desc' => __('admin.enterprise_knowledge.index_atom_structure_desc')],
                ['icon' => 'database-zap', 'title' => __('admin.enterprise_knowledge.index_atom_publish_title'), 'desc' => __('admin.enterprise_knowledge.index_atom_publish_desc')],
            ] as $card)
                <article class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-orange-50 text-orange-600">
                            <i data-lucide="{{ $card['icon'] }}" class="h-5 w-5"></i>
                        </div>
                        <div>
                            <h2 class="text-sm font-semibold text-gray-900">{{ $card['title'] }}</h2>
                            <p class="mt-1 text-sm leading-6 text-gray-500">{{ $card['desc'] }}</p>
                        </div>
                    </div>
                </article>
            @endforeach
        </section>

        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow">
            <div class="border-b border-gray-200 px-6 py-5">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.list_title') }}</h2>
            </div>

            @if ($projects->count() > 0)
                <div class="divide-y divide-gray-200">
                    @foreach ($projects as $project)
                        @php($status = (string) ($project->status ?? 'draft'))
                        <div class="px-6 py-5">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a href="{{ route('admin.enterprise-knowledge.show', ['projectId' => (int) $project->id]) }}" class="text-lg font-semibold text-gray-900 hover:text-blue-600">
                                            {{ $project->name }}
                                        </a>
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $statusStyles[$status] ?? $statusStyles['draft'] }}">
                                            {{ __('admin.enterprise_knowledge.status_'.$status) }}
                                        </span>
                                    </div>
                                    @if ((string) ($project->description ?? '') !== '')
                                        <p class="mt-2 max-w-4xl text-sm leading-6 text-gray-600">{{ $project->description }}</p>
                                    @endif
                                    <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-gray-500">
                                        <span>{{ __('admin.enterprise_knowledge.sources') }}: {{ (int) $project->sources_count }}</span>
                                        <span>{{ __('admin.enterprise_knowledge.revisions') }}: {{ (int) $project->revisions_count }}</span>
                                        <span>{{ __('admin.enterprise_knowledge.updated_at') }}: {{ optional($project->updated_at)->format('Y-m-d H:i') }}</span>
                                        @if ($project->publishedKnowledgeBase)
                                            <a href="{{ route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $project->publishedKnowledgeBase->id]) }}" class="font-medium text-blue-600 hover:text-blue-700">
                                                {{ __('admin.enterprise_knowledge.published_link') }}
                                            </a>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <a href="{{ route('admin.enterprise-knowledge.show', ['projectId' => (int) $project->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                                        <i data-lucide="file-pen-line" class="mr-2 h-4 w-4"></i>
                                        {{ __('admin.enterprise_knowledge.edit') }}
                                    </a>
                                    @php($confirmDeleteMessage = __('admin.enterprise_knowledge.confirm_delete', ['name' => (string) $project->name]))
                                    <form method="POST" action="{{ route('admin.enterprise-knowledge.delete', ['projectId' => (int) $project->id]) }}" onsubmit="return confirm(@json($confirmDeleteMessage))">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">
                                            <i data-lucide="trash-2" class="mr-2 h-4 w-4"></i>
                                            {{ __('admin.enterprise_knowledge.delete') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="border-t border-gray-200 px-6 py-4">
                    {{ $projects->links() }}
                </div>
            @else
                <div class="px-6 py-16 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-orange-50 text-orange-600">
                        <i data-lucide="sparkles" class="h-6 w-6"></i>
                    </div>
                    <h3 class="mt-4 text-base font-semibold text-gray-900">{{ __('admin.enterprise_knowledge.empty_title') }}</h3>
                    <p class="mt-2 text-sm text-gray-500">{{ __('admin.enterprise_knowledge.empty_desc') }}</p>
                    <a href="{{ route('admin.enterprise-knowledge.create') }}" class="mt-6 inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                        <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                        {{ __('admin.enterprise_knowledge.new_project') }}
                    </a>
                </div>
            @endif
        </div>
    </div>
@endsection
