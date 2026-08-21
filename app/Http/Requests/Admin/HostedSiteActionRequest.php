<?php

namespace App\Http\Requests\Admin;

use App\Models\DistributionChannel;
use Illuminate\Foundation\Http\FormRequest;

class HostedSiteActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $channel = $this->route('hostedSite');

        return $channel instanceof DistributionChannel && $channel->isHostedSite();
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [];
    }
}
