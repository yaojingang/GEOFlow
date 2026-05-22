@extends('admin.layouts.app')

@section('content')
    @php
        $singleSiteCards = [
            [
                'title' => __('admin.dashboard.navigation.ai_config_title'),
                'desc' => __('admin.dashboard.navigation.ai_config_desc'),
                'href' => route('admin.ai-models.index'),
                'icon' => 'cpu',
                'tone' => 'text-indigo-600 bg-indigo-50',
            ],
            [
                'title' => __('admin.dashboard.navigation.materials_title'),
                'desc' => __('admin.dashboard.navigation.materials_desc'),
                'href' => route('admin.materials.index'),
                'icon' => 'database',
                'tone' => 'text-emerald-600 bg-emerald-50',
            ],
            [
                'title' => __('admin.dashboard.navigation.url_import_title'),
                'desc' => __('admin.dashboard.navigation.url_import_desc'),
                'href' => route('admin.url-import'),
                'icon' => 'link',
                'tone' => 'text-cyan-600 bg-cyan-50',
            ],
            [
                'title' => __('admin.dashboard.navigation.create_task_title'),
                'desc' => __('admin.dashboard.navigation.create_task_desc'),
                'href' => route('admin.tasks.create'),
                'icon' => 'plus-circle',
                'tone' => 'text-blue-600 bg-blue-50',
            ],
            [
                'title' => __('admin.dashboard.navigation.articles_title'),
                'desc' => __('admin.dashboard.navigation.articles_desc'),
                'href' => route('admin.articles.index'),
                'icon' => 'file-text',
                'tone' => 'text-slate-700 bg-slate-100',
            ],
            [
                'title' => __('admin.dashboard.navigation.site_settings_title'),
                'desc' => __('admin.dashboard.navigation.site_settings_desc'),
                'href' => route('admin.site-settings.index'),
                'icon' => 'settings',
                'tone' => 'text-amber-600 bg-amber-50',
            ],
            [
                'title' => __('admin.dashboard.navigation.analytics_title'),
                'desc' => __('admin.dashboard.navigation.analytics_desc'),
                'href' => route('admin.analytics'),
                'icon' => 'bar-chart-3',
                'tone' => 'text-violet-600 bg-violet-50',
            ],
            [
                'title' => __('admin.dashboard.navigation.prompt_config_title'),
                'desc' => __('admin.dashboard.navigation.prompt_config_desc'),
                'href' => route('admin.ai-prompts'),
                'icon' => 'message-square-text',
                'tone' => 'text-rose-600 bg-rose-50',
                'links' => [
                    ['label' => __('admin.dashboard.navigation.body_prompt_label'), 'href' => route('admin.ai-prompts')],
                    ['label' => __('admin.dashboard.navigation.special_prompt_label'), 'href' => route('admin.ai-special-prompts')],
                ],
            ],
            [
                'title' => __('admin.dashboard.navigation.admin_users_title'),
                'desc' => __('admin.dashboard.navigation.admin_users_desc'),
                'href' => route('admin.admin-users.index'),
                'icon' => 'users',
                'tone' => 'text-slate-700 bg-slate-100',
            ],
        ];

        $multiSiteCards = [
            [
                'title' => __('admin.dashboard.navigation.distribution_channels_title'),
                'desc' => __('admin.dashboard.navigation.distribution_channels_desc'),
                'href' => route('admin.distribution.index'),
                'icon' => 'radio-tower',
                'tone' => 'text-blue-600 bg-blue-50',
            ],
            [
                'title' => __('admin.dashboard.navigation.create_channel_title'),
                'desc' => __('admin.dashboard.navigation.create_channel_desc'),
                'href' => route('admin.distribution.create'),
                'icon' => 'square-plus',
                'tone' => 'text-emerald-600 bg-emerald-50',
            ],
            [
                'title' => __('admin.dashboard.navigation.distribution_jobs_title'),
                'desc' => __('admin.dashboard.navigation.distribution_jobs_desc'),
                'href' => route('admin.distribution.jobs'),
                'icon' => 'list-checks',
                'tone' => 'text-orange-600 bg-orange-50',
            ],
            [
                'title' => __('admin.dashboard.navigation.remote_content_title'),
                'desc' => __('admin.dashboard.navigation.remote_content_desc'),
                'href' => route('admin.distribution.jobs'),
                'icon' => 'file-pen-line',
                'tone' => 'text-rose-600 bg-rose-50',
            ],
            [
                'title' => __('admin.dashboard.navigation.create_task_title'),
                'desc' => __('admin.dashboard.navigation.create_task_desc'),
                'href' => route('admin.tasks.create'),
                'icon' => 'workflow',
                'tone' => 'text-slate-700 bg-slate-100',
            ],
            [
                'title' => __('admin.dashboard.navigation.analytics_title'),
                'desc' => __('admin.dashboard.navigation.analytics_desc'),
                'href' => route('admin.analytics'),
                'icon' => 'chart-no-axes-combined',
                'tone' => 'text-violet-600 bg-violet-50',
            ],
        ];

        $skillResourceCards = [
            [
                'title' => __('admin.dashboard.skill_resources.template_title'),
                'desc' => __('admin.dashboard.skill_resources.template_desc'),
                'href' => 'https://github.com/yaojingang/yao-geo-skills/tree/main/skills/yao-geoflow-template',
                'icon' => 'layers-3',
                'tone' => 'text-blue-600 bg-blue-50',
            ],
            [
                'title' => __('admin.dashboard.skill_resources.design_title'),
                'desc' => __('admin.dashboard.skill_resources.design_desc'),
                'href' => 'https://github.com/yaojingang/yao-geo-skills/tree/main/skills/yao-geoflow-design',
                'icon' => 'palette',
                'tone' => 'text-fuchsia-600 bg-fuchsia-50',
            ],
            [
                'title' => __('admin.dashboard.skill_resources.cli_title'),
                'desc' => __('admin.dashboard.skill_resources.cli_desc'),
                'href' => 'https://github.com/yaojingang/yao-geo-skills/tree/main/skills/yao-geoflow-cli',
                'icon' => 'terminal',
                'tone' => 'text-slate-700 bg-slate-100',
            ],
        ];
    @endphp

    <div class="px-4 sm:px-0">
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">{{ __('admin.dashboard.navigation.heading') }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ __('admin.dashboard.navigation.subtitle') }}</p>
        </div>

        <section class="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <div class="border-b border-gray-100 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-600">{{ __('admin.dashboard.quick_start.eyebrow') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-gray-900">{{ __('admin.dashboard.quick_start.title') }}</h2>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-500">{{ __('admin.dashboard.quick_start.subtitle') }}</p>
            </div>

            <div class="grid grid-cols-1 divide-y divide-gray-100 lg:grid-cols-3 lg:divide-x lg:divide-y-0">
                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white">1</div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ __('admin.dashboard.quick_start.api_title') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-500">{{ __('admin.dashboard.quick_start.api_desc') }}</p>
                            <a href="{{ route('admin.ai-models.index') }}" class="mt-4 inline-flex items-center rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                <i data-lucide="plug-zap" class="mr-1.5 h-4 w-4"></i>
                                {{ __('admin.dashboard.quick_start.api_button') }}
                            </a>
                        </div>
                    </div>
                </div>

                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-sm font-semibold text-white">2</div>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-base font-semibold text-gray-900">{{ __('admin.dashboard.quick_start.material_title') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-500">{{ __('admin.dashboard.quick_start.material_desc') }}</p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <a href="{{ route('admin.knowledge-bases.index') }}" class="inline-flex items-center rounded-full border border-orange-100 bg-orange-50 px-3 py-1.5 text-xs font-medium text-orange-700 hover:bg-orange-100">
                                    {{ __('admin.dashboard.quick_start.knowledge') }}
                                </a>
                                <a href="{{ route('admin.title-libraries.index') }}" class="inline-flex items-center rounded-full border border-green-100 bg-green-50 px-3 py-1.5 text-xs font-medium text-green-700 hover:bg-green-100">
                                    {{ __('admin.dashboard.quick_start.titles') }}
                                </a>
                                <a href="{{ route('admin.keyword-libraries.index') }}" class="inline-flex items-center rounded-full border border-blue-100 bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100">
                                    {{ __('admin.dashboard.quick_start.keywords') }}
                                </a>
                                <a href="{{ route('admin.image-libraries.index') }}" class="inline-flex items-center rounded-full border border-purple-100 bg-purple-50 px-3 py-1.5 text-xs font-medium text-purple-700 hover:bg-purple-100">
                                    {{ __('admin.dashboard.quick_start.images') }}
                                </a>
                                <a href="{{ route('admin.authors.index') }}" class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100">
                                    {{ __('admin.dashboard.quick_start.authors') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-900 text-sm font-semibold text-white">3</div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ __('admin.dashboard.quick_start.task_title') }}</h3>
                            <p class="mt-2 text-sm leading-6 text-gray-500">{{ __('admin.dashboard.quick_start.task_desc') }}</p>
                            <a href="{{ route('admin.tasks.create') }}" class="mt-4 inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                <i data-lucide="plus" class="mr-1.5 h-4 w-4"></i>
                                {{ __('admin.dashboard.quick_start.task_button') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="mb-8">
            <div class="mb-5">
                <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.dashboard.navigation.single_site_title') }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.dashboard.navigation.single_site_desc') }}</p>
            </div>
            <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($singleSiteCards as $card)
                    <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200 transition hover:-translate-y-0.5 hover:shadow-md">
                        <a href="{{ $card['href'] }}" class="block">
                            <div class="flex items-start gap-4">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $card['tone'] }}">
                                    <i data-lucide="{{ $card['icon'] }}" class="h-5 w-5"></i>
                                </div>
                                <div class="min-w-0">
                                    <h3 class="text-base font-semibold text-gray-900">{{ $card['title'] }}</h3>
                                    <p class="mt-2 text-sm leading-6 text-gray-500">{{ $card['desc'] }}</p>
                                </div>
                            </div>
                        </a>

                        @if (! empty($card['links']))
                            <div class="mt-4 flex flex-wrap gap-2 pl-14">
                                @foreach ($card['links'] as $link)
                                    <a href="{{ $link['href'] }}" class="rounded-full border border-gray-200 px-3 py-1 text-xs font-medium text-gray-600 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700">
                                        {{ $link['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <section class="mb-8">
            <div class="mb-5">
                <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.dashboard.navigation.multi_site_title') }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.dashboard.navigation.multi_site_desc') }}</p>
            </div>
            <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($multiSiteCards as $card)
                    <a href="{{ $card['href'] }}" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200 transition hover:-translate-y-0.5 hover:shadow-md">
                        <div class="flex items-start gap-4">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $card['tone'] }}">
                                <i data-lucide="{{ $card['icon'] }}" class="h-5 w-5"></i>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-gray-900">{{ $card['title'] }}</h3>
                                <p class="mt-2 text-sm leading-6 text-gray-500">{{ $card['desc'] }}</p>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>

        <section>
            <div class="mb-5">
                <h2 class="text-xl font-semibold text-gray-900">{{ __('admin.dashboard.skill_resources.title') }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.dashboard.skill_resources.desc') }}</p>
            </div>
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                @foreach ($skillResourceCards as $card)
                    <a href="{{ $card['href'] }}" target="_blank" rel="noopener noreferrer" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200 transition hover:-translate-y-0.5 hover:shadow-md">
                        <div class="flex items-start gap-4">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $card['tone'] }}">
                                <i data-lucide="{{ $card['icon'] }}" class="h-5 w-5"></i>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-base font-semibold text-gray-900">{{ $card['title'] }}</h3>
                                <p class="mt-2 text-sm leading-6 text-gray-500">{{ $card['desc'] }}</p>
                                <span class="mt-4 inline-flex items-center text-sm font-medium text-blue-600">
                                    {{ __('admin.dashboard.skill_resources.open') }}
                                    <i data-lucide="external-link" class="ml-1.5 h-4 w-4"></i>
                                </span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    </div>
@endsection
