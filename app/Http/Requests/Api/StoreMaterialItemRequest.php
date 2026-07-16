<?php

namespace App\Http\Requests\Api;

use App\Exceptions\ApiException;
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
        $image = $this->file('image');
        $hasImageInput = $image instanceof UploadedFile || $this->exists('image');
        if (! in_array($type, ['image-libraries', 'images'], true) || ! $hasImageInput) {
            return [];
        }

        $maxKilobytes = max(1, (int) ceil((int) config('geoflow.max_upload_bytes', 2 * 1024 * 1024) / 1024));

        return [
            'image' => [
                'required',
                File::image()->types(['jpg', 'jpeg', 'png', 'gif', 'webp'])->max($maxKilobytes),
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

    protected function failedValidation(Validator $validator): never
    {
        $fieldErrors = collect($validator->errors()->messages())
            ->map(fn (array $messages): string => (string) ($messages[0] ?? 'Invalid value.'))
            ->all();

        throw new ApiException('validation_failed', '参数校验失败', 422, [
            'field_errors' => $fieldErrors,
        ]);
    }
}
