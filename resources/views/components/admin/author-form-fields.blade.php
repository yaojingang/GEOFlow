@props([
    'authorForm' => [],
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
    $fieldValue = static function (string $field) use ($authorForm, $normalizeScalar): string {
        $fallback = $normalizeScalar(data_get($authorForm, $field, ''));

        return $normalizeScalar(old($field, $fallback), $fallback);
    };
    $errorIds = [
        'name' => 'author-name-error',
        'email' => 'author-email-error',
        'bio' => 'author-bio-error',
        'website' => 'author-website-error',
        'social_links' => 'author-social-links-error',
    ];
@endphp

<div class="space-y-8">
    <fieldset>
        <legend class="text-sm font-semibold text-gray-900">{{ __('admin.authors.page_title') }}</legend>
        <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700">{{ __('admin.authors.field_name') }}</label>
                <input id="name" name="name" type="text" required maxlength="100" value="{{ $fieldValue('name') }}" autocomplete="name" @error('name') aria-invalid="true" aria-describedby="{{ $errorIds['name'] }}" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.authors.placeholder_name') }}">
                @error('name')
                    <p id="{{ $errorIds['name'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">{{ __('admin.authors.field_email') }}</label>
                <input id="email" name="email" type="text" inputmode="email" maxlength="100" value="{{ $fieldValue('email') }}" autocomplete="email" @error('email') aria-invalid="true" aria-describedby="{{ $errorIds['email'] }}" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.authors.placeholder_email') }}">
                @error('email')
                    <p id="{{ $errorIds['email'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </fieldset>

    <fieldset class="border-t border-gray-200 pt-7">
        <legend class="text-sm font-semibold text-gray-900">{{ __('admin.authors.field_bio') }}</legend>
        <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2">
            <div class="md:col-span-2">
                <label for="bio" class="block text-sm font-medium text-gray-700">{{ __('admin.authors.field_bio') }}</label>
                <textarea id="bio" name="bio" rows="5" @error('bio') aria-invalid="true" aria-describedby="{{ $errorIds['bio'] }}" @enderror class="mt-1 block w-full resize-y rounded-lg border-gray-300 text-sm leading-6 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.authors.placeholder_bio') }}">{{ $fieldValue('bio') }}</textarea>
                @error('bio')
                    <p id="{{ $errorIds['bio'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="website" class="block text-sm font-medium text-gray-700">{{ __('admin.authors.field_website') }}</label>
                <input id="website" name="website" type="text" inputmode="url" maxlength="200" value="{{ $fieldValue('website') }}" autocomplete="url" spellcheck="false" @error('website') aria-invalid="true" aria-describedby="{{ $errorIds['website'] }}" @enderror class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="https://example.com">
                @error('website')
                    <p id="{{ $errorIds['website'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="social_links" class="block text-sm font-medium text-gray-700">{{ __('admin.authors.field_social') }}</label>
                <textarea id="social_links" name="social_links" rows="3" @error('social_links') aria-invalid="true" aria-describedby="{{ $errorIds['social_links'] }}" @enderror class="mt-1 block w-full resize-y rounded-lg border-gray-300 text-sm leading-6 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="{{ __('admin.authors.placeholder_social') }}">{{ $fieldValue('social_links') }}</textarea>
                @error('social_links')
                    <p id="{{ $errorIds['social_links'] }}" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </fieldset>
</div>
