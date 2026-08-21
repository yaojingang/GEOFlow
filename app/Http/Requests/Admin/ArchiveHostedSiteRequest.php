<?php

namespace App\Http\Requests\Admin;

use App\Models\DistributionChannel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ArchiveHostedSiteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $channel = $this->route('hostedSite');

        return $channel instanceof DistributionChannel && $channel->isHostedSite();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hostname' => ['required', 'string', 'max:253'],
        ];
    }
}
