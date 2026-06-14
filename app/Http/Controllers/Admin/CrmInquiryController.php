<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CaseRecord;
use App\Models\CrmCustomer;
use App\Models\CrmFollowUp;
use App\Models\CrmInquiry;
use App\Models\EntityRecord;
use App\Models\KnowledgeBase;
use App\Services\GeoFlow\CrmInquiryAnalysisService;
use App\Services\GeoFlow\TagService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CollectionOptions;
use App\Support\GeoFlow\CrmOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CrmInquiryController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly CrmInquiryAnalysisService $analysisService,
        private readonly TagService $tagService,
    ) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $priority = trim((string) $request->query('priority', ''));
        $collectionId = $this->selectedCollectionId($request);

        $query = CrmInquiry::query()
            ->with(['collection', 'customer'])
            ->withCount(['entities', 'knowledgeBases', 'cases', 'quotes', 'opportunities'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('subject', 'like', '%'.$search.'%')
                    ->orWhere('raw_message', 'like', '%'.$search.'%')
                    ->orWhere('customer_need_summary', 'like', '%'.$search.'%')
                    ->orWhereHas('customer', static fn ($customerQuery) => $customerQuery->where('company_name', 'like', '%'.$search.'%'));
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($priority !== '') {
            $query->where('priority', $priority);
        }

        if ($collectionId !== null) {
            $query->where('collection_id', $collectionId);
        }

        return view('admin.crm.inquiries.index', [
            'pageTitle' => '询盘管理',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'inquiries' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'search' => $search,
            'status' => $status,
            'priority' => $priority,
            'collectionId' => $collectionId,
            'collectionOptions' => CollectionOptions::all(),
            'stats' => [
                'total' => CrmInquiry::query()->count(),
                'new' => CrmInquiry::query()->where('status', 'new')->count(),
                'high' => CrmInquiry::query()->where('priority', 'high')->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $customerId = (int) $request->query('customer_id', 0);
        $customer = $customerId > 0 ? CrmCustomer::query()->whereKey($customerId)->first() : null;
        $collectionId = (int) ($request->query('collection_id', 0) ?: ($customer?->collection_id ?? 0));

        return view('admin.crm.inquiries.form', $this->formData([
            'pageTitle' => '新增询盘',
            'isEdit' => false,
            'inquiryId' => 0,
            'inquiryForm' => array_replace($this->emptyInquiryForm(), [
                'customer_id' => $customer ? (string) $customer->id : '',
                'collection_id' => $collectionId > 0 ? (string) $collectionId : '',
            ]),
            'selectedEntityIds' => [],
            'selectedKnowledgeBaseIds' => [],
            'selectedCaseRecordIds' => [],
            'selectedTagIds' => [],
            'tagOptions' => [],
        ], $collectionId > 0 ? $collectionId : null));
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateInquiry($request);
        $inquiry = CrmInquiry::query()->create($this->normalizeInquiryPayload($payload));
        $this->syncRelations($inquiry, $payload);

        return redirect()
            ->route('admin.crm.inquiries.show', ['inquiryId' => (int) $inquiry->id])
            ->with('message', '询盘已创建');
    }

    public function show(int $inquiryId): View
    {
        $inquiry = CrmInquiry::query()
            ->with(['collection', 'customer.contacts', 'followUps.inquiry', 'crmTasks.assignee', 'opportunities', 'entities.collection', 'knowledgeBases.collection', 'cases.collection', 'tags', 'quotes', 'salesOrders'])
            ->whereKey($inquiryId)
            ->firstOrFail();

        return view('admin.crm.inquiries.show', [
            'pageTitle' => '询盘详情',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'inquiry' => $inquiry,
        ]);
    }

    public function edit(int $inquiryId): View
    {
        $inquiry = CrmInquiry::query()->with(['entities', 'knowledgeBases', 'cases', 'tags'])->whereKey($inquiryId)->firstOrFail();
        $selectedTagIds = $this->tagService->selectedTagIdsFor($inquiry);

        return view('admin.crm.inquiries.form', $this->formData([
            'pageTitle' => '编辑询盘',
            'isEdit' => true,
            'inquiryId' => (int) $inquiry->id,
            'inquiryForm' => [
                'collection_id' => (string) ((int) ($inquiry->collection_id ?? 0) ?: ''),
                'customer_id' => (string) ((int) ($inquiry->customer_id ?? 0) ?: ''),
                'source_channel' => (string) ($inquiry->source_channel ?? ''),
                'source_url' => (string) ($inquiry->source_url ?? ''),
                'subject' => (string) $inquiry->subject,
                'raw_message' => (string) ($inquiry->raw_message ?? ''),
                'detected_language' => (string) ($inquiry->detected_language ?? ''),
                'status' => (string) ($inquiry->status ?? 'new'),
                'priority' => (string) ($inquiry->priority ?? 'normal'),
                'assigned_to' => (string) ($inquiry->assigned_to ?? ''),
                'customer_need_summary' => (string) ($inquiry->customer_need_summary ?? ''),
                'product_interest' => (string) ($inquiry->product_interest ?? ''),
                'suggested_reply_points' => (string) ($inquiry->suggested_reply_points ?? ''),
                'missing_information_questions' => (string) ($inquiry->missing_information_questions ?? ''),
                'urgency_level' => (string) ($inquiry->urgency_level ?? ''),
                'notes' => (string) ($inquiry->notes ?? ''),
            ],
            'selectedEntityIds' => $inquiry->entities->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'selectedKnowledgeBaseIds' => $inquiry->knowledgeBases->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'selectedCaseRecordIds' => $inquiry->cases->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'selectedTagIds' => $selectedTagIds,
            'tagOptions' => $this->tagService->tagOptionsForIds($selectedTagIds),
        ], (int) ($inquiry->collection_id ?? 0) ?: null));
    }

    public function update(Request $request, int $inquiryId): RedirectResponse
    {
        $inquiry = CrmInquiry::query()->whereKey($inquiryId)->firstOrFail();
        $payload = $this->validateInquiry($request);
        $inquiry->update($this->normalizeInquiryPayload($payload));
        $this->syncRelations($inquiry, $payload);

        return redirect()
            ->route('admin.crm.inquiries.edit', ['inquiryId' => (int) $inquiry->id])
            ->with('message', '询盘已更新');
    }

    public function destroy(int $inquiryId): RedirectResponse
    {
        CrmInquiry::query()->whereKey($inquiryId)->firstOrFail()->delete();

        return redirect()
            ->route('admin.crm.inquiries.index')
            ->with('message', '询盘已归档，关联商业记录已保留');
    }

    public function analyze(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'content' => ['required', 'string', 'max:50000'],
            'collection_id' => ['nullable', 'integer', 'min:1'],
            'ai_model_id' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json([
            'fields' => $this->analysisService->analyze(
                (string) $payload['content'],
                isset($payload['collection_id']) && (int) $payload['collection_id'] > 0 ? (int) $payload['collection_id'] : null,
                (int) ($payload['ai_model_id'] ?? 0),
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function formData(array $base, ?int $collectionId): array
    {
        return $base + [
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'collectionOptions' => CollectionOptions::all(true),
            'customerOptions' => $this->customerOptions($collectionId),
            'entityOptions' => $this->entityOptions($collectionId),
            'knowledgeBaseOptions' => $this->knowledgeBaseOptions($collectionId),
            'caseOptions' => $this->caseOptions($collectionId),
            'sourceChannelOptions' => CrmOptions::sourceChannels(),
            'employeeOptions' => CrmOptions::employeeOptions(),
            'aiModelOptions' => $this->analysisService->modelOptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateInquiry(Request $request): array
    {
        return $request->validate([
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'customer_id' => ['nullable', 'integer', 'min:1', Rule::exists('crm_customers', 'id')],
            'source_channel' => ['nullable', 'string', 'max:120'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'subject' => ['required', 'string', 'max:200'],
            'raw_message' => ['nullable', 'string', 'max:50000'],
            'detected_language' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'string', Rule::in(['new', 'analyzing', 'qualified', 'converted', 'invalid', 'closed', 'quoted', 'won', 'lost'])],
            'priority' => ['nullable', 'string', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assigned_to' => ['nullable', 'string', 'max:120'],
            'customer_need_summary' => ['nullable', 'string', 'max:20000'],
            'product_interest' => ['nullable', 'string', 'max:10000'],
            'suggested_reply_points' => ['nullable', 'string', 'max:20000'],
            'missing_information_questions' => ['nullable', 'string', 'max:20000'],
            'urgency_level' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer', Rule::exists('entities', 'id')],
            'knowledge_base_ids' => ['nullable', 'array'],
            'knowledge_base_ids.*' => ['integer', Rule::exists('knowledge_bases', 'id')],
            'case_record_ids' => ['nullable', 'array'],
            'case_record_ids.*' => ['integer', Rule::exists('case_records', 'id')],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where(static fn ($query) => $query->where('type', 'material'))],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeInquiryPayload(array $payload): array
    {
        return [
            'collection_id' => $this->normalizeNullableId($payload['collection_id'] ?? null),
            'customer_id' => $this->normalizeNullableId($payload['customer_id'] ?? null),
            'source_channel' => trim((string) ($payload['source_channel'] ?? '')),
            'source_url' => trim((string) ($payload['source_url'] ?? '')),
            'subject' => trim((string) $payload['subject']),
            'raw_message' => trim((string) ($payload['raw_message'] ?? '')),
            'detected_language' => trim((string) ($payload['detected_language'] ?? '')),
            'status' => (string) ($payload['status'] ?? 'new'),
            'priority' => (string) ($payload['priority'] ?? 'normal'),
            'assigned_to' => trim((string) ($payload['assigned_to'] ?? '')),
            'customer_need_summary' => trim((string) ($payload['customer_need_summary'] ?? '')),
            'product_interest' => trim((string) ($payload['product_interest'] ?? '')),
            'suggested_reply_points' => trim((string) ($payload['suggested_reply_points'] ?? '')),
            'missing_information_questions' => trim((string) ($payload['missing_information_questions'] ?? '')),
            'urgency_level' => trim((string) ($payload['urgency_level'] ?? '')),
            'notes' => trim((string) ($payload['notes'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncRelations(CrmInquiry $inquiry, array $payload): void
    {
        $collectionId = (int) ($inquiry->collection_id ?? 0) ?: null;
        $inquiry->entities()->sync($this->validIds(EntityRecord::class, $payload['entity_ids'] ?? [], $collectionId));
        $inquiry->knowledgeBases()->sync($this->validIds(KnowledgeBase::class, $payload['knowledge_base_ids'] ?? [], $collectionId));
        $inquiry->cases()->sync($this->validIds(CaseRecord::class, $payload['case_record_ids'] ?? [], $collectionId));
        $this->tagService->syncExisting($inquiry, $this->selectedIds($payload['tag_ids'] ?? []));
    }

    /**
     * @param  class-string<EntityRecord|KnowledgeBase|CaseRecord>  $modelClass
     * @return list<int>
     */
    private function validIds(string $modelClass, mixed $ids, ?int $collectionId): array
    {
        $idList = $this->selectedIds($ids);
        if ($idList === []) {
            return [];
        }

        return $modelClass::query()
            ->when($collectionId !== null && $collectionId > 0, static fn ($query) => $query->where('collection_id', $collectionId))
            ->whereIn('id', $idList)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function selectedIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }

    private function normalizeNullableId(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function selectedCollectionId(Request $request): ?int
    {
        if (!$request->has('collection_id')) {
            return \App\Support\AdminWeb::defaultCollectionId();
        }
        return $this->normalizeNullableId($request->query('collection_id', 0));
    }

    /**
     * @return array<string, string>
     */
    private function emptyInquiryForm(): array
    {
        return [
            'collection_id' => '',
            'customer_id' => '',
            'source_channel' => '',
            'source_url' => '',
            'subject' => '',
            'raw_message' => '',
            'detected_language' => '',
            'status' => 'new',
            'priority' => 'normal',
            'assigned_to' => '',
            'customer_need_summary' => '',
            'product_interest' => '',
            'suggested_reply_points' => '',
            'missing_information_questions' => '',
            'urgency_level' => '',
            'notes' => '',
        ];
    }

    /**
     * @return list<array{id:int,label:string,meta:string,collection_id:int}>
     */
    private function customerOptions(?int $collectionId): array
    {
        return CrmCustomer::query()
            ->with('collection')
            ->when($collectionId !== null && $collectionId > 0, static fn ($query) => $query->where('collection_id', $collectionId))
            ->orderBy('company_name')
            ->limit(300)
            ->get()
            ->map(static fn (CrmCustomer $customer): array => [
                'id' => (int) $customer->id,
                'label' => trim((string) ($customer->contact_person ?? '')) !== '' ? (string) $customer->contact_person : (string) $customer->company_name,
                'meta' => (string) ($customer->collection?->name ?? ''),
                'collection_id' => (int) ($customer->collection_id ?? 0),
            ])
            ->all();
    }

    /**
     * @return list<array{id:int,label:string,meta:string,collection_id:int}>
     */
    private function entityOptions(?int $collectionId): array
    {
        return EntityRecord::query()
            ->with('collection')
            ->when($collectionId !== null && $collectionId > 0, static fn ($query) => $query->where('collection_id', $collectionId))
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(static fn (EntityRecord $entity): array => [
                'id' => (int) $entity->id,
                'label' => (string) $entity->name,
                'meta' => trim((string) ($entity->entity_type ?? '').' '.(string) ($entity->collection?->name ?? '')),
                'collection_id' => (int) ($entity->collection_id ?? 0),
            ])
            ->all();
    }

    /**
     * @return list<array{id:int,label:string,meta:string,collection_id:int}>
     */
    private function knowledgeBaseOptions(?int $collectionId): array
    {
        return KnowledgeBase::query()
            ->with('collection')
            ->when($collectionId !== null && $collectionId > 0, static fn ($query) => $query->where('collection_id', $collectionId))
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(static fn (KnowledgeBase $knowledgeBase): array => [
                'id' => (int) $knowledgeBase->id,
                'label' => (string) $knowledgeBase->name,
                'meta' => trim((string) ($knowledgeBase->knowledge_type ?? '').' '.(string) ($knowledgeBase->collection?->name ?? '')),
                'collection_id' => (int) ($knowledgeBase->collection_id ?? 0),
            ])
            ->all();
    }

    /**
     * @return list<array{id:int,label:string,meta:string,collection_id:int}>
     */
    private function caseOptions(?int $collectionId): array
    {
        return CaseRecord::query()
            ->with('collection')
            ->when($collectionId !== null && $collectionId > 0, static fn ($query) => $query->where('collection_id', $collectionId))
            ->orderBy('title')
            ->limit(500)
            ->get()
            ->map(static fn (CaseRecord $caseRecord): array => [
                'id' => (int) $caseRecord->id,
                'label' => (string) $caseRecord->title,
                'meta' => trim((string) ($caseRecord->case_type ?? '').' '.(string) ($caseRecord->collection?->name ?? '')),
                'collection_id' => (int) ($caseRecord->collection_id ?? 0),
            ])
            ->all();
    }

    public function storeFollowUp(Request $request, int $inquiryId): RedirectResponse
    {
        $inquiry = CrmInquiry::query()->whereKey($inquiryId)->firstOrFail();
        $payload = $request->validate([
            'followup_type' => ['nullable', 'string', 'max:80'],
            'content' => ['required', 'string', 'max:10000'],
            'next_action' => ['nullable', 'string', 'max:5000'],
            'next_followup_at' => ['nullable', 'date'],
            'owner' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(['open', 'done', 'paused'])],
        ]);
        CrmFollowUp::query()->create([
            'customer_id' => (int) $inquiry->customer_id,
            'inquiry_id' => (int) $inquiry->id,
            'followup_type' => trim((string) ($payload['followup_type'] ?? '')),
            'content' => trim((string) $payload['content']),
            'next_action' => trim((string) ($payload['next_action'] ?? '')),
            'next_followup_at' => $payload['next_followup_at'] ?? null,
            'owner' => trim((string) ($payload['owner'] ?? $inquiry->owner ?? '')),
            'status' => (string) ($payload['status'] ?? 'open'),
        ]);
        return back()->with('message', '跟进记录已添加');
    }

    public function destroyFollowUp(int $followUpId): RedirectResponse
    {
        CrmFollowUp::query()->whereKey($followUpId)->firstOrFail()->delete();
        return back()->with('message', '跟进记录已删除');
    }
}
