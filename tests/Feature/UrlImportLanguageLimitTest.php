<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UrlImportLanguageLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_url_import_language_selector_only_shows_chinese_english_and_auto_detect(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.url-import'))
            ->assertOk()
            ->assertSee(__('admin.url_import.option.auto_detect'))
            ->assertSee(__('admin.url_import.language.zh_cn'))
            ->assertSee(__('admin.url_import.language.en'))
            ->assertDontSee('value="es"', false)
            ->assertDontSee('value="pt"', false)
            ->assertDontSee('value="ja"', false)
            ->assertDontSee('value="ru"', false);
    }

    public function test_url_import_rejects_removed_language_values(): void
    {
        $admin = $this->createAdmin();

        foreach (['es', 'pt', 'pt-BR', 'ja', 'ru'] as $locale) {
            $this->actingAs($admin, 'admin')
                ->from(route('admin.url-import'))
                ->post(route('admin.url-import.store'), [
                    'url' => 'https://example.com/product',
                    'content_language' => $locale,
                ])
                ->assertRedirect(route('admin.url-import'))
                ->assertSessionHasErrors('content_language');
        }
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'url_import_language_admin',
            'password' => 'secret-123',
            'email' => 'url-import-language@example.com',
            'display_name' => 'URL Import Language Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
