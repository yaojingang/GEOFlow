@props([
    'libraryForm' => [],
])

@php
    $normalizeScalar = static function (mixed $value, mixed $fallback = ''): string {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($fallback) || is_int($fallback) || is_float($fallback)
            ? (string) $fallback
            : '';
    };
    $fieldValue = static function (string $field) use ($libraryForm, $normalizeScalar): string {
        $fallback = $normalizeScalar(data_get($libraryForm, $field, ''));

        return $normalizeScalar(old($field, $fallback), $fallback);
    };
    $errorIds = [
        'name' => 'image-library-name-error',
        'description' => 'image-library-description-error',
    ];
@endphp

<fieldset>
    <legend class="text-sm font-semibold text-gray-900">{{ __('admin.image_libraries.page_title') }}</legend>
    <div class="mt-4 grid grid-cols-1 gap-5">
        <div>
            <label for="name" class="block text-sm font-medium text-gray-700">{{ __('admin.image_libraries.field_name') }}</label>
            <input id="name" name="name" type="text" required maxlength="100" value="{{ $fieldValue('name') }}" autocomplete="off" @error('name') aria-invalid="true" aria-describedby="{{ $errorIds['name'] }}" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.image_libraries.placeholder_name') }}">
            @error('name')
                <p id="{{ $errorIds['name'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <label for="description" class="block text-sm font-medium text-gray-700">{{ __('admin.image_libraries.field_description') }}</label>
            <textarea id="description" name="description" rows="5" @error('description') aria-invalid="true" aria-describedby="{{ $errorIds['description'] }}" @enderror class="mt-1 block w-full resize-y rounded-lg border-gray-300 text-sm leading-6 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.image_libraries.placeholder_description') }}">{{ $fieldValue('description') }}</textarea>
            @error('description')
                <p id="{{ $errorIds['description'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>
</fieldset>
