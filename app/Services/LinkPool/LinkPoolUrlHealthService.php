<?php

namespace App\Services\LinkPool;

use App\Jobs\CheckLinkPoolTargetUrl;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

final class LinkPoolUrlHealthService
{
    public function __construct(private readonly SafeOutboundHttpClient $http) {}

    public function enqueue(int $targetId): void
    {
        $target = DB::table('link_pool_targets')->find($targetId);
        if ($target === null || ! LinkPoolTargetService::isExternal($target)) {
            return;
        }
        DB::table('link_pool_targets')->where('id', $targetId)->update(['health_status' => 'pending']);
        $job = (new CheckLinkPoolTargetUrl($targetId, (string) $target->url_sha256))->onQueue('default')->afterCommit();
        DB::afterCommit(function () use ($job, $targetId, $target): void {
            $lock = new UniqueLock(app(Repository::class));
            $acquired = false;
            try {
                $acquired = $lock->acquire($job);
                if ($acquired) {
                    Bus::dispatch($job);
                }
            } catch (Throwable $exception) {
                if ($acquired) {
                    try {
                        $lock->release($job);
                    } catch (Throwable $releaseException) {
                        report($releaseException);
                    }
                }
                report($exception);
                DB::table('link_pool_targets')->where('id', $targetId)->where('url_sha256', $target->url_sha256)->update([
                    'health_status' => 'unchecked', 'health_http_status' => null,
                    'health_message' => '链接已保存，访问检查暂时无法排队，请稍后重新检查。', 'health_checked_at' => now(),
                ]);
            }
        });
    }

    public function check(int $targetId, string $urlHash): void
    {
        $target = DB::table('link_pool_targets')->where('id', $targetId)->where('url_sha256', $urlHash)->first();
        if ($target === null || ! LinkPoolTargetService::isExternal($target)) {
            return;
        }
        $status = null;
        try {
            $url = explode('#', $target->input_url, 2)[0];
            $response = $this->http->get(Http::connectTimeout(2)->timeout(5)->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'GEOFlow-Link-Check', 'Accept' => 'text/html,*/*;q=0.8']), $url, 2 * 1024 * 1024, 3);
            $status = $response->status();
            $health = $response->successful() ? 'reachable' : (in_array($status, [401, 403, 429], true) ? 'restricted' : 'unreachable');
            $message = $response->successful() ? '可以访问（HTTP '.$status.'）'
                : (in_array($status, [401, 403, 429], true) ? '网站限制自动检查（HTTP '.$status.'），可人工打开核对。' : '网站返回 HTTP '.$status.'，请检查链接。');
        } catch (OutboundRequestBlockedException $exception) {
            $health = 'unchecked';
            $message = match ($exception->reasonCode) {
                'dns_resolution_failed' => '域名暂时无法解析，请稍后重试。',
                'redirect_limit_exceeded' => '跳转次数过多，请人工打开核对。',
                'response_too_large' => '页面超过检查大小上限，请人工打开核对。',
                default => '访问检查受安全策略限制，可人工打开核对。',
            };
        } catch (Throwable) {
            $health = 'unchecked';
            $message = '连接超时或网络连接失败，请稍后重试。';
        }
        DB::table('link_pool_targets')->where('id', $targetId)->where('url_sha256', $urlHash)->update([
            'health_status' => $health, 'health_http_status' => $status,
            'health_message' => $message, 'health_checked_at' => now(),
        ]);
    }
}
