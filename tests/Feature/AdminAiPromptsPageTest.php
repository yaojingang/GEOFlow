<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Prompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAiPromptsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_content_prompts_are_visible(): void
    {
        $admin = Admin::query()->create([
            'username' => 'ai_prompt_admin',
            'password' => 'secret-123',
            'email' => 'ai-prompt-admin@example.com',
            'display_name' => 'AI Prompt Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-prompts'))
            ->assertOk()
            ->assertSee('GEO营销学·信任型正文生成')
            ->assertSee('GEO榜单型正文生成')
            ->assertSee('GEO Marketing · Trust-Based Article Generation (English)')
            ->assertSee('GEO Ranking-Style Article Generation (English)');
    }

    public function test_content_prompt_creation_uses_a_standalone_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'ai_prompt_create_admin',
            'password' => 'secret-123',
            'email' => 'ai-prompt-create-admin@example.com',
            'display_name' => 'AI Prompt Create Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-prompts'))
            ->assertOk()
            ->assertSee('href="'.route('admin.ai-prompts.create').'"', false)
            ->assertDontSee('showCreatePromptModal()', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-prompts.create'))
            ->assertOk()
            ->assertSee(__('admin.ai_prompts.modal_create'))
            ->assertSee('action="'.route('admin.ai-prompts.store').'"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="content"', false)
            ->assertDontSee('id="promptModal"', false);
    }

    public function test_admin_can_store_a_content_prompt_from_the_standalone_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'ai_prompt_store_admin',
            'password' => 'secret-123',
            'email' => 'ai-prompt-store-admin@example.com',
            'display_name' => 'AI Prompt Store Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-prompts.store'), [
                'name' => '证据型正文生成',
                'content' => '请根据 {{title}} 和 {{Knowledge}} 生成正文。',
            ])
            ->assertRedirect(route('admin.ai-prompts'));

        $this->assertDatabaseHas(Prompt::class, [
            'name' => '证据型正文生成',
            'type' => 'content',
            'content' => '请根据 {{title}} 和 {{Knowledge}} 生成正文。',
        ]);
    }

    public function test_content_prompt_editing_uses_an_authenticated_standalone_page(): void
    {
        $prompt = Prompt::query()->create([
            'name' => '证据型正文生成',
            'type' => 'content',
            'content' => '请根据 {{title}} 生成正文。',
            'variables' => '',
        ]);

        $this->get(route('admin.ai-prompts.edit', ['promptId' => $prompt->id]))
            ->assertRedirect(route('admin.login'));

        $admin = Admin::query()->create([
            'username' => 'ai_prompt_edit_admin',
            'password' => 'secret-123',
            'email' => 'ai-prompt-edit-admin@example.com',
            'display_name' => 'AI Prompt Edit Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $indexResponse = $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-prompts'))
            ->assertOk()
            ->assertSee('href="'.route('admin.ai-prompts.edit', ['promptId' => $prompt->id]).'"', false)
            ->assertDontSee('id="promptModal"', false)
            ->assertDontSee('editPrompt(', false);

        $this->assertSame(
            1,
            substr_count($indexResponse->getContent(), 'href="'.route('admin.ai-prompts.edit', ['promptId' => $prompt->id]).'"')
        );

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-prompts.edit', ['promptId' => $prompt->id]))
            ->assertOk()
            ->assertViewIs('admin.ai-prompts.edit')
            ->assertSee('action="'.route('admin.ai-prompts.update', ['promptId' => $prompt->id]).'"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('value="证据型正文生成"', false)
            ->assertSee('请根据 {{title}} 生成正文。')
            ->assertDontSee('id="promptModal"', false);
    }

    public function test_content_prompt_update_keeps_the_existing_form_contract(): void
    {
        $prompt = Prompt::query()->create([
            'name' => '旧提示词',
            'type' => 'content',
            'content' => '旧内容',
            'variables' => '',
        ]);

        $admin = Admin::query()->create([
            'username' => 'ai_prompt_update_admin',
            'password' => 'secret-123',
            'email' => 'ai-prompt-update-admin@example.com',
            'display_name' => 'AI Prompt Update Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.ai-prompts.edit', ['promptId' => $prompt->id]))
            ->put(route('admin.ai-prompts.update', ['promptId' => $prompt->id]), [
                'name' => '新提示词',
                'content' => '新内容 {{Knowledge}}',
            ])
            ->assertRedirect(route('admin.ai-prompts'))
            ->assertSessionHas('message');

        $prompt->refresh();
        $this->assertSame('新提示词', $prompt->name);
        $this->assertSame('新内容 {{Knowledge}}', $prompt->content);
        $this->assertSame('content', $prompt->type);
    }

    public function test_content_prompt_form_renders_after_array_shaped_old_input(): void
    {
        $admin = Admin::query()->create([
            'username' => 'ai_prompt_array_admin',
            'password' => 'secret-123',
            'email' => 'ai-prompt-array-admin@example.com',
            'display_name' => 'AI Prompt Array Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.ai-prompts.create'))
            ->post(route('admin.ai-prompts.store'), [
                'name' => ['unexpected'],
                'content' => ['unexpected'],
            ])
            ->assertRedirect(route('admin.ai-prompts.create'))
            ->assertSessionHasErrors(['name', 'content']);

        $this->get(route('admin.ai-prompts.create'))
            ->assertOk()
            ->assertSee('action="'.route('admin.ai-prompts.store').'"', false);
    }

    public function test_content_prompt_id_routes_reject_non_numeric_parameters(): void
    {
        $admin = Admin::query()->create([
            'username' => 'ai_prompt_route_admin',
            'password' => 'secret-123',
            'email' => 'ai-prompt-route-admin@example.com',
            'display_name' => 'AI Prompt Route Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->actingAs($admin, 'admin');

        $this->get(route('admin.ai-prompts.edit', ['promptId' => 'not-a-number']))->assertNotFound();
        $this->put(route('admin.ai-prompts.update', ['promptId' => 'not-a-number']))->assertNotFound();
        $this->post(route('admin.ai-prompts.delete', ['promptId' => 'not-a-number']))->assertNotFound();
    }

    public function test_admin_can_create_a_quality_inspection_prompt_and_system_prompt_is_read_only(): void
    {
        $admin = Admin::query()->create([
            'username' => 'quality_prompt_admin',
            'password' => 'secret-123',
            'email' => 'quality-prompt-admin@example.com',
            'display_name' => 'Quality Prompt Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-prompts.store'), [
                'name' => '数据一致性质检',
                'type' => 'quality_check',
                'content' => '检查 {{article_title}}、{{article_content}} 与 {{knowledge}}，输出结构化问题。',
            ])
            ->assertRedirect(route('admin.ai-prompts'));

        $this->assertDatabaseHas(Prompt::class, [
            'name' => '数据一致性质检',
            'type' => 'quality_check',
        ]);

        $systemPrompt = Prompt::query()->whereNotNull('system_key')->firstOrFail();
        $this->get(route('admin.ai-prompts.edit', ['promptId' => $systemPrompt->id]))
            ->assertOk()
            ->assertSee('系统内置质检方案')
            ->assertDontSee('data-lucide="save"', false);

        $this->put(route('admin.ai-prompts.update', ['promptId' => $systemPrompt->id]), [
            'name' => '被修改',
            'content' => '被修改',
        ])->assertSessionHasErrors();
        $this->assertNotSame('被修改', $systemPrompt->fresh()->name);
    }

    public function test_admin_can_copy_the_system_quality_prompt_into_an_editable_plan(): void
    {
        $admin = Admin::query()->create([
            'username' => 'quality_prompt_copy_admin',
            'password' => 'secret-123',
            'email' => 'quality-prompt-copy@example.com',
            'display_name' => 'Quality Prompt Copy Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $systemPrompt = Prompt::query()->whereNotNull('system_key')->firstOrFail();

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-prompts.copy', ['promptId' => $systemPrompt->id]));

        $copy = Prompt::query()
            ->where('type', 'quality_check')
            ->whereNull('system_key')
            ->latest('id')
            ->firstOrFail();
        $response->assertRedirect(route('admin.ai-prompts.edit', ['promptId' => $copy->id]));
        $this->assertSame($systemPrompt->content, $copy->content);
        $this->assertStringContainsString('副本', $copy->name);
    }
}
