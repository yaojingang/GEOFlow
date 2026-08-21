<?php

namespace App\Http\Requests\Admin;

use App\Models\LeadForm;
use App\Services\Site\HostedSiteResolver;
use App\Support\AdminWeb;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreHostedSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,array<int,string>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'hostname' => ['required', 'string', 'max:253', 'unique:hosted_site_profiles,hostname'],
            'topic' => ['required', 'string', 'max:160'],
            'locale' => ['required', 'string', Rule::in(array_keys(AdminWeb::supportedLocales()))],
            'timezone' => ['required', 'timezone:all'],
            'daily_publish_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'publish_weight' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'min_publish_interval_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
            'min_articles_before_index' => ['required', 'integer', 'min:1', 'max:50000'],
            'template_key' => [
                'required', 'string', 'regex:/^[a-zA-Z0-9_-]+$/', 'max:120',
                Rule::in(app(SiteThemeCatalog::class)->hostedCompatibleIds()),
            ],
            'site_description' => ['nullable', 'string', 'max:1000'],
            'site_keywords' => ['nullable', 'string', 'max:500'],
            'about_title' => ['nullable', 'string', 'max:160'],
            'about_content' => ['nullable', 'string', 'max:20000'],
            'lead_form_slugs' => ['nullable', 'array', 'max:20'],
            'lead_form_slugs.*' => [
                'string', 'max:120', 'distinct',
                Rule::exists('lead_forms', 'slug')->where('status', LeadForm::STATUS_ACTIVE),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'hostname' => strtolower(rtrim(trim((string) $this->input('hostname')), '.')),
            'lead_form_slugs' => collect((array) $this->input('lead_form_slugs', []))
                ->map(static fn (mixed $slug): string => trim((string) $slug))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    /** @return array<int,callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! app(HostedSiteResolver::class)->isSingleLabelHostedHostname((string) $this->input('hostname'))) {
                $validator->errors()->add('hostname', '域名必须是已配置根域下的单层、非保留二级域名。');
            }
        }];
    }
}
