<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class ManualPublicationFormRequest extends FormRequest
{
    /** @return array<string, mixed> */
    protected function publicationRules(bool $includeStatus): array
    {
        $rules = [
            'type' => ['required', Rule::in(ManualPublication::TYPES)],
            'article_id' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('type') === ManualPublication::TYPE_POST),
                Rule::prohibitedIf(fn (): bool => $this->input('type') === ManualPublication::TYPE_COMMENT),
                'integer',
                Rule::exists((new Article)->getTable(), 'id')->whereNull('deleted_at'),
            ],
            'persona_id' => [
                'required',
                'integer',
                Rule::exists((new ManualPublicationPersona)->getTable(), 'id')->where('is_active', true),
            ],
            'account_id' => [
                'nullable',
                'integer',
                Rule::exists((new ManualPublicationAccount)->getTable(), 'id')->where('is_active', true),
            ],
            'assigned_admin_id' => [
                'nullable',
                'integer',
                Rule::exists((new Admin)->getTable(), 'id')->where('status', 'active'),
            ],
            'platform' => ['required', Rule::in(ManualPublicationAccount::PLATFORMS)],
            'custom_platform' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('platform') === ManualPublicationAccount::PLATFORM_CUSTOM),
                'string',
                'max:120',
            ],
            'target_url' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('type') === ManualPublication::TYPE_COMMENT),
                'url:http,https',
                'max:1000',
            ],
            'target_context' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('type') === ManualPublication::TYPE_COMMENT),
                'string',
                'max:5000',
            ],
            'content' => ['required', 'string', 'max:'.ManualPublication::MAX_CONTENT_CHARACTERS],
            'scheduled_at' => ['nullable', 'date'],
        ];

        if ($includeStatus) {
            $rules['status'] = ['required', Rule::in([
                ManualPublication::STATUS_DRAFT,
                ManualPublication::STATUS_READY,
            ])];
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $personaId = (int) $this->input('persona_id');
                $accountId = (int) $this->input('account_id');

                if ($accountId > 0) {
                    $account = ManualPublicationAccount::query()->find($accountId);
                    if ($account instanceof ManualPublicationAccount
                        && ((int) $account->persona_id !== $personaId || $account->platform !== $this->input('platform'))) {
                        $validator->errors()->add('account_id', __('admin.manual_publications.error.account_mismatch'));
                    }
                }

                if ($this->input('type') === ManualPublication::TYPE_POST) {
                    $article = Article::query()->find((int) $this->input('article_id'));
                    if ($article instanceof Article && ! in_array((string) $article->review_status, ['approved', 'auto_approved'], true)) {
                        $validator->errors()->add('article_id', __('admin.manual_publications.error.article_not_approved'));
                    }
                }

                if ($this->input('status') === ManualPublication::STATUS_READY && (int) $this->input('assigned_admin_id') < 1) {
                    $validator->errors()->add('assigned_admin_id', __('admin.manual_publications.error.ready_requires_assignee'));
                }
            },
        ];
    }
}
