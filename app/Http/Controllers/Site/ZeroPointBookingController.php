<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Support\Lead\LeadFormFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ZeroPointBookingController extends Controller
{
    public function show(Request $request): View
    {
        $this->ensureEnabled();
        $token = (string) Str::uuid();
        $request->session()->put('zeropoint_booking_token', $token);

        return view('theme.zeropoint.booking', [
            'leadForm' => $this->form(),
            'bookingToken' => $token,
            'siteName' => '正负零',
            'tagline' => (string) config('zeropoint.tagline'),
            'pageTitle' => '预约到店 · 正负零',
            'pageDescription' => '提交到店时间意向，由工作人员人工联系确认。提交不等于预约成功。',
            'canonicalUrl' => route('site.zeropoint.booking'),
            'robotsDirective' => 'noindex,follow',
            'schemaData' => null,
            'isPlaceholder' => false,
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        $this->ensureEnabled();
        $form = $this->form();
        if (trim((string) $request->input('website', '')) !== '') {
            return redirect()->route('site.zeropoint.booking')->with('message', $form->success_message);
        }

        $expectedToken = (string) $request->session()->get('zeropoint_booking_token', '');
        $providedToken = (string) $request->input('_booking_token', '');
        if ($expectedToken === '' || $providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            return redirect()->route('site.zeropoint.booking')->withErrors('预约申请已提交或页面已过期，请刷新后重试。');
        }

        try {
            $payload = LeadFormFields::validateSubmission(
                $form,
                $request->except(['_token', '_booking_token', 'website'])
            );
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $request->session()->forget('zeropoint_booking_token');
        $reference = $this->referenceCode();

        LeadSubmission::query()->create([
            'reference_code' => $reference,
            'lead_form_id' => $form->id,
            'status' => LeadSubmission::STATUS_NEW,
            'payload' => $payload,
            'source_url' => url()->current(),
            'attribution' => $this->attribution($request),
            'ip_address' => '',
            'user_agent' => '',
        ]);

        return redirect()
            ->route('site.zeropoint.booking')
            ->with('message', '预约申请已提交，申请编号：'.$reference.'。工作人员确认前不代表预约成功。');
    }

    private function form(): LeadForm
    {
        $form = LeadForm::query()
            ->where('slug', (string) config('zeropoint.booking_form_slug'))
            ->where('status', LeadForm::STATUS_ACTIVE)
            ->first();

        if (! $form) {
            throw new NotFoundHttpException('预约入口尚未启用。');
        }

        return $form;
    }

    private function ensureEnabled(): void
    {
        abort_unless((bool) config('zeropoint.enabled', false), 404);
    }

    private function referenceCode(): string
    {
        do {
            $code = 'ZP-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (LeadSubmission::query()->where('reference_code', $code)->exists());

        return $code;
    }

    /** @return array<string, string> */
    private function attribution(Request $request): array
    {
        return collect(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'])
            ->mapWithKeys(fn (string $key): array => [$key => trim(mb_substr((string) $request->input($key, ''), 0, 120))])
            ->filter()
            ->all();
    }
}
