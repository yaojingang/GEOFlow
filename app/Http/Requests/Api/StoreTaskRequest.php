<?php

namespace App\Http\Requests\Api;

class StoreTaskRequest extends UpdateTaskRequest
{
    /** @return array<string,list<mixed>> */
    public function rules(): array
    {
        if ($this->input('content_type') === 'topic') {
            return array_replace(parent::rules(), ['target_site_key' => ['required', 'string'], 'topic_limit' => ['required', 'integer'], 'publish_interval' => ['required', 'integer']]);
        }

        return array_replace(parent::rules(), [
            'name' => ['required', 'string', 'max:200'],
            'title_library_id' => ['required', 'integer', 'min:1'],
            'prompt_id' => ['required', 'integer', 'min:1'],
            'ai_model_id' => ['required', 'integer', 'min:1'],
        ]);
    }
}
