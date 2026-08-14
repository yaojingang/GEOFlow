<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ContentApproval;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Models\PublicFact;
use App\Models\PublicPage;
use App\Models\PublicationSnapshot;
use App\Services\ZeroPoint\PublicContentWorkflow;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZeroPointPublicSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('zeropoint.enabled', true);
    }

    public function test_publish_requires_all_four_gates_on_the_same_hash(): void
    {
        $page = $this->page();
        $admin = $this->admin();
        $workflow = app(PublicContentWorkflow::class);
        $workflow->approve($page, 'facts', $admin, 'approved');

        $this->expectException(DomainException::class);
        $workflow->publish($page, $admin);
    }

    public function test_public_site_reads_snapshot_while_later_draft_stays_private(): void
    {
        $page = $this->page(['slug' => 'home', 'title' => '已审核标题', 'body' => '已审核正文']);
        $this->approveAndPublish($page);

        $page->update(['title' => '未审核的新标题', 'body' => '未审核的新正文', 'version' => 2, 'status' => 'draft']);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('已审核标题')
            ->assertSee('已审核正文')
            ->assertDontSee('未审核的新标题')
            ->assertSee('noindex,nofollow', false)
            ->assertSee('application/ld+json', false);
    }

    public function test_fact_change_invalidates_existing_approvals(): void
    {
        $fact = PublicFact::query()->create([
            'fact_code' => 'ORG-001', 'entity_type' => 'organization', 'statement' => '已核验主体事实',
            'evidence_level' => 3, 'visibility' => 'public', 'status' => 'approved',
        ]);
        $page = $this->page(['area' => 'institution', 'is_placeholder' => false]);
        $page->facts()->sync([$fact->id]);
        $page->save();
        $oldHash = $page->content_hash;
        $this->approveAndPublish($page);

        $fact->update(['statement' => '变化后的事实']);
        $page->version++;
        $page->status = 'draft';
        $page->save();

        $this->assertNotSame($oldHash, $page->content_hash);
        $this->expectException(DomainException::class);
        app(PublicContentWorkflow::class)->publish($page, $this->admin('publisher'));
    }

    public function test_health_content_cannot_publish_with_booking_cta(): void
    {
        $page = $this->page(['area' => 'health', 'cta_label' => '立即预约', 'cta_url' => '/booking']);
        $admin = $this->admin();
        $workflow = app(PublicContentWorkflow::class);
        foreach (ContentApproval::GATES as $gate) {
            $workflow->approve($page, $gate, $admin, 'approved');
        }

        $this->expectException(DomainException::class);
        $workflow->publish($page, $admin);
    }

    public function test_expired_fact_removes_institution_snapshot_from_public_reads(): void
    {
        $fact = PublicFact::query()->create([
            'fact_code' => 'LICENSE-EXPIRY', 'entity_type' => 'license', 'statement' => '限期有效事实',
            'evidence_level' => 3, 'visibility' => 'public', 'status' => 'approved', 'expires_at' => now()->addDay()->toDateString(),
        ]);
        $page = $this->page(['slug' => 'credentials', 'area' => 'institution', 'is_placeholder' => false]);
        $page->facts()->sync([$fact->id]);
        $page->save();
        $this->approveAndPublish($page);

        $this->get(route('site.zeropoint.credentials'))->assertOk();
        $this->travel(2)->days();
        $this->get(route('site.zeropoint.credentials'))->assertNotFound();
    }

    public function test_sitemap_only_contains_active_publication_snapshots(): void
    {
        $active = $this->page(['slug' => 'credentials', 'title' => '活动页面', 'is_placeholder' => false]);
        $inactive = $this->page(['slug' => 'contact', 'title' => '未发布页面']);
        $this->approveAndPublish($active);

        $this->get(route('site.zeropoint.sitemap'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee(route('site.zeropoint.credentials'), false)
            ->assertDontSee(route('site.zeropoint.contact'), false);
        $this->assertDatabaseMissing('publication_snapshots', ['public_page_id' => $inactive->id]);

        $this->get(route('site.zeropoint.llms'))
            ->assertOk()
            ->assertSee('活动页面')
            ->assertDontSee('未发布页面');
    }

    public function test_light_booking_stores_only_configured_fields_and_attribution(): void
    {
        $this->bookingForm();
        $response = $this->get(route('site.zeropoint.booking').'?utm_source=wechat')->assertOk();
        preg_match('/name="_booking_token" value="([^"]+)"/', $response->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $this->post(route('site.zeropoint.booking.submit'), [
            '_booking_token' => $matches[1],
            'name' => '李女士',
            'phone' => '13800138000',
            'service_interest' => '一般咨询（暂不指定项目）',
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_period' => '下午 13:00—17:00',
            'contact_consent' => '1',
            'privacy_consent' => '1',
            'medical_history' => '这段信息不应被保存',
            'utm_source' => 'wechat',
        ])->assertRedirect(route('site.zeropoint.booking'))->assertSessionHas('message');

        $submission = LeadSubmission::query()->sole();
        $this->assertStringStartsWith('ZP-', (string) $submission->reference_code);
        $this->assertArrayNotHasKey('medical_history', $submission->payload);
        $this->assertSame('wechat', data_get($submission->attribution, 'utm_source'));
    }

    public function test_only_super_admin_can_record_a_gate_decision(): void
    {
        $page = $this->page();
        $regular = $this->admin('regular', 'admin');
        $this->actingAs($regular, 'admin')->post(route('admin.public-pages.approve', $page->id), [
            'gate' => 'facts', 'decision' => 'approved',
        ])->assertForbidden();

        $super = $this->admin('super', 'super_admin');
        $this->actingAs($super, 'admin')->get(route('admin.public-pages.index'))
            ->assertOk()->assertSee('正负零官网内容');
        $this->actingAs($super, 'admin')->get(route('admin.public-pages.edit', $page->id))
            ->assertOk()->assertSee('四门审核');
        $this->actingAs($super, 'admin')->post(route('admin.public-pages.approve', $page->id), [
            'gate' => 'facts', 'decision' => 'approved',
        ])->assertRedirect(route('admin.public-pages.edit', $page->id));
        $this->assertDatabaseHas('content_approvals', ['public_page_id' => $page->id, 'gate' => 'facts', 'decision' => 'approved']);
    }

    public function test_legacy_demo_content_is_not_public_in_zero_point_mode(): void
    {
        $this->get('/article/demo-article')->assertNotFound();
        $this->get('/archive')->assertNotFound();
        $this->get('/forms/business-contact')->assertNotFound();
    }

    private function approveAndPublish(PublicPage $page): PublicationSnapshot
    {
        $admin = $this->admin();
        $workflow = app(PublicContentWorkflow::class);
        foreach (ContentApproval::GATES as $gate) {
            $workflow->approve($page, $gate, $admin, 'approved');
        }

        return $workflow->publish($page, $admin);
    }

    /** @param array<string, mixed> $overrides */
    private function page(array $overrides = []): PublicPage
    {
        return PublicPage::query()->create(array_merge([
            'slug' => 'page-'.uniqid(), 'page_type' => 'governance', 'area' => 'governance',
            'title' => '测试页面', 'eyebrow' => 'TEST', 'summary' => '测试摘要', 'body' => '测试正文',
            'seo_title' => '测试页面', 'meta_description' => '测试摘要', 'cta_label' => '', 'cta_url' => '',
            'sort_order' => 10, 'is_placeholder' => true, 'status' => 'draft', 'version' => 1,
        ], $overrides));
    }

    private function admin(string $username = 'reviewer', string $role = 'super_admin'): Admin
    {
        return Admin::query()->firstOrCreate(['username' => $username], [
            'password' => 'secret-123', 'email' => $username.'@example.com', 'display_name' => $username,
            'role' => $role, 'status' => 'active',
        ]);
    }

    private function bookingForm(): LeadForm
    {
        return LeadForm::query()->create([
            'name' => '预约', 'slug' => (string) config('zeropoint.booking_form_slug'), 'status' => LeadForm::STATUS_ACTIVE,
            'fields' => [
                ['name' => 'name', 'label' => '称呼', 'type' => 'text', 'required' => true, 'options' => []],
                ['name' => 'phone', 'label' => '联系电话', 'type' => 'phone', 'required' => true, 'options' => []],
                ['name' => 'service_interest', 'label' => '到店意向', 'type' => 'select', 'required' => true, 'options' => ['一般咨询（暂不指定项目）']],
                ['name' => 'preferred_date', 'label' => '期望日期', 'type' => 'date', 'required' => true, 'options' => []],
                ['name' => 'preferred_period', 'label' => '期望时段', 'type' => 'select', 'required' => true, 'options' => ['下午 13:00—17:00']],
                ['name' => 'contact_consent', 'label' => '联系授权', 'type' => 'checkbox', 'required' => true, 'options' => ['同意联系']],
                ['name' => 'privacy_consent', 'label' => '隐私确认', 'type' => 'checkbox', 'required' => true, 'options' => ['已了解']],
            ],
        ]);
    }
}
