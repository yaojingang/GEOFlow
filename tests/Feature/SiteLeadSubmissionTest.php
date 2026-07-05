<?php

namespace Tests\Feature;

use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Models\SiteSetting;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteLeadSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_public_form_can_be_viewed_and_submitted(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $form = $this->leadForm();

        $this->get(route('site.lead-forms.show', ['slug' => $form->slug]))
            ->assertOk()
            ->assertSee('增长咨询')
            ->assertSee('预算');

        $this->post(route('site.lead-forms.submit', ['slug' => $form->slug]), [
            'name' => 'Alice',
            'phone' => '+86 13800000000',
            'email' => 'alice@example.com',
            'budget' => '1-5万',
            'message' => '想了解 GEO 内容增长方案',
            'agree' => '1',
            'source_url' => 'https://example.com/pricing',
        ])
            ->assertRedirect(route('site.lead-forms.show', ['slug' => $form->slug]))
            ->assertSessionHas('message', '我们已收到你的需求');

        $submission = LeadSubmission::query()->firstOrFail();
        $this->assertSame($form->id, $submission->lead_form_id);
        $this->assertSame(LeadSubmission::STATUS_NEW, $submission->status);
        $this->assertSame('Alice', $submission->payload['name']);
        $this->assertSame('1-5万', $submission->payload['budget']);
        $this->assertTrue($submission->payload['agree']);
        $this->assertSame('https://example.com/pricing', $submission->source_url);
    }

    public function test_public_submission_validates_required_email_phone_and_select_options(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $form = $this->leadForm();

        $this->from(route('site.lead-forms.show', ['slug' => $form->slug]))
            ->post(route('site.lead-forms.submit', ['slug' => $form->slug]), [
                'name' => '',
                'phone' => 'abc',
                'email' => 'not-an-email',
                'budget' => '10万以上',
                'message' => '',
            ])
            ->assertRedirect(route('site.lead-forms.show', ['slug' => $form->slug]))
            ->assertSessionHasErrors(['name', 'phone', 'email', 'budget', 'message']);

        $this->assertDatabaseCount('lead_submissions', 0);
    }

    public function test_public_submission_never_redirects_to_external_referer(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $form = $this->leadForm();

        $this->withHeader('referer', 'https://evil.example/phishing')
            ->post(route('site.lead-forms.submit', ['slug' => $form->slug]), [
                'name' => '',
                'phone' => 'abc',
                'email' => 'not-an-email',
                'budget' => '10万以上',
                'message' => '',
            ])
            ->assertRedirect(route('site.lead-forms.show', ['slug' => $form->slug]))
            ->assertSessionHasErrors(['name', 'phone', 'email', 'budget', 'message']);

        $this->withHeader('referer', 'https://evil.example/phishing')
            ->post(route('site.lead-forms.submit', ['slug' => $form->slug]), [
                'name' => 'Alice',
                'phone' => '+86 13800000000',
                'email' => 'alice@example.com',
                'budget' => '1-5万',
                'message' => '想了解 GEO 内容增长方案',
            ])
            ->assertRedirect(route('site.lead-forms.show', ['slug' => $form->slug]))
            ->assertSessionHas('message', '我们已收到你的需求');
    }

    public function test_disabled_or_missing_forms_return_404(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $form = $this->leadForm(status: LeadForm::STATUS_INACTIVE);

        $this->get(route('site.lead-forms.show', ['slug' => $form->slug]))
            ->assertNotFound();

        $this->post(route('site.lead-forms.submit', ['slug' => $form->slug]), [
            'name' => 'Alice',
        ])->assertNotFound();

        $this->get('/forms/missing-form')->assertNotFound();
    }

    public function test_honeypot_submission_returns_success_without_creating_lead(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $form = $this->leadForm();

        $this->from(route('site.lead-forms.show', ['slug' => $form->slug]))
            ->post(route('site.lead-forms.submit', ['slug' => $form->slug]), [
                'website' => 'bot.example',
                'name' => 'Spam',
            ])
            ->assertRedirect(route('site.lead-forms.show', ['slug' => $form->slug]))
            ->assertSessionHas('message', '我们已收到你的需求');

        $this->assertDatabaseCount('lead_submissions', 0);
    }

    public function test_homepage_lead_form_module_embeds_active_form_and_submits_back_to_home(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $form = $this->leadForm();
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'homepage_modules'],
            ['setting_value' => json_encode([
                [
                    'id' => 'lead-module',
                    'type' => 'lead_form',
                    'layout' => 'single',
                    'enabled' => true,
                    'sort_order' => 10,
                    'title' => '领取 GEO 增长诊断',
                    'body' => '留下需求，我们会整理诊断建议',
                    'lead_form_slug' => $form->slug,
                ],
            ], JSON_UNESCAPED_UNICODE)]
        );

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('领取 GEO 增长诊断')
            ->assertSee('预算');

        $this->withHeader('referer', route('site.home'))
            ->post(route('site.lead-forms.submit', ['slug' => $form->slug]), [
                'name' => 'Homepage Lead',
                'phone' => '13800000000',
                'email' => 'home@example.com',
                'budget' => '1万以内',
                'message' => '首页提交',
            ])
            ->assertRedirect(route('site.home'))
            ->assertSessionHas('message', '我们已收到你的需求');

        $this->assertDatabaseHas('lead_submissions', [
            'lead_form_id' => $form->id,
            'source_url' => route('site.home'),
        ]);
    }

    private function leadForm(string $status = LeadForm::STATUS_ACTIVE): LeadForm
    {
        return LeadForm::query()->create([
            'name' => '增长咨询',
            'slug' => 'growth-consulting',
            'status' => $status,
            'description' => '提交你的增长需求',
            'submit_button_label' => '提交需求',
            'success_message' => '我们已收到你的需求',
            'fields' => [
                ['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true, 'options' => []],
                ['name' => 'phone', 'label' => '手机号', 'type' => 'phone', 'required' => true, 'options' => []],
                ['name' => 'email', 'label' => '邮箱', 'type' => 'email', 'required' => true, 'options' => []],
                ['name' => 'budget', 'label' => '预算', 'type' => 'select', 'required' => true, 'options' => ['1万以内', '1-5万']],
                ['name' => 'message', 'label' => '需求描述', 'type' => 'textarea', 'required' => true, 'options' => []],
                ['name' => 'agree', 'label' => '确认', 'type' => 'checkbox', 'required' => false, 'options' => ['同意提交这些信息']],
            ],
        ]);
    }
}
