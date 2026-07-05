<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Support\Lead\LeadFormFields;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LeadFormController extends Controller
{
    public function show(string $slug): View
    {
        $leadForm = $this->activeForm($slug);
        $map = SiteSettingsBag::all();
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteDescription = (string) ($map['site_description'] ?? config('geoflow.site_description', ''));
        $pageTitle = $leadForm->name.' - '.$siteTitle;

        return view('site.lead-forms.show', [
            'leadForm' => $leadForm,
            'siteTitle' => $siteTitle,
            'siteDescription' => $siteDescription,
            'pageTitle' => $pageTitle,
            'pageDescription' => trim((string) $leadForm->description) !== '' ? (string) $leadForm->description : $siteDescription,
            'pageKeywords' => (string) ($map['site_keywords'] ?? ''),
            'pageOgType' => 'website',
            'canonicalUrl' => route('site.lead-forms.show', ['slug' => $leadForm->slug]),
        ]);
    }

    public function submit(string $slug, Request $request): RedirectResponse
    {
        $leadForm = $this->activeForm($slug);
        $redirectUrl = $this->redirectTarget($request, $leadForm);

        if (trim((string) $request->input('website', '')) !== '') {
            return redirect()->to($redirectUrl)->with('message', (string) ($leadForm->success_message ?: __('site.lead_forms.success')));
        }

        try {
            $payload = LeadFormFields::validateSubmission($leadForm, $request->except(['_token', 'website', 'source_url']));
        } catch (ValidationException $exception) {
            return redirect()
                ->to($redirectUrl)
                ->withErrors($exception->validator)
                ->withInput($request->except(['website']));
        }

        LeadSubmission::query()->create([
            'lead_form_id' => $leadForm->id,
            'status' => LeadSubmission::STATUS_NEW,
            'payload' => $payload,
            'source_url' => mb_substr((string) ($request->input('source_url') ?: $request->headers->get('referer') ?: url()->current()), 0, 500),
            'ip_address' => (string) ($request->ip() ?? ''),
            'user_agent' => $request->userAgent(),
        ]);

        return redirect()->to($redirectUrl)->with('message', (string) ($leadForm->success_message ?: __('site.lead_forms.success')));
    }

    private function redirectTarget(Request $request, LeadForm $leadForm): string
    {
        $fallback = route('site.lead-forms.show', ['slug' => $leadForm->slug]);
        $referer = trim((string) $request->headers->get('referer', ''));
        if ($referer === '') {
            return $fallback;
        }

        $parts = parse_url($referer);
        if (! is_array($parts)) {
            return $fallback;
        }

        $host = $parts['host'] ?? null;
        if (is_string($host) && strcasecmp($host, $request->getHost()) === 0) {
            return $referer;
        }

        if ($host === null && str_starts_with($referer, '/') && ! str_starts_with($referer, '//')) {
            return $referer;
        }

        return $fallback;
    }

    private function activeForm(string $slug): LeadForm
    {
        $leadForm = LeadForm::query()
            ->where('slug', $slug)
            ->where('status', LeadForm::STATUS_ACTIVE)
            ->first();

        if (! $leadForm instanceof LeadForm) {
            throw new NotFoundHttpException(__('site.lead_forms.not_found'));
        }

        return $leadForm;
    }
}
