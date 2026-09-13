<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Site\ArticlePermalinkService;
use App\Services\Site\SiteUrlGenerator;
use App\Support\Site\ArticlePermalinkCsv;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ArticlePermalinkController extends Controller
{
    public function __construct(
        private readonly ArticlePermalinkService $articlePermalinks,
        private readonly SiteUrlGenerator $urls,
    ) {}

    public function preview(Request $request): RedirectResponse
    {
        $payload = $request->validate(['pattern' => ['required', 'string', 'max:160']]);

        try {
            $inspection = $this->articlePermalinks->inspect((string) $payload['pattern']);
        } catch (InvalidArgumentException|ValidationException $exception) {
            throw ValidationException::withMessages(['pattern' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['pattern' => __('article_permalink.errors.preview_unavailable')]);
        }
        if ($inspection['conflicts'] !== []) {
            throw ValidationException::withMessages(['pattern' => $inspection['conflicts']]);
        }

        $inspection['credential'] = Crypt::encryptString(json_encode([
            'admin_id' => (int) $request->user('admin')->id,
            'revision' => $inspection['revision'],
            'current_pattern' => $inspection['current_pattern'],
            'pattern' => $inspection['pattern'],
            'affected_articles' => $inspection['affected_articles'],
            'expires_at' => now()->addMinutes(15)->timestamp,
        ], JSON_THROW_ON_ERROR));

        return redirect()->route('admin.site-settings.index')
            ->with('article_permalink_preview', $inspection)
            ->with('message', __('article_permalink.messages.preview_ready'));
    }

    public function activate(Request $request): RedirectResponse
    {
        $payload = $request->validate(['preview_credential' => ['required', 'string', 'max:4096']]);
        try {
            $credential = json_decode(
                Crypt::decryptString((string) $payload['preview_credential']),
                true,
                16,
                JSON_THROW_ON_ERROR,
            );
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['pattern' => __('article_permalink.errors.credential_invalid')]);
        }

        if (! is_array($credential)
            || (int) ($credential['admin_id'] ?? 0) !== (int) $request->user('admin')->id
            || (int) ($credential['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['pattern' => __('article_permalink.errors.credential_expired')]);
        }

        $policy = $this->articlePermalinks->policy();
        if ($policy->currentPattern !== (string) ($credential['current_pattern'] ?? '')) {
            throw ValidationException::withMessages(['pattern' => __('article_permalink.errors.policy_changed')]);
        }

        $nextPolicy = $this->articlePermalinks->activatePrimary(
            (string) ($credential['pattern'] ?? ''),
            (int) ($credential['revision'] ?? -1),
        );
        $request->request->remove('preview_credential');
        $request->attributes->set('admin_activity_action', 'activate');
        $request->attributes->set('admin_activity_details', [
            'site' => 'primary',
            'old_pattern' => (string) ($credential['current_pattern'] ?? ''),
            'new_pattern' => $nextPolicy->currentPattern,
            'revision' => $nextPolicy->revision,
            'affected_articles' => (int) ($credential['affected_articles'] ?? 0),
            'success' => true,
        ]);

        return redirect()->route('admin.site-settings.index')
            ->with('message', __('article_permalink.messages.activated', ['revision' => $nextPolicy->revision]));
    }

    public function migrationMap(Request $request): StreamedResponse
    {
        $payload = $request->validate(['preview_credential' => ['required', 'string', 'max:4096']]);
        try {
            $credential = json_decode(
                Crypt::decryptString((string) $payload['preview_credential']),
                true,
                16,
                JSON_THROW_ON_ERROR,
            );
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['pattern' => __('article_permalink.errors.migration_preview_required')]);
        }
        $currentPolicy = $this->articlePermalinks->policy();
        if (! is_array($credential)
            || (int) ($credential['admin_id'] ?? 0) !== (int) $request->user('admin')->id
            || (int) ($credential['expires_at'] ?? 0) < now()->timestamp
            || (int) ($credential['revision'] ?? -1) !== $currentPolicy->revision
            || (string) ($credential['current_pattern'] ?? '') !== $currentPolicy->currentPattern) {
            throw ValidationException::withMessages(['pattern' => __('article_permalink.errors.migration_credential_expired')]);
        }

        $previewPolicy = $currentPolicy->activate((string) ($credential['pattern'] ?? ''));
        $rows = $this->articlePermalinks->migrationRows($previewPolicy);

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['article_id', 'title', 'old_url', 'new_url', 'change_reason'], ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($output, [
                    ArticlePermalinkCsv::cell($row['article_id']),
                    ArticlePermalinkCsv::cell($row['title']),
                    ArticlePermalinkCsv::cell($this->urls->url($row['old_path'])),
                    ArticlePermalinkCsv::cell($this->urls->url($row['new_path'])),
                    ArticlePermalinkCsv::cell($row['change_reason']),
                ], ',', '"', '');
            }
            fclose($output);
        }, 'article-url-migration.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
