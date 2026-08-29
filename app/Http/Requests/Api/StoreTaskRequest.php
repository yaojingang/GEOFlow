<?php

namespace App\Http\Requests\Api;

class StoreTaskRequest extends UpdateTaskRequest
{
    /** @return array<string,list<mixed>> */
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'name' => ['required', 'string', 'max:200'],
            'title_library_id' => ['required', 'integer', 'min:1'],
            'prompt_id' => ['required', 'integer', 'min:1'],
            'ai_model_id' => ['required', 'integer', 'min:1'],
        ]);
    }
}
