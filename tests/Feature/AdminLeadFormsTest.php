<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLeadFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_form_selects_use_inset_custom_chevrons(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.lead-forms.create'))
            ->assertOk()
            ->assertSee('h-10', false)
            ->assertSee('appearance-none', false)
            ->assertSee('pr-10', false)
            ->assertSee('data-lucide="chevron-down"', false)
            ->assertSee('right-3', false);
    }

    public function test_create_form_field_rows_use_single_line_options_and_centered_required_control(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.lead-forms.create'))
            ->assertOk()
            ->assertSee('<input type="text" name="fields[0][options]"', false)
            ->assertDontSee('<textarea name="fields[0][options]"', false)
            ->assertSee(__('admin.lead_forms.options_placeholder_inline'), false)
            ->assertSee('justify-center', false)
            ->assertSee('h-4 w-4', false);
    }

    public function test_admin_can_create_edit_toggle_and_delete_lead_forms(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.lead-forms.store'), [
                'name' => '产品咨询表单',
                'slug' => 'product-demo',
                'status' => 'active',
                'description' => '收集产品咨询需求',
                'submit_button_label' => '预约演示',
                'success_message' => '已收到预约',
                'fields' => [
                    ['label' => '姓名', 'name' => 'name', 'type' => 'text', 'required' => '1'],
                    ['label' => '预算', 'name' => 'budget', 'type' => 'select', 'required' => '1', 'options' => "1万以内\n1-5万，5万以上|定制预算"],
                    ['label' => '公司名称', 'name' => '', 'type' => 'text', 'required' => ''],
                    ['label' => '来源页面', 'name' => 'source_url', 'type' => 'text', 'required' => ''],
                ],
            ])
            ->assertRedirect(route('admin.lead-forms.index'));

        $form = LeadForm::query()->firstOrFail();
        $this->assertSame('product-demo', $form->slug);
        $this->assertSame('budget', $form->fields[1]['name']);
        $this->assertSame(['1万以内', '1-5万', '5万以上', '定制预算'], $form->fields[1]['options']);
        $this->assertSame('field_3', $form->fields[2]['name']);
        $this->assertSame('source_url_field', $form->fields[3]['name']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.lead-forms.index'))
            ->assertOk()
            ->assertSee('产品咨询表单')
            ->assertSee('/forms/product-demo');

        $this->actingAs($admin, 'admin')
            ->put(route('admin.lead-forms.update', ['formId' => $form->id]), [
                'name' => '新版咨询表单',
                'slug' => 'product-demo-v2',
                'status' => 'inactive',
                'description' => '新版说明',
                'submit_button_label' => '提交需求',
                'success_message' => '提交成功',
                'fields' => [
                    ['label' => '邮箱', 'name' => 'email', 'type' => 'email', 'required' => '1'],
                ],
            ])
            ->assertRedirect(route('admin.lead-forms.index'));

        $form->refresh();
        $this->assertSame('product-demo-v2', $form->slug);
        $this->assertSame(LeadForm::STATUS_INACTIVE, $form->status);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.lead-forms.toggle-status', ['formId' => $form->id]))
            ->assertRedirect();

        $this->assertSame(LeadForm::STATUS_ACTIVE, $form->refresh()->status);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.lead-forms.delete', ['formId' => $form->id]))
            ->assertRedirect(route('admin.lead-forms.index'));

        $this->assertDatabaseMissing('lead_forms', ['id' => $form->id]);
    }

    public function test_admin_cannot_delete_forms_that_have_submissions(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $form = $this->leadForm();
        LeadSubmission::query()->create([
            'lead_form_id' => $form->id,
            'payload' => ['name' => 'Alice'],
            'source_url' => '/forms/contact',
            'ip_address' => '127.0.0.1',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->from(route('admin.lead-forms.index'))
            ->post(route('admin.lead-forms.delete', ['formId' => $form->id]))
            ->assertRedirect(route('admin.lead-forms.index'))
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('lead_forms', ['id' => $form->id]);
    }

    public function test_lead_form_normalizes_chinese_label_without_field_name(): void
    {
        $form = new LeadForm([
            'fields' => [
                ['label' => '公司名称', 'name' => '', 'type' => 'text', 'required' => true, 'options' => []],
                ['label' => '来源页面', 'name' => 'source_url', 'type' => 'text', 'required' => false, 'options' => []],
                ['label' => '重复字段', 'name' => 'source url', 'type' => 'text', 'required' => false, 'options' => []],
            ],
        ]);

        $fields = $form->normalizedFields();

        $this->assertSame('field_1', $fields[0]['name']);
        $this->assertSame('公司名称', $fields[0]['label']);
        $this->assertSame('source_url_field', $fields[1]['name']);
        $this->assertCount(2, $fields);
    }

    public function test_admin_can_filter_update_and_export_leads(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = $this->admin();
        $form = $this->leadForm();
        $otherForm = $this->leadForm('partner', '合作咨询');

        $alice = LeadSubmission::query()->create([
            'lead_form_id' => $form->id,
            'status' => LeadSubmission::STATUS_NEW,
            'payload' => ['name' => 'Alice', 'email' => 'alice@example.com'],
            'source_url' => '/pricing',
            'ip_address' => '127.0.0.1',
            'created_at' => '2026-07-05 10:00:00',
        ]);
        LeadSubmission::query()->create([
            'lead_form_id' => $otherForm->id,
            'status' => LeadSubmission::STATUS_INVALID,
            'payload' => ['name' => 'Bob'],
            'source_url' => '/partners',
            'ip_address' => '127.0.0.2',
            'created_at' => '2026-07-04 10:00:00',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.leads.index', [
                'status' => LeadSubmission::STATUS_NEW,
                'form_id' => $form->id,
                'date_from' => '2026-07-05',
                'date_to' => '2026-07-05',
                'search' => 'alice',
            ]))
            ->assertOk()
            ->assertSee('Alice')
            ->assertDontSee('Bob');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.leads.show', ['submissionId' => $alice->id]))
            ->assertOk()
            ->assertSee('alice@example.com')
            ->assertSee(__('admin.leads.status.new'));

        $this->actingAs($admin, 'admin')
            ->put(route('admin.leads.update', ['submissionId' => $alice->id]), [
                'status' => LeadSubmission::STATUS_QUALIFIED,
                'note' => '=1+1',
            ])
            ->assertRedirect(route('admin.leads.show', ['submissionId' => $alice->id]));

        $this->assertDatabaseHas('lead_submissions', [
            'id' => $alice->id,
            'status' => LeadSubmission::STATUS_QUALIFIED,
            'note' => '=1+1',
            'handled_by' => $admin->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.leads.index', [
                'date_from' => 'not-a-date',
                'date_to' => 'still-not-a-date',
            ]))
            ->assertOk();

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.leads.export', ['status' => LeadSubmission::STATUS_QUALIFIED]));

        $response->assertOk()->assertStreamed();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Alice', $csv);
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringNotContainsString('Bob', $csv);
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'lead_admin_'.uniqid(),
            'password' => 'secret-123',
            'email' => uniqid('lead-admin-').'@example.com',
            'display_name' => 'Lead Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function leadForm(string $slug = 'contact', string $name = '联系表单'): LeadForm
    {
        return LeadForm::query()->create([
            'name' => $name,
            'slug' => $slug,
            'status' => LeadForm::STATUS_ACTIVE,
            'description' => 'Tell us what you need',
            'submit_button_label' => 'Submit',
            'success_message' => 'Thanks',
            'fields' => [
                ['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true, 'options' => []],
                ['name' => 'email', 'label' => '邮箱', 'type' => 'email', 'required' => false, 'options' => []],
            ],
        ]);
    }
}
