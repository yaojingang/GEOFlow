<?php

namespace Tests\Unit;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPtBrLocaleCoverageTest extends TestCase
{
    public function test_pt_br_covers_all_current_admin_translation_keys(): void
    {
        $english = require lang_path('en/admin.php');
        $overlay = $this->loadLocaleOverlay('pt_BR');

        $missingKeys = array_values(array_diff(
            $this->flattenLeafKeys($english),
            $this->flattenLeafKeys($overlay)
        ));

        $this->assertSame([], $missingKeys, 'pt_BR is missing admin translation keys: '.implode(', ', $missingKeys));
    }

    public function test_pt_br_overrides_admin_keys_used_by_current_backend_modules(): void
    {
        $english = require lang_path('en/admin.php');
        $overlay = $this->loadLocaleOverlay('pt_BR');

        $requiredKeys = [
            'nav.analytics',
            'nav.distribution',
            'nav.api_tokens',
            'header.notifications.title',
            'header.notifications.update_available',
            'common.not_found_desc',
            'footer.copyright',
            'dashboard.navigation.heading',
            'dashboard.navigation.analytics_title',
            'dashboard.skill_resources.title',
            'analytics.heading',
            'analytics.filters.apply',
            'analytics.logs_title',
            'analytics.logs_bot.ai_bot',
            'distribution.page_heading',
            'distribution.field.front_mode',
            'distribution.button.update_target_site',
            'distribution.front_mode.static',
            'distribution.message.remote_article_deleted',
        ];

        $sameValueAllowed = [
            'footer.copyright',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertTrue(Arr::has($overlay, $key), "pt_BR must explicitly override [{$key}].");

            $value = data_get($overlay, $key);
            $this->assertIsString($value, "pt_BR override [{$key}] must be a string.");
            $this->assertNotSame('', trim($value), "pt_BR override [{$key}] must not be empty.");

            if (! in_array($key, $sameValueAllowed, true)) {
                $this->assertNotSame(
                    data_get($english, $key),
                    $value,
                    "pt_BR override [{$key}] must not fall back to the English copy."
                );
            }
        }
    }

    /**
     * Load only the locale override array from files that normally merge with
     * the English base file.
     *
     * @return array<string, mixed>
     */
    private function loadLocaleOverlay(string $locale): array
    {
        $source = File::get(lang_path("{$locale}/admin.php"));
        $directory = storage_path('framework/testing/locale-overlay-'.Str::random(12));

        File::ensureDirectoryExists($directory.'/en');
        File::ensureDirectoryExists($directory.'/'.$locale);
        File::put($directory.'/en/admin.php', "<?php\n\nreturn [];\n");
        File::put($directory.'/'.$locale.'/admin.php', $source);

        try {
            /** @var array<string, mixed> $overlay */
            $overlay = require $directory.'/'.$locale.'/admin.php';

            return $overlay;
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<int, string>
     */
    private function flattenLeafKeys(array $values, string $prefix = ''): array
    {
        $keys = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenLeafKeys($value, $path));
            } else {
                $keys[] = $path;
            }
        }

        return $keys;
    }
}
