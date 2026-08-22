<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

final class UpdateAdminAccountPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') instanceof Admin;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /** @return list<callable(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $admin = $this->user('admin');
            if ($admin instanceof Admin
                && ! Hash::check((string) $this->input('current_password', ''), (string) $admin->password)) {
                $validator->errors()->add('current_password', __('admin.account.validation.current_password'));
            }
        }];
    }
}
