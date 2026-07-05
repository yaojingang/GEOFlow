@php
    $embedded = (bool) ($embedded ?? false);
    $leadFormTitle = trim((string) ($title ?? $leadForm->name));
    $leadFormDescription = trim((string) ($description ?? $leadForm->description ?? ''));
@endphp

<div class="geo-lead-form rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
    @if(session('message'))
        <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
            {{ session('message') }}
        </div>
    @endif

    @if($leadFormTitle !== '' || $leadFormDescription !== '')
        <div class="mb-5">
            @if($leadFormTitle !== '')
                <h2 class="{{ $embedded ? 'text-xl' : 'text-2xl' }} font-semibold text-gray-900">{{ $leadFormTitle }}</h2>
            @endif
            @if($leadFormDescription !== '')
                <p class="mt-2 text-sm leading-6 text-gray-600">{{ $leadFormDescription }}</p>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('site.lead-forms.submit', ['slug' => $leadForm->slug]) }}" class="space-y-4">
        @csrf
        <input type="hidden" name="source_url" value="{{ url()->full() }}">
        <div class="absolute left-[-9999px] top-auto h-1 w-1 overflow-hidden" aria-hidden="true">
            <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        @foreach ($leadForm->normalizedFields() as $field)
            @php
                $name = $field['name'];
                $type = $field['type'];
                $fieldId = 'lead-field-'.$leadForm->id.'-'.$name;
            @endphp
            <div>
                <label for="{{ $fieldId }}" class="mb-2 block text-sm font-medium text-gray-700">
                    {{ $field['label'] }}
                    @if($field['required'])
                        <span class="text-red-500">*</span>
                    @endif
                </label>

                @if($type === 'textarea')
                    <textarea id="{{ $fieldId }}" name="{{ $name }}" rows="4" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900">{{ old($name) }}</textarea>
                @elseif($type === 'select')
                    <select id="{{ $fieldId }}" name="{{ $name }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900">
                        <option value="">{{ __('site.lead_forms.select_placeholder') }}</option>
                        @foreach ($field['options'] as $option)
                            <option value="{{ $option }}" @selected(old($name) === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                @elseif($type === 'checkbox')
                    <label class="inline-flex items-start gap-2 text-sm text-gray-700">
                        <input type="checkbox" id="{{ $fieldId }}" name="{{ $name }}" value="1" @checked((bool) old($name)) class="mt-0.5 rounded border-gray-300 text-gray-900 focus:ring-gray-900">
                        <span>{{ $field['options'][0] ?? $field['label'] }}</span>
                    </label>
                @else
                    <input id="{{ $fieldId }}" type="{{ $type === 'email' ? 'email' : ($type === 'phone' ? 'tel' : 'text') }}" name="{{ $name }}" value="{{ old($name) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900">
                @endif

                @error($name)
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        @endforeach

        <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-gray-800">
            {{ $leadForm->submit_button_label ?: __('site.lead_forms.submit') }}
        </button>
    </form>
</div>
