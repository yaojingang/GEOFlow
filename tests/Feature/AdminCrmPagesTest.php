<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\CaseRecord;
use App\Models\CollectionRecord;
use App\Models\CrmAfterSalesTicket;
use App\Models\CrmContentProposal;
use App\Models\CrmCustomer;
use App\Models\CrmInquiry;
use App\Models\CrmCustomerContact;
use App\Models\CrmOpportunity;
use App\Models\CrmQuote;
use App\Models\CrmSalesOrder;
use App\Models\CrmSellerProfile;
use App\Models\CrmTask;
use App\Models\EntityRecord;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCrmPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_guest_is_redirected_from_crm_pages(): void
    {
        $this->get(route('admin.crm.customers.index'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.crm.inquiries.index'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.crm.quotes.index'))->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_create_customer_and_follow_up(): void
    {
        $admin = $this->admin();
        $collection = $this->collection('Automation CRM');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.customers.index'))
            ->assertOk()
            ->assertSee('CRM 客户管理')
            ->assertSee(__('admin.nav.crm'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.customers.store'), [
                'collection_id' => (int) $collection->id,
                'company_name' => 'Acme Automation Ltd',
                'contact_person' => 'John Smith', 
                'customer_type' => 'Integrator',
                'country' => 'US',
                'region' => 'CA',
                'website' => 'https://acme.example',
                'industry' => 'Battery Manufacturing',
                'source_channel' => 'Website',
                'phone' => '+61 400',
                'contact_title' => 'Purchasing Manager',
                'owner' => 'CRM Admin',
                'status' => 'active',
                'notes' => 'Important customer.',
            ])
            ->assertRedirect();

        $customer = CrmCustomer::query()->where('company_name', 'Acme Automation Ltd')->firstOrFail();
        $this->assertSame((int) $collection->id, (int) $customer->collection_id);
        $this->assertDatabaseHas('crm_customers', [
            'id' => (int) $customer->id,
            'phone' => '+61 400',
            'contact_title' => 'Purchasing Manager',
            'owner' => 'CRM Admin',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.customers.follow-ups.store', ['customerId' => (int) $customer->id]), [
                'content' => 'Confirmed application requirements.',
                'next_action' => 'Prepare quotation.',
                'owner' => 'CRM Admin',
                'status' => 'open',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('crm_follow_ups', [
            'customer_id' => (int) $customer->id,
            'content' => 'Confirmed application requirements.',
            'owner' => 'CRM Admin',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.customers.show', ['customerId' => (int) $customer->id]))
            ->assertOk()
            ->assertSee('Acme Automation Ltd')
            ->assertSee('Purchasing Manager')
            ->assertSee('+61 400')
            ->assertSee('CRM Admin')
            ->assertSee('Confirmed application requirements.');
    }

    public function test_inquiry_links_are_collection_limited_and_analysis_recommends_existing_materials(): void
    {
        $admin = $this->admin('crm_inquiry_admin');
        $collection = $this->collection('Cooling CRM');
        $otherCollection = $this->collection('Lighting CRM');
        $customer = CrmCustomer::query()->create([
            'collection_id' => (int) $collection->id,
            'company_name' => 'Cooling Buyer',
                'contact_person' => 'John Smith', 
            'status' => 'active',
        ]);

        $entity = EntityRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060',
            'entity_type' => '产品型号',
            'aliases' => 'SJ-4060',
            'description' => 'Industrial cooling product.',
        ]);
        $otherEntity = EntityRecord::query()->create([
            'collection_id' => (int) $otherCollection->id,
            'name' => 'LIGHT999',
            'entity_type' => '产品型号',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060 Manual',
            'description' => 'SJ4060 installation and sizing manual.',
            'summary' => 'SJ4060 technical manual.',
            'content' => 'SJ4060 supports industrial cooling applications.',
            'file_type' => 'markdown',
        ]);
        $otherKnowledgeBase = KnowledgeBase::query()->create([
            'collection_id' => (int) $otherCollection->id,
            'name' => 'LIGHT999 Manual',
            'content' => 'Lighting content.',
            'file_type' => 'markdown',
        ]);
        $caseRecord = CaseRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'entity_id' => (int) $entity->id,
            'title' => 'SJ4060 Battery Plant Case',
            'case_type' => 'application_scenario',
            'summary' => 'Battery plant case for SJ4060.',
        ]);
        $otherCaseRecord = CaseRecord::query()->create([
            'collection_id' => (int) $otherCollection->id,
            'title' => 'LIGHT999 Showroom Case',
            'case_type' => 'application_scenario',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.inquiries.store'), [
                'collection_id' => (int) $collection->id,
                'customer_id' => (int) $customer->id,
                'subject' => 'SJ4060 quotation request',
                'raw_message' => 'Need SJ4060 quotation for a battery plant.',
                'status' => 'new',
                'priority' => 'high',
                'assigned_to' => 'CRM Admin',
                'entity_ids' => [(int) $entity->id, (int) $otherEntity->id],
                'knowledge_base_ids' => [(int) $knowledgeBase->id, (int) $otherKnowledgeBase->id],
                'case_record_ids' => [(int) $caseRecord->id, (int) $otherCaseRecord->id],
            ])
            ->assertRedirect();

        $inquiry = CrmInquiry::query()->where('subject', 'SJ4060 quotation request')->firstOrFail();
        $this->assertSame('CRM Admin', (string) $inquiry->assigned_to);
        $this->assertSame([(int) $entity->id], $inquiry->entities()->pluck('entities.id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([(int) $knowledgeBase->id], $inquiry->knowledgeBases()->pluck('knowledge_bases.id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([(int) $caseRecord->id], $inquiry->cases()->pluck('case_records.id')->map(fn ($id): int => (int) $id)->all());

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.crm.inquiries.analyze'), [
                'collection_id' => (int) $collection->id,
                'content' => 'Urgent: Need SJ4060 quotation for battery plant. Please use SJ4060 Manual and SJ4060 Battery Plant Case.',
                'ai_model_id' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('fields.entity_ids.0', (int) $entity->id)
            ->assertJsonPath('fields.knowledge_base_ids.0', (int) $knowledgeBase->id)
            ->assertJsonPath('fields.case_record_ids.0', (int) $caseRecord->id)
            ->assertJsonPath('fields.urgency_level', 'high');
    }

    public function test_admin_can_create_quote_from_inquiry_and_open_print_page(): void
    {
        $admin = $this->admin('crm_quote_admin');
        $collection = $this->collection('Quote CRM');
        $customer = CrmCustomer::query()->create([
            'collection_id' => (int) $collection->id,
            'company_name' => 'Quote Buyer',
                'contact_person' => 'John Smith', 
            'status' => 'active',
        ]);
        $entity = EntityRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060',
            'entity_type' => '产品型号',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'subject' => 'Need quotation',
            'status' => 'qualified',
            'priority' => 'normal',
        ]);
        $inquiry->entities()->attach((int) $entity->id);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.create', ['inquiry_id' => (int) $inquiry->id]))
            ->assertOk()
            ->assertSee('SJ4060');

        Storage::fake('public');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.quotes.store'), [
                'collection_id' => (int) $collection->id,
                'customer_id' => (int) $customer->id,
                'inquiry_id' => (int) $inquiry->id,
                'title' => 'SJ4060 Quotation',
                'currency' => 'USD',
                'status' => 'draft',
                'items' => [
                    'entity_id' => [(int) $entity->id, ''],
                    'item_name' => ['SJ4060 System', ''],
                    'description' => ['Configured system.', ''],
                    'quantity' => [2, ''],
                    'unit' => ['set', ''],
                    'unit_price' => [1500, ''],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('crm_quotes', [
            'title' => 'SJ4060 Quotation',
            'total_amount' => 3000,
        ]);
        $this->assertDatabaseHas('crm_quote_items', [
            'item_name' => 'SJ4060 System',
            'quantity' => 2,
            'unit_price' => 1500,
            'amount' => 3000,
        ]);

        $quote = \App\Models\CrmQuote::query()->where('title', 'SJ4060 Quotation')->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.print', ['quoteId' => (int) $quote->id]))
            ->assertOk()
            ->assertSee('Quotation')
            ->assertSee('SJ4060 System')
            ->assertSee('USD 3,000.00');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.quotes.store'), [
                'collection_id' => (int) $collection->id,
                'customer_id' => (int) $customer->id,
                'document_type' => 'invoice',
                'title' => 'SJ4060 Commercial Invoice',
                'currency' => 'USD',
                'buyer_company' => 'Quote Buyer',
                'trade_term' => 'FOB',
                'shipping_fee' => 200,
                'discount_amount' => 50,
                'tax_amount' => 10,
                'status' => 'draft',
                'items' => [
                    'entity_id' => [(int) $entity->id],
                    'line_type' => ['product'],
                    'model' => ['SJ4060'],
                    'hs_code' => ['842489'],
                    'image_upload' => [UploadedFile::fake()->image('sj4060.png', 64, 64)->size(50)],
                    'item_name' => ['SJ4060 Invoice System'],
                    'description' => ['Commercial invoice line.'],
                    'quantity' => [1],
                    'unit' => ['set'],
                    'unit_price' => [1000],
                    'package_count' => [2],
                    'net_weight' => [80],
                    'gross_weight' => [95],
                    'volume_cbm' => [1.2],
                ],
            ])
            ->assertRedirect();

        $invoice = CrmQuote::query()->where('title', 'SJ4060 Commercial Invoice')->firstOrFail();
        $this->assertSame('invoice', (string) $invoice->document_type);
        $this->assertSame('1160.00', (string) $invoice->grand_total);
        $this->assertDatabaseHas('crm_quote_items', [
            'quote_id' => (int) $invoice->id,
            'item_name' => 'SJ4060 Invoice System',
            'line_type' => 'product',
            'package_count' => 2,
        ]);
        $this->assertNotSame('', (string) $invoice->items()->firstOrFail()->image_path);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.print', ['quoteId' => (int) $invoice->id]))
            ->assertOk()
            ->assertSee('Commercial Invoice')
            ->assertSee('SJ4060 Invoice System');

        foreach ([
            'proforma_invoice' => 'Proforma Invoice',
            'packing_list' => 'Packing List',
            'contract' => 'Contract',
        ] as $documentType => $expectedTitle) {
            $document = CrmQuote::query()->create([
                'collection_id' => (int) $collection->id,
                'customer_id' => (int) $customer->id,
                'quote_no' => 'DOC-'.strtoupper($documentType),
                'document_type' => $documentType,
                'title' => $expectedTitle.' Test',
                'currency' => 'USD',
                'buyer_company' => 'Quote Buyer',
                'payment_terms' => '30% deposit.',
                'delivery_terms' => 'Ship by sea.',
                'contract_terms' => 'Custom contract terms for this buyer.',
                'bank_account_json' => ['bank_name' => 'Test Bank', 'account_no' => '123456', 'beneficiary' => 'Test Beneficiary', 'swift' => 'TESTCNBJ'],
                'total_amount' => 1000,
                'grand_total' => 1000,
                'status' => 'draft',
            ]);
            $document->items()->create([
                'entity_id' => (int) $entity->id,
                'line_type' => 'product',
                    'model' => 'SJ4060',
                'hs_code' => '842489',
                'item_name' => 'SJ4060 System',
                'quantity' => 1,
                'unit' => 'set',
                'unit_price' => 1000,
                'amount' => 1000,
                'package_count' => 1,
                'net_weight' => 80,
                'gross_weight' => 95,
                'volume_cbm' => 1.2,
                'sort_order' => 1,
            ]);

            $response = $this->actingAs($admin, 'admin')
                ->get(route('admin.crm.quotes.print', ['quoteId' => (int) $document->id]))
                ->assertOk()
                ->assertSee($expectedTitle);

            if ($documentType === 'packing_list') {
                $response->assertSee('Packages')->assertDontSee('Unit Price');
            }
            if ($documentType === 'contract') {
                $response->assertSee('Custom contract terms for this buyer.');
            }
            if ($documentType === 'proforma_invoice') {
                $response->assertSee('Test Bank');
            }
        }
    }

    public function test_admin_can_save_seller_profiles_and_quote_json_must_be_valid(): void
    {
        $admin = $this->admin('crm_seller_profile_admin');
        $collection = $this->collection('Seller Profile CRM');
        $customer = CrmCustomer::query()->create([
            'collection_id' => (int) $collection->id,
            'company_name' => 'Seller Profile Buyer',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.crm.quotes.seller-profiles.store'), [
                'type' => 'seller_company',
                'name' => 'Robota Default Seller',
                'payload' => json_encode([
                    'name' => 'Robota Automation',
                    'address' => 'Shenzhen',
                    'email' => 'sales@example.com',
                ], JSON_THROW_ON_ERROR),
                'set_default' => true,
            ])
            ->assertOk()
            ->assertJsonPath('profile.name', 'Robota Default Seller')
            ->assertJsonPath('profile.is_default', true);

        $this->assertDatabaseHas('crm_seller_profiles', [
            'type' => 'seller_company',
            'name' => 'Robota Default Seller',
            'is_default' => true,
        ]);
        $this->assertSame('Robota Automation', (string) CrmSellerProfile::query()->firstOrFail()->payload['name']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.quotes.create', ['collection_id' => (int) $collection->id]))
            ->assertOk()
            ->assertSee('Robota Default Seller')
            ->assertSee('Seller Company JSON');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.quotes.store'), [
                'collection_id' => (int) $collection->id,
                'customer_id' => (int) $customer->id,
                'title' => 'Invalid Seller JSON Quote',
                'seller_company_json' => '{"name":',
                'bank_account_json' => '{"bank_name":"Valid Bank"}',
                'items' => [
                    'item_name' => ['Machine'],
                    'quantity' => [1],
                    'unit_price' => [100],
                ],
            ])
            ->assertSessionHasErrors('seller_company_json');
    }

    public function test_admin_can_convert_quote_to_order_and_create_after_sales_ticket(): void
    {
        $admin = $this->admin('crm_order_admin');
        $collection = $this->collection('Order CRM');
        $customer = CrmCustomer::query()->create([
            'collection_id' => (int) $collection->id,
            'company_name' => 'Order Buyer',
                'contact_person' => 'John Smith', 
            'status' => 'active',
        ]);
        $entity = EntityRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060',
            'entity_type' => '产品型号',
        ]);
        $quote = CrmQuote::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'quote_no' => 'Q-TEST-001',
            'title' => 'SJ4060 Quote',
            'currency' => 'USD',
            'total_amount' => 1200,
            'status' => 'accepted',
        ]);
        $quote->items()->create([
            'entity_id' => (int) $entity->id,
            'item_name' => 'SJ4060 System',
            'quantity' => 1,
            'unit' => 'set',
            'unit_price' => 1200,
            'amount' => 1200,
            'sort_order' => 1,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.orders.from-quote', ['quoteId' => (int) $quote->id]))
            ->assertRedirect();

        $order = CrmSalesOrder::query()->where('quote_id', (int) $quote->id)->firstOrFail();
        $this->assertSame('SJ4060 Quote', (string) $order->title);
        $this->assertDatabaseHas('crm_sales_order_items', [
            'order_id' => (int) $order->id,
            'item_name' => 'SJ4060 System',
            'amount' => 1200,
        ]);

        $knowledgeBase = KnowledgeBase::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'SJ4060 Troubleshooting',
            'content' => 'Alarm diagnosis content.',
            'file_type' => 'markdown',
        ]);
        $caseRecord = CaseRecord::query()->create([
            'collection_id' => (int) $collection->id,
            'entity_id' => (int) $entity->id,
            'title' => 'SJ4060 Alarm Case',
            'case_type' => 'troubleshooting',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.tickets.create', ['order_id' => (int) $order->id]))
            ->assertOk()
            ->assertSee('SJ4060')
            ->assertSee('AI 工单分析');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.tickets.store'), [
                'collection_id' => (int) $collection->id,
                'customer_id' => (int) $customer->id,
                'order_id' => (int) $order->id,
                'entity_id' => (int) $entity->id,
                'title' => 'SJ4060 alarm after installation',
                'issue_description' => 'Customer reports alarm after installation.',
                'priority' => 'high',
                'status' => 'open',
                'knowledge_base_ids' => [(int) $knowledgeBase->id],
                'case_record_ids' => [(int) $caseRecord->id],
            ])
            ->assertRedirect();

        $ticket = CrmAfterSalesTicket::query()->where('title', 'SJ4060 alarm after installation')->firstOrFail();
        $this->assertSame([(int) $knowledgeBase->id], $ticket->knowledgeBases()->pluck('knowledge_bases.id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([(int) $caseRecord->id], $ticket->cases()->pluck('case_records.id')->map(fn ($id): int => (int) $id)->all());

        $this->actingAs($admin, 'admin')
            ->get(route('admin.crm.tickets.show', ['ticketId' => (int) $ticket->id]))
            ->assertOk()
            ->assertSee('生成 FAQ 草稿')
            ->assertSee('SJ4060 Troubleshooting');
    }

    public function test_crm_content_proposals_are_applied_only_after_confirmation(): void
    {
        $admin = $this->admin('crm_proposal_admin');
        $collection = $this->collection('Proposal CRM');
        $customer = CrmCustomer::query()->create([
            'collection_id' => (int) $collection->id,
            'company_name' => 'Proposal Buyer',
                'contact_person' => 'John Smith', 
            'status' => 'active',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'subject' => 'Need SJ4060 sizing',
            'raw_message' => 'How to size SJ4060 for battery manufacturing?',
            'status' => 'qualified',
            'priority' => 'normal',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'CRM Titles',
            'description' => '',
            'title_count' => 0,
            'generation_type' => 'manual',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.proposals.from-inquiry', ['inquiryId' => (int) $inquiry->id]), [
                'proposal_type' => 'title_suggestion',
            ])
            ->assertRedirect(route('admin.crm.proposals.index', ['proposal_type' => 'title_suggestion']));

        $proposal = CrmContentProposal::query()->where('proposal_type', 'title_suggestion')->firstOrFail();
        $this->assertSame('pending', (string) $proposal->status);
        $this->assertSame(0, Title::query()->count());

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.proposals.apply', ['proposalId' => (int) $proposal->id]), [
                'title_library_id' => (int) $titleLibrary->id,
            ])
            ->assertRedirect();

        $proposal->refresh();
        $this->assertSame('applied', (string) $proposal->status);
        $this->assertSame(1, Title::query()->where('library_id', (int) $titleLibrary->id)->count());

        $ticket = CrmAfterSalesTicket::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'title' => 'Installation alarm',
            'issue_description' => 'Alarm on startup.',
            'reply_points' => 'Check wiring and parameters.',
            'resolution' => 'Adjusted sensor wiring.',
            'priority' => 'normal',
            'status' => 'resolved',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.proposals.from-ticket', ['ticketId' => (int) $ticket->id]), [
                'proposal_type' => 'faq_draft',
            ])
            ->assertRedirect(route('admin.crm.proposals.index', ['proposal_type' => 'faq_draft']));

        $faqProposal = CrmContentProposal::query()->where('proposal_type', 'faq_draft')->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.crm.proposals.apply', ['proposalId' => (int) $faqProposal->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('knowledge_bases', [
            'collection_id' => (int) $collection->id,
            'knowledge_type' => 'faq',
            'status' => 'active',
        ]);
    }

    public function test_task_create_can_record_crm_source(): void
    {
        $admin = $this->admin('crm_task_admin');
        $collection = $this->collection('Task CRM');
        $customer = CrmCustomer::query()->create([
            'collection_id' => (int) $collection->id,
            'company_name' => 'Task Buyer',
                'contact_person' => 'John Smith', 
            'status' => 'active',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'collection_id' => (int) $collection->id,
            'customer_id' => (int) $customer->id,
            'subject' => 'Task source inquiry',
            'status' => 'qualified',
            'priority' => 'normal',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'collection_id' => (int) $collection->id,
            'name' => 'Task Titles',
            'description' => '',
            'title_count' => 1,
            'generation_type' => 'manual',
        ]);
        Title::query()->create([
            'library_id' => (int) $titleLibrary->id,
            'title' => 'How to choose SJ4060',
            'keyword' => 'SJ4060',
        ]);
        $prompt = Prompt::query()->create([
            'name' => 'Content Prompt',
            'type' => 'content',
            'content' => 'Write an article.',
        ]);
        $model = AiModel::query()->create([
            'name' => 'Local Model',
            'model_id' => 'local-model',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        \App\Models\Category::query()->create([
            'name' => 'Articles',
            'slug' => 'articles',
            'sort_order' => 1,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee('CRM 来源')
            ->assertSee('Task source inquiry');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tasks.store'), [
                'task_name' => 'CRM sourced task',
                'collection_id' => (int) $collection->id,
                'title_library_id' => (int) $titleLibrary->id,
                'prompt_id' => (int) $prompt->id,
                'ai_model_id' => (int) $model->id,
                'crm_source_type' => 'inquiry',
                'crm_source_id' => (int) $inquiry->id,
                'status' => 'paused',
                'article_limit' => 3,
                'draft_limit' => 2,
                'publish_interval' => 60,
                'category_mode' => 'smart',
                'model_selection_mode' => 'fixed',
                'publish_scope' => 'local_only',
            ])
            ->assertRedirect(route('admin.tasks.index'));

        $task = Task::query()->where('name', 'CRM sourced task')->firstOrFail();
        $this->assertSame('inquiry', (string) $task->crm_source_type);
        $this->assertSame((int) $inquiry->id, (int) $task->crm_source_id);
    }

    public function test_customer_archive_preserves_commercial_records_and_primary_contact(): void
    {
        $admin = $this->admin('crm_archive_admin');
        $collection = $this->collection('Archive CRM');
        $this->actingAs($admin, 'admin')->post(route('admin.crm.customers.store'), [
            'collection_id'=>$collection->id,'company_name'=>'Archive Buyer','contact_person'=>'Alice','phone'=>'+1 555','email'=>'alice@example.com','status'=>'active',
        ])->assertRedirect();
        $customer = CrmCustomer::query()->where('company_name','Archive Buyer')->firstOrFail();
        $this->assertDatabaseHas('crm_customer_contacts',['customer_id'=>$customer->id,'name'=>'Alice','is_primary'=>1]);
        $quote = CrmQuote::query()->create(['customer_id'=>$customer->id,'quote_no'=>'Q-ARCHIVE','title'=>'Archive quote','document_type'=>'quotation','currency'=>'USD','status'=>'draft']);
        $this->actingAs($admin, 'admin')->post(route('admin.crm.customers.delete',['customerId'=>$customer->id]))->assertRedirect();
        $this->assertSoftDeleted('crm_customers',['id'=>$customer->id]);
        $this->assertDatabaseHas('crm_quotes',['id'=>$quote->id,'customer_id'=>$customer->id,'deleted_at'=>null]);
    }

    public function test_inquiry_activity_is_scoped_and_future_work_uses_tasks(): void
    {
        $admin = $this->admin('crm_activity_admin');
        $customer = CrmCustomer::query()->create(['company_name'=>'Activity Buyer','contact_person'=>'A','status'=>'active']);
        $first = CrmInquiry::query()->create(['customer_id'=>$customer->id,'subject'=>'First inquiry','status'=>'new','priority'=>'normal']);
        $second = CrmInquiry::query()->create(['customer_id'=>$customer->id,'subject'=>'Second inquiry','status'=>'new','priority'=>'normal']);
        $first->followUps()->create(['customer_id'=>$customer->id,'content'=>'Only first activity']);
        $second->followUps()->create(['customer_id'=>$customer->id,'content'=>'Only second activity']);
        $this->actingAs($admin,'admin')->get(route('admin.crm.inquiries.show',['inquiryId'=>$first->id]))->assertOk()->assertSee('Only first activity')->assertDontSee('Only second activity')->assertSee('下一步待办');
        $this->actingAs($admin,'admin')->post(route('admin.crm.tasks.store'),['customer_id'=>$customer->id,'inquiry_id'=>$first->id,'title'=>'Send specification','due_at'=>now()->addDay()->format('Y-m-d H:i:s')])->assertRedirect();
        $task = CrmTask::query()->firstOrFail();
        $this->actingAs($admin,'admin')->post(route('admin.crm.tasks.complete',['taskId'=>$task->id]))->assertRedirect();
        $this->assertSame('done',$task->fresh()->status);
    }

    public function test_inquiry_can_become_opportunity_and_lost_reason_is_required(): void
    {
        $admin = $this->admin('crm_opportunity_admin');
        $collection = $this->collection('Pipeline CRM');
        $customer = CrmCustomer::query()->create(['collection_id'=>$collection->id,'company_name'=>'Pipeline Buyer','contact_person'=>'Buyer','status'=>'active']);
        $inquiry = CrmInquiry::query()->create(['collection_id'=>$collection->id,'customer_id'=>$customer->id,'subject'=>'Machine project','status'=>'qualified','priority'=>'high']);
        $this->actingAs($admin,'admin')->get(route('admin.crm.inquiries.show',['inquiryId'=>$inquiry->id]))->assertOk()->assertSee('转为商机');
        $this->actingAs($admin,'admin')->post(route('admin.crm.opportunities.from-inquiry',['inquiryId'=>$inquiry->id]))->assertRedirect();
        $opportunity = CrmOpportunity::query()->firstOrFail();
        $this->assertSame('converted', (string) $inquiry->fresh()->status);
        $this->assertSame((int) $inquiry->id, (int) $opportunity->source_inquiry_id);
        $this->actingAs($admin,'admin')->put(route('admin.crm.opportunities.update',['opportunityId'=>$opportunity->id]),['customer_id'=>$customer->id,'name'=>'Machine project','stage'=>'lost','amount'=>12000,'currency'=>'USD','probability'=>0])->assertSessionHasErrors('lost_reason');
        $this->actingAs($admin,'admin')->get(route('admin.crm.opportunities.index'))->assertOk()->assertSee('Machine project');
        $this->actingAs($admin,'admin')->get(route('admin.crm.quotes.create',['opportunity_id'=>$opportunity->id]))->assertOk()->assertSee('关联商机')->assertSee('Machine project');
        $this->actingAs($admin,'admin')->post(route('admin.crm.quotes.store'),[
            'collection_id'=>$collection->id,
            'customer_id'=>$customer->id,
            'inquiry_id'=>$inquiry->id,
            'opportunity_id'=>$opportunity->id,
            'title'=>'Opportunity quotation',
            'currency'=>'USD',
            'status'=>'draft',
        ])->assertRedirect();
        $this->assertDatabaseHas('crm_quotes',[
            'title'=>'Opportunity quotation',
            'opportunity_id'=>$opportunity->id,
            'inquiry_id'=>$inquiry->id,
        ]);
        $closedInquiry = CrmInquiry::query()->create(['collection_id'=>$collection->id,'customer_id'=>$customer->id,'subject'=>'Closed inquiry','status'=>'closed','priority'=>'normal']);
        $this->actingAs($admin,'admin')->post(route('admin.crm.opportunities.store'),[
            'collection_id'=>$collection->id,
            'customer_id'=>$customer->id,
            'source_inquiry_id'=>$closedInquiry->id,
            'name'=>'Closed source opportunity',
            'stage'=>'qualified',
            'amount'=>0,
            'currency'=>'USD',
            'probability'=>10,
        ])->assertRedirect();
        $this->assertSame('closed', (string) $closedInquiry->fresh()->status);
    }

    public function test_quote_conversion_creates_independent_document_and_items(): void
    {
        $admin = $this->admin('crm_convert_admin');
        $customer = CrmCustomer::query()->create(['company_name'=>'Convert Buyer','contact_person'=>'Buyer','status'=>'active']);
        $quote = CrmQuote::query()->create(['customer_id'=>$customer->id,'quote_no'=>'Q-CONVERT','title'=>'Base quotation','document_type'=>'quotation','currency'=>'USD','status'=>'draft']);
        $quote->items()->create(['item_name'=>'Machine','quantity'=>2,'unit'=>'set','unit_price'=>100,'amount'=>200]);
        $this->actingAs($admin,'admin')->post(route('admin.crm.quotes.convert',['quoteId'=>$quote->id]),['document_type'=>'proforma_invoice'])->assertRedirect();
        $copy = CrmQuote::query()->where('source_quote_id',$quote->id)->firstOrFail();
        $this->assertSame('proforma_invoice',$copy->document_type);
        $this->assertNotSame($quote->quote_no,$copy->quote_no);
        $this->assertDatabaseHas('crm_quote_items',['quote_id'=>$copy->id,'item_name'=>'Machine']);
    }

    private function admin(string $username = 'crm_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'CRM Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function collection(string $name): CollectionRecord
    {
        return CollectionRecord::query()->create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'description' => '',
            'status' => 'active',
            'sort_order' => 1,
        ]);
    }
}
