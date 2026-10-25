<?php

namespace App\Http\Requests\Admin\AiWorkspace;

final class SendMessageRequest extends AiWorkspaceRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'task_draft_id' => ['required_with:task_choice', 'uuid'],
            'task_draft_revision' => ['required_with:task_draft_id,task_choice', 'integer', 'min:1'],
            'task_choice' => ['sometimes', 'array:field,id', 'required_array_keys:field,id'],
            'task_choice.field' => ['required_with:task_choice', 'in:title_library_id,prompt_id,ai_model_id,fixed_category_id,author_id,image_library_id,knowledge_base_ids'],
            'task_choice.id' => ['required_with:task_choice', 'integer', 'min:1'],
        ];
    }
}
