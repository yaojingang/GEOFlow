<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\CrmCustomer;
use App\Models\CrmInquiry;
use App\Models\CrmOpportunity;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CollectionOptions;
use App\Support\GeoFlow\CrmOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CrmOpportunityController extends Controller
{
    public const STAGES = [
        'qualified' => '已确认',
        'discovery' => '需求梳理',
        'solution' => '方案制定',
        'proposal' => '报价方案',
        'negotiation' => '商务谈判',
        'won' => '赢单',
        'lost' => '输单',
    ];

    public function index(Request $request): View
    {
        $collectionId = (int) $request->query('collection_id', 0);
        $query = CrmOpportunity::query()
            ->with(['customer', 'owner', 'primaryContact', 'sourceInquiry'])
            ->latest('updated_at');

        if ($collectionId > 0) {
            $query->where('collection_id', $collectionId);
        }

        return view('admin.crm.opportunities.index', [
            'pageTitle' => '商机管道',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'opportunities' => $query->get()->groupBy('stage'),
            'stages' => self::STAGES,
            'collectionId' => $collectionId,
            'collectionOptions' => CollectionOptions::all(),
        ]);
    }

    public function create(Request $request): View
    {
        $inquiryId = (int) $request->query('inquiry_id', 0);
        $inquiry = $inquiryId > 0 ? $this->inquiryForOpportunity($inquiryId) : null;

        return view('admin.crm.opportunities.form', $this->formData(null, $inquiry));
    }

    public function store(Request $request): RedirectResponse
    {
        $opportunity = CrmOpportunity::query()->create($this->validated($request));
        $this->markSourceInquiryConverted($opportunity);

        return redirect()
            ->route('admin.crm.opportunities.edit', ['opportunityId' => (int) $opportunity->id])
            ->with('message', '商机已创建');
    }

    public function storeFromInquiry(int $inquiryId): RedirectResponse
    {
        $inquiry = $this->inquiryForOpportunity($inquiryId);
        $existing = $inquiry->opportunities()->oldest('id')->first();
        if ($existing) {
            return redirect()
                ->route('admin.crm.opportunities.edit', ['opportunityId' => (int) $existing->id])
                ->with('message', '该询盘已存在关联商机');
        }

        if ((int) ($inquiry->customer_id ?? 0) <= 0) {
            return redirect()
                ->route('admin.crm.inquiries.edit', ['inquiryId' => (int) $inquiry->id])
                ->withErrors(['customer_id' => '转为商机前，请先为询盘关联客户。']);
        }

        $opportunity = CrmOpportunity::query()->create($this->payloadFromInquiry($inquiry));
        $this->markSourceInquiryConverted($opportunity);

        return redirect()
            ->route('admin.crm.opportunities.edit', ['opportunityId' => (int) $opportunity->id])
            ->with('message', '已从询盘创建商机');
    }

    public function edit(int $opportunityId): View
    {
        $opportunity = CrmOpportunity::query()
            ->with([
                'customer.contacts',
                'sourceInquiry.customer',
                'sourceInquiry.entities',
                'sourceInquiry.knowledgeBases',
                'sourceInquiry.cases',
                'tasks.assignee',
                'quotes.inquiry',
            ])
            ->findOrFail($opportunityId);

        return view('admin.crm.opportunities.form', $this->formData($opportunity, null));
    }

    public function update(Request $request, int $opportunityId): RedirectResponse
    {
        $opportunity = CrmOpportunity::query()->findOrFail($opportunityId);
        $data = $this->validated($request);
        $data['won_at'] = $data['stage'] === 'won' ? ($opportunity->won_at ?: now()) : null;
        $data['lost_at'] = $data['stage'] === 'lost' ? ($opportunity->lost_at ?: now()) : null;
        $opportunity->update($data);
        $this->markSourceInquiryConverted($opportunity);

        return back()->with('message', '商机已更新');
    }

    public function destroy(int $opportunityId): RedirectResponse
    {
        CrmOpportunity::query()->findOrFail($opportunityId)->delete();

        return redirect()
            ->route('admin.crm.opportunities.index')
            ->with('message', '商机已归档');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'collection_id' => ['nullable', 'integer', 'exists:collections,id'],
            'customer_id' => ['required', 'integer', 'exists:crm_customers,id'],
            'primary_contact_id' => [
                'nullable',
                'integer',
                Rule::exists('crm_customer_contacts', 'id')->where('customer_id', (int) $request->input('customer_id')),
            ],
            'source_inquiry_id' => ['nullable', 'integer', 'exists:crm_inquiries,id'],
            'owner_admin_id' => ['nullable', 'integer', 'exists:admins,id'],
            'name' => ['required', 'string', 'max:200'],
            'stage' => ['required', Rule::in(array_keys(self::STAGES))],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'probability' => ['nullable', 'integer', 'between:0,100'],
            'expected_close_date' => ['nullable', 'date'],
            'next_step' => ['nullable', 'string', 'max:500'],
            'next_step_at' => ['nullable', 'date'],
            'competitor' => ['nullable', 'string', 'max:200'],
            'lost_reason' => [
                Rule::requiredIf(fn () => $request->input('stage') === 'lost'),
                'nullable',
                'string',
                'max:5000',
            ],
            'notes' => ['nullable', 'string', 'max:20000'],
        ]);

        foreach (['collection_id', 'primary_contact_id', 'source_inquiry_id', 'owner_admin_id'] as $key) {
            $data[$key] = (int) ($data[$key] ?? 0) ?: null;
        }
        $data['currency'] = strtoupper(trim((string) ($data['currency'] ?? 'USD'))) ?: 'USD';
        $data['probability'] = (int) ($data['probability'] ?? 20);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(?CrmOpportunity $opportunity, ?CrmInquiry $inquiry): array
    {
        $sourceInquiry = $opportunity?->sourceInquiry ?: $inquiry;
        $customerId = (int) ($opportunity?->customer_id ?: $sourceInquiry?->customer_id ?: 0);

        return [
            'pageTitle' => $opportunity ? '编辑商机' : '新增商机',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'opportunity' => $opportunity,
            'inquiry' => $inquiry,
            'sourceInquiry' => $sourceInquiry,
            'stages' => self::STAGES,
            'customers' => CrmCustomer::query()->with('contacts')->orderBy('company_name')->get(),
            'selectedCustomerId' => $customerId,
            'collectionOptions' => CollectionOptions::all(true),
            'employeeOptions' => CrmOptions::employeeOptionsById(),
        ];
    }

    private function inquiryForOpportunity(int $inquiryId): CrmInquiry
    {
        return CrmInquiry::query()
            ->with(['customer.contacts', 'entities', 'knowledgeBases', 'cases', 'opportunities'])
            ->findOrFail($inquiryId);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFromInquiry(CrmInquiry $inquiry): array
    {
        return [
            'collection_id' => (int) ($inquiry->collection_id ?? 0) ?: null,
            'customer_id' => (int) $inquiry->customer_id,
            'primary_contact_id' => $this->primaryContactId($inquiry),
            'source_inquiry_id' => (int) $inquiry->id,
            'owner_admin_id' => $this->ownerAdminIdFromInquiry($inquiry),
            'name' => (string) $inquiry->subject,
            'stage' => 'qualified',
            'amount' => 0,
            'currency' => 'USD',
            'probability' => 20,
            'next_step' => $this->firstNonEmpty([
                (string) ($inquiry->missing_information_questions ?? ''),
                (string) ($inquiry->suggested_reply_points ?? ''),
                '确认需求、预算、交期和目标配置。',
            ]),
            'notes' => $this->opportunityNotesFromInquiry($inquiry),
        ];
    }

    private function markSourceInquiryConverted(CrmOpportunity $opportunity): void
    {
        if ((int) ($opportunity->source_inquiry_id ?? 0) <= 0) {
            return;
        }

        CrmInquiry::query()
            ->whereKey((int) $opportunity->source_inquiry_id)
            ->whereNotIn('status', ['quoted', 'won', 'lost', 'closed'])
            ->update(['status' => 'converted']);
    }

    private function ownerAdminIdFromInquiry(CrmInquiry $inquiry): ?int
    {
        $assignedTo = trim((string) ($inquiry->assigned_to ?? ''));
        if ($assignedTo === '') {
            return null;
        }

        $id = Admin::query()
            ->where('display_name', $assignedTo)
            ->orWhere('username', $assignedTo)
            ->orWhere('email', $assignedTo)
            ->value('id');

        return $id ? (int) $id : null;
    }

    private function primaryContactId(CrmInquiry $inquiry): ?int
    {
        $contacts = $inquiry->customer?->contacts;
        if (! $contacts || $contacts->isEmpty()) {
            return null;
        }

        return (int) ($contacts->firstWhere('is_primary', true)?->id ?: $contacts->first()?->id) ?: null;
    }

    /**
     * @param  list<string>  $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $text = trim($value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function opportunityNotesFromInquiry(CrmInquiry $inquiry): string
    {
        $parts = [];
        foreach ([
            '需求摘要' => $inquiry->customer_need_summary,
            '产品兴趣' => $inquiry->product_interest,
            '建议回复要点' => $inquiry->suggested_reply_points,
        ] as $label => $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                $parts[] = $label.":\n".$text;
            }
        }

        return implode("\n\n", $parts);
    }
}
