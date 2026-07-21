<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\DistributionChannel;
use App\Services\GeoFlow\DistributionChannelDeletionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

class DeleteDistributionChannelRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof Admin && $admin->isSuperAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'confirmation_name' => ['required', 'string', 'max:120'],
            'current_password' => ['required', 'string'],
            'impact_fingerprint' => ['required', 'string', 'size:64'],
            'ack_remote_content' => ['nullable', 'boolean'],
            'ack_task_changes' => ['nullable', 'boolean'],
            'ack_credentials' => ['nullable', 'boolean'],
            'ack_history' => ['nullable', 'boolean'],
            'force_stale_sending' => ['nullable', 'boolean'],
            'force_stale_operations' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator):void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $channel = $this->channel();
            if (! $channel) {
                return;
            }

            $deletionService = app(DistributionChannelDeletionService::class);
            if (! $deletionService->isSchemaReady()) {
                $validator->errors()->add('distribution', __('admin.distribution.delete.blocked.migration_required'));

                return;
            }

            if (! hash_equals((string) $channel->name, (string) $this->input('confirmation_name', ''))) {
                $validator->errors()->add('confirmation_name', __('admin.distribution.delete.validation.name_mismatch'));
            }

            $admin = Auth::guard('admin')->user();
            if (! $admin instanceof Admin || ! Hash::check((string) $this->input('current_password', ''), (string) $admin->password)) {
                $validator->errors()->add('current_password', __('admin.distribution.delete.validation.password_invalid'));
            }

            if ((string) $channel->status !== DistributionChannel::STATUS_DELETING) {
                $validator->errors()->add('channel', __('admin.distribution.delete.validation.prepare_required'));
            }

            $impact = $deletionService->inspect($channel);
            if (! hash_equals((string) $impact['impact_fingerprint'], (string) $this->input('impact_fingerprint', ''))) {
                $validator->errors()->add('channel', __('admin.distribution.delete.validation.impact_changed'));
            }
            if ($impact['remote_content_count'] > 0 && ! $this->boolean('ack_remote_content')) {
                $validator->errors()->add('ack_remote_content', __('admin.distribution.delete.validation.remote_content_ack'));
            }
            if ($impact['linked_task_count'] > 0 && ! $this->boolean('ack_task_changes')) {
                $validator->errors()->add('ack_task_changes', __('admin.distribution.delete.validation.task_changes_ack'));
            }
            if ($impact['secret_count'] > 0 && ! $this->boolean('ack_credentials')) {
                $validator->errors()->add('ack_credentials', __('admin.distribution.delete.validation.credentials_ack'));
            }
            if (! $this->boolean('ack_history')) {
                $validator->errors()->add('ack_history', __('admin.distribution.delete.validation.history_ack'));
            }
            if ($impact['fresh_sending_count'] > 0) {
                $validator->errors()->add('channel', __('admin.distribution.delete.validation.sending_in_progress'));
            }
            if ($impact['stale_sending_count'] > 0 && ! $this->boolean('force_stale_sending')) {
                $validator->errors()->add('force_stale_sending', __('admin.distribution.delete.validation.stale_sending_ack'));
            }
            if ($impact['fresh_operation_count'] > 0) {
                $validator->errors()->add('channel', __('admin.distribution.delete.validation.operation_in_progress'));
            }
            if ($impact['stale_operation_count'] > 0 && ! $this->boolean('force_stale_operations')) {
                $validator->errors()->add('force_stale_operations', __('admin.distribution.delete.validation.stale_operation_ack'));
            }
        }];
    }

    public function channel(): ?DistributionChannel
    {
        return DistributionChannel::query()
            ->whereKey((int) $this->route('channelId'))
            ->first();
    }
}
