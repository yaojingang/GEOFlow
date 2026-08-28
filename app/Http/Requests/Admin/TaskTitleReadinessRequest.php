<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TaskTitleReadinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title_library_id' => ['required', 'integer', 'min:1', 'exists:title_libraries,id'],
            'article_limit' => ['required', 'integer', 'min:1', 'max:99999'],
            'is_loop' => ['required', 'boolean'],
            'status' => ['required', 'string', 'in:active,paused'],
            'task_id' => ['nullable', 'integer', 'min:1', 'exists:tasks,id'],
        ];
    }
}
