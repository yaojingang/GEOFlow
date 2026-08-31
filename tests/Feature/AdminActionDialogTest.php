<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class AdminActionDialogTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_action_dialog_is_rendered_once_in_v3_and_legacy_layouts(): void
    {
        $admin = Admin::query()->create([
            'username' => 'action_dialog_owner',
            'password' => 'secret-123',
            'email' => 'action-dialog@example.com',
            'display_name' => 'Action Dialog Owner',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        foreach ([true, false] as $v3Enabled) {
            config()->set('geoflow.admin_ui_v3_enabled', $v3Enabled);
            $html = $this->actingAs($admin, 'admin')
                ->withSession(['message' => 'Saved centrally'])
                ->get(route('admin.dashboard'))
                ->assertOk()
                ->getContent();

            self::assertSame(1, substr_count($html, 'data-admin-action-dialog'));
            self::assertSame(1, substr_count($html, 'data-admin-action-notice-region'));
            self::assertStringContainsString('Saved centrally', $html);
            self::assertStringNotContainsString('admin-flash-alert', $html);
        }
    }

    public function test_structured_notice_keeps_only_safe_internal_action_urls(): void
    {
        $unsafe = $this->withSession([
            'admin_action_notice' => [
                'tone' => 'success',
                'title' => 'Done',
                'message' => 'Updated',
                'action_label' => 'Open',
                'action_url' => 'https://example.com/redirect',
            ],
        ])->view('components.admin.action-dialog');

        $unsafe->assertDontSee('https://example.com/redirect', false);

        $backslashUnsafe = $this->withSession([
            'admin_action_notice' => [
                'tone' => 'success',
                'title' => 'Done',
                'message' => 'Updated',
                'action_label' => 'Open',
                'action_url' => '/\\evil.test',
            ],
        ])->view('components.admin.action-dialog');

        $backslashUnsafe->assertDontSee('/\\evil.test', false);

        $safe = $this->withSession([
            'admin_action_notice' => [
                'tone' => 'success',
                'title' => 'Done',
                'message' => 'Updated',
                'action_label' => 'Open',
                'action_url' => '/geo_admin/tasks',
            ],
        ])->view('components.admin.action-dialog');

        $safe->assertSee('/geo_admin/tasks', false);
    }

    public function test_structured_notice_is_safe_inside_the_json_script_element(): void
    {
        $payload = '</script><script>window.noticeInjected=true</script>';

        $view = $this->withSession([
            'admin_action_notice' => [
                'tone' => 'error',
                'title' => 'Failed',
                'message' => $payload,
            ],
        ])->view('components.admin.action-dialog');

        $view->assertDontSee($payload, false);
        $view->assertSee('\\u003C/script\\u003E', false);
    }

    public function test_dialog_common_copy_exists_in_all_supported_locales(): void
    {
        foreach (['zh_CN', 'en', 'ja', 'es', 'ru', 'pt_BR'] as $locale) {
            App::setLocale($locale);
            foreach ([
                'cancel', 'close', 'confirm', 'success_title', 'error_title', 'error_guidance', 'target',
                'hosted_site.activate_title', 'hosted_site.pause_title', 'hosted_site.maintenance_title',
                'article_ai_optimization.start_title', 'article_ai_optimization.start_message',
                'article_ai_optimization.apply_title', 'article_ai_optimization.apply_message',
                'article_ai_optimization.cancel_title', 'article_ai_optimization.discard_title',
                'article_ai_optimization.rollback_title', 'article_ai_optimization.rollback_message',
                'article_ai_quality.run_title', 'article_ai_quality.run_message',
                'article_ai_quality.override_title', 'article_ai_quality.override_message',
            ] as $key) {
                self::assertNotSame('admin.action_dialog.'.$key, __('admin.action_dialog.'.$key), $locale.': '.$key);
            }
        }
    }

    public function test_admin_business_sources_do_not_use_native_browser_dialogs(): void
    {
        $roots = [
            resource_path('views/admin'),
            resource_path('views/components/admin'),
            resource_path('js/admin'),
        ];
        $pattern = '/(?<![.\w])(?:window\.|globalThis\.)?(?:confirm|alert|prompt)\s*\(/u';
        $violations = [];
        $beforeUnloadCount = 0;

        foreach ($roots as $root) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($files as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }
                if (! in_array($file->getExtension(), ['js', 'php'], true)) {
                    continue;
                }
                if ($file->getPathname() === resource_path('js/admin/action-dialog.js')) {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                if (preg_match($pattern, $source) === 1) {
                    $violations[] = str_replace(base_path().'/', '', $file->getPathname());
                }
                $beforeUnloadCount += substr_count($source, "addEventListener('beforeunload'");
            }
        }

        self::assertSame([], $violations);
        self::assertSame(2, $beforeUnloadCount);
    }

    public function test_static_confirmation_forms_declare_the_complete_dialog_copy_contract(): void
    {
        $root = resource_path('views/admin');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $forms = [];

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/\bdata-admin-confirm-form\b/u', $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [, $offset]) {
                $start = strrpos(substr($source, 0, $offset), '<form');
                $end = strpos($source, '</form>', $offset);
                self::assertIsInt($start);
                self::assertIsInt($end);
                $form = substr($source, $start, $end + strlen('</form>') - $start);
                $forms[] = str_replace(base_path().'/', '', $file->getPathname()).': '.$form;
                self::assertStringContainsString('data-admin-confirm-title=', $form);
                self::assertStringContainsString('data-admin-confirm-message=', $form);
                self::assertStringContainsString('data-admin-confirm-label=', $form);
                self::assertStringContainsString('data-admin-confirm-tone=', $form);
            }
        }

        self::assertGreaterThanOrEqual(37, count($forms));
    }
}
