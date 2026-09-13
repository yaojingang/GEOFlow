<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DistributionChannel;
use App\Services\HostedSites\HostedSitePermalinkService;
use App\Support\Site\ArticlePermalinkCsv;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class HostedSitePermalinkController extends Controller
{
    public function __construct(private readonly HostedSitePermalinkService $permalinks) {}

    public function preview(Request $request, DistributionChannel $hostedSite): RedirectResponse
    {
        $payload = $request->validate(['pattern' => ['required', 'string', 'max:160']]);
        try {
            $preview = $this->permalinks->inspect($hostedSite, (string) $payload['pattern']);
        } catch (InvalidArgumentException|ValidationException $exception) {
            throw ValidationException::withMessages(['pattern' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['pattern' => __('article_permalink.errors.hosted_preview_unavailable')]);
        }
        if ($preview['conflicts'] !== []) {
            throw ValidationException::withMessages(['pattern' => $preview['conflicts']]);
        }

        $preview['credential'] = Crypt::encryptString(json_encode([
            'admin_id' => (int) $request->user('admin')->id,
            'channel_id' => (int) $hostedSite->id,
            'revision' => $preview['revision'],
            'current_pattern' => $preview['current_pattern'],
            'pattern' => $preview['pattern'],
            'affected_articles' => $preview['affected_articles'],
            'expires_at' => now()->addMinutes(15)->timestamp,
        ], JSON_THROW_ON_ERROR));

        return redirect()->route('admin.distribution.hosted-sites.edit', $hostedSite)
            ->with('hosted_article_permalink_preview', $preview)
            ->with('message', __('article_permalink.messages.hosted_preview_ready'));
    }

    public function activate(Request $request, DistributionChannel $hostedSite): RedirectResponse
    {
        $payload = $request->validate(['preview_credential' => ['required', 'string', 'max:4096']]);
        try {
            $credential = json_decode(Crypt::decryptString((string) $payload['preview_credential']), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['pattern' => __('article_permalink.errors.hosted_credential_invalid')]);
        }
        if (! is_array($credential)
            || (int) ($credential['admin_id'] ?? 0) !== (int) $request->user('admin')->id
            || (int) ($credential['channel_id'] ?? 0) !== (int) $hostedSite->id
            || (int) ($credential['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['pattern' => __('article_permalink.errors.hosted_credential_expired')]);
        }
        $currentPolicy = $this->permalinks->policy($hostedSite);
        if ($currentPolicy->currentPattern !== (string) ($credential['current_pattern'] ?? '')) {
            throw ValidationException::withMessages(['pattern' => __('article_permalink.errors.hosted_policy_changed')]);
        }

        $policy = $this->permalinks->activate(
            $hostedSite,
            (string) ($credential['pattern'] ?? ''),
            (int) ($credential['revision'] ?? -1),
        );
        $request->request->remove('preview_credential');
        $request->attributes->set('admin_activity_action', 'activate');
        $request->attributes->set('admin_activity_details', [
            'site' => 'hosted',
            'channel_id' => (int) $hostedSite->id,
            'old_pattern' => (string) ($credential['current_pattern'] ?? ''),
            'new_pattern' => $policy->currentPattern,
            'revision' => $policy->revision,
            'affected_articles' => (int) ($credential['affected_articles'] ?? 0),
            'success' => true,
        ]);

        return redirect()->route('admin.distribution.hosted-sites.edit', $hostedSite)
            ->with('message', __('article_permalink.messages.hosted_activated', ['revision' => $policy->revision]));
    }

    public function migrationMap(Request $request, DistributionChannel $hostedSite): StreamedResponse
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
            throw ValidationException::withMessages(['pattern' => __('article_permalink.errors.hosted_migration_preview_required')]);
        }
        $currentPolicy = $this->permalinks->policy($hostedSite);
        if (! is_array($credential)
            || (int) ($credential['admin_id'] ?? 0) !== (int) $request->user('admin')->id
            || (int) ($credential['channel_id'] ?? 0) !== (int) $hostedSite->id
            || (int) ($credential['expires_at'] ?? 0) < now()->timestamp
            || (int) ($credential['revision'] ?? -1) !== $currentPolicy->revision
            || (string) ($credential['current_pattern'] ?? '') !== $currentPolicy->currentPattern) {
            throw ValidationException::withMessages(['pattern' => __('article_permalink.errors.hosted_migration_credential_expired')]);
        }

        $rows = $this->permalinks->migrationRows($hostedSite, (string) ($credential['pattern'] ?? ''));
        $hostedSite->loadMissing('hostedSiteProfile');
        $baseUrl = 'https://'.(string) $hostedSite->hostedSiteProfile?->hostname;

        return response()->streamDownload(function () use ($rows, $baseUrl): void {
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
                    ArticlePermalinkCsv::cell($baseUrl.$row['old_path']),
                    ArticlePermalinkCsv::cell($baseUrl.$row['new_path']),
                    ArticlePermalinkCsv::cell($row['change_reason']),
                ], ',', '"', '');
            }
            fclose($output);
        }, 'hosted-article-url-migration.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
