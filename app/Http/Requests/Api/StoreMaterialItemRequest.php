<?php

namespace App\Http\Requests\Api;

use App\Exceptions\ApiException;
use App\Support\ImageLibraryUploadPolicy;
use App\Support\LibraryImportPolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;

class StoreMaterialItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string,list<mixed>>
     */
    public function rules(): array
    {
        $type = str_replace('_', '-', (string) $this->route('type'));
        if (in_array($type, ['keyword-libraries', 'keywords'], true)) {
            return [
                'keyword' => [
                    'required',
                    'string',
                    'max:'.LibraryImportPolicy::KEYWORD_MAX_CHARACTERS,
                    LibraryImportPolicy::rejectNullByteRule(__('admin.library_validation.keyword_nul')),
                    LibraryImportPolicy::rejectInvalidUtf8Rule(__('admin.library_validation.keyword_utf8')),
                ],
            ];
        }
        if (in_array($type, ['title-libraries', 'titles'], true)) {
            return [
                'title' => [
                    'required',
                    'string',
                    'max:'.LibraryImportPolicy::TITLE_MAX_CHARACTERS,
                    LibraryImportPolicy::rejectNullByteRule(__('admin.library_validation.title_nul')),
                    LibraryImportPolicy::rejectInvalidUtf8Rule(__('admin.library_validation.title_utf8')),
                ],
                'keyword' => [
                    'nullable',
                    'string',
                    'max:'.LibraryImportPolicy::TITLE_KEYWORD_MAX_CHARACTERS,
                    LibraryImportPolicy::rejectNullByteRule(__('admin.library_validation.related_keyword_nul')),
                    LibraryImportPolicy::rejectInvalidUtf8Rule(__('admin.library_validation.related_keyword_utf8')),
                ],
            ];
        }

        $image = $this->file('image');
        $hasImageInput = $image instanceof UploadedFile || $this->exists('image');
        if (! in_array($type, ['image-libraries', 'images'], true) || ! $hasImageInput) {
            return [];
        }

        return [
            'image' => [
                'required',
                File::image()
                    ->types(ImageLibraryUploadPolicy::EXTENSIONS)
                    ->max(ImageLibraryUploadPolicy::maxKilobytes()),
            ],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $image = $this->file('image');
                if (! $image instanceof UploadedFile || ! $image->isValid()) {
                    return;
                }

                $realPath = $image->getRealPath();
                if (! is_string($realPath) || $realPath === '' || @getimagesize($realPath) === false) {
                    $validator->errors()->add('image', 'The image field must be a valid image.');
                }
            },
        ];
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        $type = str_replace('_', '-', (string) $this->route('type'));

        return [
            'keyword.required' => __('admin.library_validation.keyword_required'),
            'keyword.string' => in_array($type, ['title-libraries', 'titles'], true)
                ? __('admin.library_validation.related_keyword_string')
                : __('admin.library_validation.keyword_string'),
            'keyword.max' => in_array($type, ['title-libraries', 'titles'], true)
                ? __('admin.library_validation.related_keyword_too_long', ['max' => LibraryImportPolicy::TITLE_KEYWORD_MAX_CHARACTERS])
                : __('admin.library_validation.keyword_too_long', ['max' => LibraryImportPolicy::KEYWORD_MAX_CHARACTERS]),
            'title.required' => __('admin.library_validation.title_required'),
            'title.string' => __('admin.library_validation.title_string'),
            'title.max' => __('admin.library_validation.title_too_long', ['max' => LibraryImportPolicy::TITLE_MAX_CHARACTERS]),
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        $fieldErrors = collect($validator->errors()->messages())
            ->map(fn (array $messages): string => (string) ($messages[0] ?? 'Invalid value.'))
            ->all();

        throw new ApiException('validation_failed', __('admin.library_validation.validation_failed'), 422, [
            'field_errors' => $fieldErrors,
        ]);
    }
}
