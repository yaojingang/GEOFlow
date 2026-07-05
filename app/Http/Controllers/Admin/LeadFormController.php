<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Support\AdminWeb;
use App\Support\Lead\LeadFormFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LeadFormController extends Controller
{
    public function index(): View
    {
        $forms = LeadForm::query()
            ->withCount('submissions')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('admin.lead-forms.index', [
            'pageTitle' => __('admin.lead_forms.page_title'),
            'activeMenu' => 'analytics',
            'adminSiteName' => AdminWeb::siteName(),
            'forms' => $forms,
            'stats' => [
                'total' => LeadForm::query()->count(),
                'active' => LeadForm::query()->where('status', LeadForm::STATUS_ACTIVE)->count(),
                'submissions' => LeadSubmission::query()->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.lead-forms.form', [
            'pageTitle' => __('admin.lead_forms.create_title'),
            'activeMenu' => 'analytics',
            'adminSiteName' => AdminWeb::siteName(),
            'leadForm' => null,
            'fields' => old('fields', LeadFormFields::defaultFields()),
            'formAction' => route('admin.lead-forms.store'),
            'method' => 'POST',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatePayload($request);
        $fields = LeadFormFields::normalizePosted($request->input('fields', []));
        if ($fields === []) {
            return back()->withErrors(['fields' => __('admin.lead_forms.error.fields_required')])->withInput();
        }

        $slug = $this->uniqueSlug((string) ($payload['slug'] ?? ''), (string) $payload['name']);

        LeadForm::query()->create([
            'name' => trim((string) $payload['name']),
            'slug' => $slug,
            'status' => (string) $payload['status'],
            'description' => trim((string) ($payload['description'] ?? '')),
            'submit_button_label' => trim((string) ($payload['submit_button_label'] ?? __('admin.lead_forms.default_submit_button'))),
            'success_message' => trim((string) ($payload['success_message'] ?? '')),
            'fields' => $fields,
        ]);

        return redirect()->route('admin.lead-forms.index')->with('message', __('admin.lead_forms.message.created'));
    }

    public function edit(int $formId): View
    {
        $leadForm = LeadForm::query()->whereKey($formId)->firstOrFail();

        return view('admin.lead-forms.form', [
            'pageTitle' => __('admin.lead_forms.edit_title'),
            'activeMenu' => 'analytics',
            'adminSiteName' => AdminWeb::siteName(),
            'leadForm' => $leadForm,
            'fields' => old('fields', $leadForm->normalizedFields()),
            'formAction' => route('admin.lead-forms.update', ['formId' => $leadForm->id]),
            'method' => 'PUT',
        ]);
    }

    public function update(int $formId, Request $request): RedirectResponse
    {
        $leadForm = LeadForm::query()->whereKey($formId)->firstOrFail();
        $payload = $this->validatePayload($request, $leadForm);
        $fields = LeadFormFields::normalizePosted($request->input('fields', []));
        if ($fields === []) {
            return back()->withErrors(['fields' => __('admin.lead_forms.error.fields_required')])->withInput();
        }

        $leadForm->update([
            'name' => trim((string) $payload['name']),
            'slug' => $this->uniqueSlug((string) ($payload['slug'] ?? ''), (string) $payload['name'], $leadForm),
            'status' => (string) $payload['status'],
            'description' => trim((string) ($payload['description'] ?? '')),
            'submit_button_label' => trim((string) ($payload['submit_button_label'] ?? __('admin.lead_forms.default_submit_button'))),
            'success_message' => trim((string) ($payload['success_message'] ?? '')),
            'fields' => $fields,
        ]);

        return redirect()->route('admin.lead-forms.index')->with('message', __('admin.lead_forms.message.updated'));
    }

    public function toggleStatus(int $formId): RedirectResponse
    {
        $leadForm = LeadForm::query()->whereKey($formId)->firstOrFail();
        $leadForm->update([
            'status' => $leadForm->isActive() ? LeadForm::STATUS_INACTIVE : LeadForm::STATUS_ACTIVE,
        ]);

        return back()->with('message', __('admin.lead_forms.message.status_updated'));
    }

    public function destroy(int $formId): RedirectResponse
    {
        $leadForm = LeadForm::query()->withCount('submissions')->whereKey($formId)->firstOrFail();
        if ((int) $leadForm->submissions_count > 0) {
            return back()->withErrors(__('admin.lead_forms.error.delete_with_submissions'));
        }

        $leadForm->delete();

        return redirect()->route('admin.lead-forms.index')->with('message', __('admin.lead_forms.message.deleted'));
    }

    /**
     * @return array{name:string,slug?:string,status:string,description?:string,submit_button_label?:string,success_message?:string}
     */
    private function validatePayload(Request $request, ?LeadForm $leadForm = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'nullable',
                'string',
                'max:120',
                'regex:/^[a-z0-9][a-z0-9_-]*[a-z0-9]$/',
                Rule::unique('lead_forms', 'slug')->ignore($leadForm?->id),
            ],
            'status' => ['required', Rule::in([LeadForm::STATUS_ACTIVE, LeadForm::STATUS_INACTIVE])],
            'description' => ['nullable', 'string', 'max:2000'],
            'submit_button_label' => ['nullable', 'string', 'max:80'],
            'success_message' => ['nullable', 'string', 'max:2000'],
        ], [
            'name.required' => __('admin.lead_forms.error.name_required'),
            'slug.regex' => __('admin.lead_forms.error.slug_invalid'),
            'slug.unique' => __('admin.lead_forms.error.slug_exists'),
        ]);
    }

    private function uniqueSlug(string $slug, string $name, ?LeadForm $leadForm = null): string
    {
        $base = trim($slug) !== '' ? trim($slug) : Str::slug($name);
        $base = Str::lower($base !== '' ? $base : Str::random(8));
        $candidate = $base;
        $index = 2;

        while (LeadForm::query()
            ->where('slug', $candidate)
            ->when($leadForm, fn ($query) => $query->whereKeyNot($leadForm->id))
            ->exists()) {
            $candidate = $base.'-'.$index;
            $index++;
        }

        return $candidate;
    }
}
