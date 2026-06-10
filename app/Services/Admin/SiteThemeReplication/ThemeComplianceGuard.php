<?php

namespace App\Services\Admin\SiteThemeReplication;

use Illuminate\Support\Facades\Storage;

class ThemeComplianceGuard
{
    /**
     * @param  array<string, mixed>  $files
     * @return array<string, mixed>
     */
    public function scan(array $files): array
    {
        $violations = [];
        foreach ((array) ($files['files'] ?? []) as $file) {
            $relative = (string) ($file['path'] ?? '');
            $storagePath = (string) ($file['storage_path'] ?? '');
            $content = Storage::disk('local')->exists($storagePath)
                ? (string) Storage::disk('local')->get($storagePath)
                : '';

            foreach ($this->scanContent($relative, $content) as $violation) {
                $violations[] = $violation;
            }
        }

        return [
            'passed' => $violations === [],
            'violations' => $violations,
            'scanned_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return list<array{path:string,rule:string,message:string}>
     */
    private function scanContent(string $path, string $content): array
    {
        $violations = [];
        $rules = [
            'php_open_tag' => '/<\?php/i',
            'blade_php' => '/@php\b/i',
            'dangerous_php' => '/\b(eval|shell_exec|system|passthru|proc_open|file_put_contents)\s*\(|\b(DB|Http)::/i',
            'external_script' => '/<script\b[^>]*\bsrc=["\']https?:\/\//i',
            'external_js_request' => '/\b(fetch|importScripts)\s*\(\s*["\']https?:\/\//i',
            'external_image' => '/<img\b[^>]*\bsrc=["\']https?:\/\//i',
            'external_css_import' => '/@import\s+(?:url\(\s*)?["\']?https?:\/\//i',
            'external_css_url' => '/url\(\s*["\']?https?:\/\//i',
        ];

        foreach ($rules as $rule => $pattern) {
            if (preg_match($pattern, $content)) {
                $violations[] = [
                    'path' => $path,
                    'rule' => $rule,
                    'message' => __('admin.theme_replication.error.compliance_violation', [
                        'path' => $path,
                        'rule' => $rule,
                    ]),
                ];
            }
        }

        return $violations;
    }
}
