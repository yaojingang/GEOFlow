<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminPageDescriptionPunctuationTest extends TestCase
{
    public function test_requested_admin_page_descriptions_do_not_end_with_periods(): void
    {
        foreach (['zh_CN', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ([
                'admin.articles',
                'admin.materials',
                'admin.admin_users',
                'admin.site_settings',
            ] as $translationRoot) {
                $copy = trans($translationRoot);

                $this->assertIsArray($copy);
                $this->assertStringNotContainsString('�', json_encode($copy, JSON_UNESCAPED_UNICODE));
                $this->assertDescriptionCopyDoesNotEndWithPeriods($copy, $translationRoot);
            }

            foreach ([
                'admin.theme_replication.entry_desc',
                'admin.theme_replication.deployment.readonly_hint',
            ] as $translationKey) {
                $value = trans($translationKey);

                $this->assertIsString($value);
                $this->assertStringNotContainsString('�', $value);
                $this->assertDoesNotEndWithSentencePeriod($value, $translationKey);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $copy
     */
    private function assertDescriptionCopyDoesNotEndWithPeriods(array $copy, string $path): void
    {
        foreach ($copy as $key => $value) {
            $nextPath = $path.'.'.$key;

            if (is_array($value)) {
                $this->assertDescriptionCopyDoesNotEndWithPeriods($value, $nextPath);

                continue;
            }

            if (! is_string($value) || ! $this->isDescriptionLikeKey((string) $key)) {
                continue;
            }

            $this->assertDoesNotEndWithSentencePeriod($value, $nextPath);
        }
    }

    private function isDescriptionLikeKey(string $key): bool
    {
        return $key === 'desc'
            || $key === 'subtitle'
            || $key === 'page_subtitle'
            || str_ends_with($key, '_desc')
            || str_ends_with($key, '_description')
            || str_ends_with($key, '_summary')
            || str_ends_with($key, '_subtitle')
            || str_ends_with($key, '_help')
            || str_ends_with($key, '_notice')
            || str_ends_with($key, '_hint');
    }

    private function assertDoesNotEndWithSentencePeriod(string $value, string $path): void
    {
        $this->assertFalse(
            str_ends_with($value, '。') || str_ends_with($value, '.'),
            "{$path} should not end with sentence punctuation."
        );
    }
}
