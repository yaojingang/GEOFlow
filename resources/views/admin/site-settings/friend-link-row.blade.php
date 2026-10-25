@php
    $friendRow = is_array($friendRow) ? $friendRow : [];
    $rowErrorKey = 'friend_links.links.'.$rowIndex;
@endphp
<section data-friend-row class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="friend-link-row-{{ $rowIndex }}">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h3 data-friend-row-title id="friend-link-row-{{ $rowIndex }}" class="text-base font-semibold text-gray-900">
            {{ __('friend_links.row') }} <span data-row-number>{{ is_int($rowIndex) ? $rowIndex + 1 : '' }}</span>
        </h3>
        <div class="flex flex-wrap items-center gap-2">
            <label class="inline-flex min-h-10 items-center gap-2 rounded-md border border-gray-200 bg-gray-50 px-3 text-sm font-medium text-gray-700">
                <input data-field="enabled" type="hidden" name="friend_links[links][{{ $rowIndex }}][enabled]" value="0">
                <input data-field="enabled" type="checkbox" name="friend_links[links][{{ $rowIndex }}][enabled]" value="1" @checked($friendValue($friendRow['enabled'] ?? '') === '1') class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                {{ __('friend_links.enabled') }}
            </label>
            <button type="button" data-friend-remove class="inline-flex min-h-10 items-center justify-center rounded-md border border-red-200 bg-white px-3 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                <i data-lucide="trash-2" class="mr-1.5 h-4 w-4" aria-hidden="true"></i>
                {{ __('friend_links.remove') }}
            </button>
        </div>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-12">
        @foreach(['name', 'url', 'sort_order'] as $field)
            <div class="min-w-0 {{ $field === 'url' ? 'md:col-span-6' : ($field === 'name' ? 'md:col-span-4' : 'md:col-span-2') }}">
                <label data-friend-label="{{ $field }}" class="block text-sm font-medium text-gray-700" for="friend-link-{{ $rowIndex }}-{{ $field }}">{{ __('friend_links.'.$field) }}</label>
                <input id="friend-link-{{ $rowIndex }}-{{ $field }}" data-field="{{ $field }}" data-friend-control name="friend_links[links][{{ $rowIndex }}][{{ $field }}]"
                       type="{{ $field === 'sort_order' ? 'number' : ($field === 'url' ? 'url' : 'text') }}" required
                       @if($field === 'sort_order') min="0" max="9999" step="1" @else maxlength="{{ $field === 'name' ? 80 : 2048 }}" @endif
                       value="{{ $friendValue($friendRow[$field] ?? '') }}"
                       @if($friendErrors->has($rowErrorKey.'.'.$field)) aria-invalid="true" aria-describedby="friend-link-{{ $rowIndex }}-{{ $field }}-error" @endif
                       class="mt-2 block min-h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20">
                @if($friendErrors->has($rowErrorKey.'.'.$field))
                    <p id="friend-link-{{ $rowIndex }}-{{ $field }}-error" class="mt-2 text-sm text-red-600" role="alert">{{ $friendErrors->first($rowErrorKey.'.'.$field) }}</p>
                @endif
            </div>
        @endforeach
    </div>

    <details class="group mt-5 overflow-hidden rounded-md border border-gray-200 bg-gray-50" @if($friendErrors->has($rowErrorKey.'.target') || $friendErrors->has($rowErrorKey.'.relationship')) open @endif>
        <summary class="flex min-h-10 cursor-pointer list-none items-center justify-between gap-3 px-3 py-2 text-sm font-medium text-gray-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500 [&::-webkit-details-marker]:hidden">
            {{ __('friend_links.advanced') }}
            <i data-lucide="chevron-down" class="h-4 w-4 shrink-0 text-gray-400 transition-transform group-open:rotate-180" aria-hidden="true"></i>
        </summary>
        <div class="grid gap-4 border-t border-gray-200 bg-white p-4 sm:grid-cols-2">
            @foreach(['target' => ['_blank' => 'blank', '_self' => 'self'], 'relationship' => ['regular' => 'regular', 'nofollow' => 'nofollow', 'sponsored' => 'sponsored']] as $field => $options)
                <div>
                    <label data-friend-label="{{ $field }}" class="block text-sm font-medium text-gray-700" for="friend-link-{{ $rowIndex }}-{{ $field }}">{{ __('friend_links.'.$field) }}</label>
                    <select id="friend-link-{{ $rowIndex }}-{{ $field }}" data-field="{{ $field }}" data-friend-control name="friend_links[links][{{ $rowIndex }}][{{ $field }}]" @if($friendErrors->has($rowErrorKey.'.'.$field)) aria-invalid="true" aria-describedby="friend-link-{{ $rowIndex }}-{{ $field }}-error" @endif class="mt-2 block min-h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20">
                        @foreach($options as $value => $label)
                            <option value="{{ $value }}" @selected($friendValue($friendRow[$field] ?? '') === $value)>{{ __('friend_links.'.$label) }}</option>
                        @endforeach
                    </select>
                    @if($friendErrors->has($rowErrorKey.'.'.$field))
                        <p id="friend-link-{{ $rowIndex }}-{{ $field }}-error" class="mt-2 text-sm text-red-600" role="alert">{{ $friendErrors->first($rowErrorKey.'.'.$field) }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </details>
</section>
