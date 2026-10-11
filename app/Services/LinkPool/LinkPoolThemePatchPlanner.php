<?php

namespace App\Services\LinkPool;

use App\Ai\Agents\LinkPoolThemeAnchorAgent;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\AiModelRuntimeEligibilityException;
use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Services\AiWorkspace\AiWorkspaceModelUnavailableException;
use App\Services\GeoFlow\DirectAdminAiExecutionGuard;
use App\Services\GeoFlow\DirectAdminAiModelInvocationGateway;
use App\Services\Site\LinkPoolSiteService;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Laravel\Ai\Responses\StructuredAgentResponse;

final class LinkPoolThemePatchPlanner
{
    public function __construct(
        private readonly AdminAiModelAccessResolver $models,
        private readonly ApiKeyCrypto $crypto,
        private readonly DirectAdminAiExecutionGuard $guard,
        private readonly DirectAdminAiModelInvocationGateway $gateway,
    ) {}

    /** @param array<string,string> $files @return array{files:array<string,string>,model_id:?int} */
    public function patch(array $files, string $themeId, Admin $admin): array
    {
        $path = 'resources/views/theme/'.$themeId.'/article.blade.php';
        if (! isset($files[$path])) {
            $path = 'resources/views/site/article.blade.php';
        }
        $source = $files[$path] ?? null;
        if (! is_string($source)) {
            throw new ApiException('theme_article_missing', '当前主题缺少可验证的文章模板', 422);
        }
        if (LinkPoolSiteService::templateSupportsRecommendations($source)) {
            throw new ApiException('theme_already_adapted', '当前主题已经接入推荐区', 409);
        }

        $anchor = $this->ruleAnchor($source);
        $modelId = null;
        if ($anchor === null) {
            [$anchor, $modelId] = $this->aiAnchor($source, $admin);
        }
        if ($anchor === '' || substr_count($source, $anchor) !== 1 || ! $this->safePosition($source, $anchor)) {
            throw new ApiException('theme_anchor_ambiguous', '无法确定推荐区插入位置，请检查主题文章模板', 422);
        }
        $files[$path] = str_replace($anchor, $anchor."\n    @include('site.partials.link-pool')", $source);

        return ['files' => $files, 'model_id' => $modelId];
    }

    private function ruleAnchor(string $source): ?string
    {
        $visible = $this->visibleSource($source);
        if (preg_match_all('/相关条目|相关文章/u', $visible, $headings, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $heading = $headings[0][0][1];
        preg_match_all('/<\/(?:section|div|aside)>\s*@endif\b/', substr($visible, $heading, 5000), $endings, PREG_OFFSET_CAPTURE);
        foreach ($endings[0] as [$ending, $offset]) {
            $anchor = substr($source, $heading + $offset, strlen($ending));
            if (substr_count($source, $anchor) === 1 && $this->safePosition($source, $anchor)) {
                return $anchor;
            }
        }

        return null;
    }

    private function safePosition(string $source, string $anchor): bool
    {
        $position = strpos($source, $anchor);
        $visible = $this->visibleSource($source);
        if ($position === false || substr($visible, $position, strlen($anchor)) !== $anchor
            || preg_match('/^<\/(?:section|div|aside)>\s*(?:@endif\s*)?$/D', $anchor) !== 1) {
            return false;
        }
        $insertion = $position + strlen($anchor);
        $endSection = strrpos($visible, '@endsection');
        if ($endSection === false || $insertion >= $endSection
            || preg_match('/@section\s*\(\s*[\'"]content[\'"]\s*\)/', $visible, $section, PREG_OFFSET_CAPTURE) !== 1) {
            return false;
        }
        $contentStart = $section[0][1] + strlen($section[0][0]);
        $before = substr($visible, $contentStart, $insertion - $contentStart);
        if (preg_match('/相关条目|相关文章|\$relatedArticles|site\.article[._]related/u', $before) !== 1) {
            return false;
        }
        // The module must render when the related list is empty, outside all conditional and loop blocks.
        preg_match_all('/(?<!@)@(if|unless|foreach|forelse|for|while|auth|guest|isset|section|push|once|endif|endunless|endforeach|endforelse|endfor|endwhile|endauth|endguest|endisset|endsection|endpush|endonce)\b/', $before, $directives);
        $stack = [];
        foreach ($directives[1] as $directive) {
            if (str_starts_with($directive, 'end')) {
                if (array_pop($stack) !== substr($directive, 3)) {
                    return false;
                }
            } else {
                $stack[] = $directive;
            }
        }

        return $stack === [];
    }

    private function visibleSource(string $source): string
    {
        return preg_replace_callback('/\{\{--.*?--\}\}|<!--.*?-->|@verbatim\b.*?@endverbatim|<script\b[^>]*>.*?<\/script\s*>|<style\b[^>]*>.*?<\/style\s*>|@php\b(?!\s*\().*?@endphp|<\?php.*?\?>/is',
            static fn (array $match): string => str_repeat(' ', strlen($match[0])), $source) ?? '';
    }

    /** @return array{string,?int} */
    private function aiAnchor(string $source, Admin $admin): array
    {
        try {
            $candidates = $this->models->resolveCandidates($admin, 'chat');
        } catch (AiModelAccessException) {
            throw new ApiException('theme_ai_model_unavailable', '当前主题需要 AI 判断插入位置，请先配置可用聊天模型', 409);
        }
        $model = $candidates->firstWhere('id', $admin->aiSettings?->default_chat_model_id) ?? $candidates->first();
        if ($model === null) {
            throw new ApiException('theme_ai_model_unavailable', '当前主题需要 AI 判断插入位置，请先配置可用聊天模型', 409);
        }
        $invocation = null;
        $response = null;
        try {
            $context = $this->guard->freeze($admin, 'link_pool_theme_anchor', (int) $admin->id, requestedModelId: (int) $model->id);
            $invocation = $this->gateway->acquire($context, 120);
            $model = $invocation->model;
            $url = OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url);
            $driver = OpenAiRuntimeProvider::resolveChatDriver($url, (string) $model->model_id);
            $key = $this->crypto->decrypt((string) $model->getRawOriginal('api_key'));
            $provider = OpenAiRuntimeProvider::registerProvider('link_pool_theme_anchor', $driver, $url, $key);
            $prompt = mb_substr($source, 0, 30000);
            $invocation->beginUsageAttempt($prompt, 'link_pool.theme_anchor', 'link_pool_theme_adaptation');
            $response = (new LinkPoolThemeAnchorAgent)->prompt($prompt, [], $provider, (string) $model->model_id, 60);
            if (! $response instanceof StructuredAgentResponse) {
                throw new \RuntimeException('invalid_anchor_response');
            }
            $this->guard->assertModelCurrent($context, $model);
            $invocation->recordSuccess($response->usage);

            return [trim((string) ($response->toArray()['anchor'] ?? '')), (int) $model->id];
        } catch (AiModelAccessException $exception) {
            $invocation?->recordRevoked($exception->getErrorCode(), $response?->usage);
            throw new ApiException('theme_ai_model_unavailable', '当前主题需要 AI 判断插入位置，请确认聊天模型可用且仍有调用额度', 409);
        } catch (AiModelRuntimeEligibilityException|AiWorkspaceModelUnavailableException) {
            throw new ApiException('theme_ai_model_unavailable', '当前主题需要 AI 判断插入位置，请确认聊天模型可用且仍有调用额度', 409);
        } catch (\Throwable) {
            if ($response !== null) {
                $invocation?->recordDiscarded('theme_ai_anchor_failed', $response->usage);
            } else {
                $invocation?->recordProviderFailure('theme_ai_anchor_failed');
            }
            throw new ApiException('theme_ai_anchor_failed', 'AI 未能确定安全的插入位置，请稍后重试', 422);
        } finally {
            $invocation?->close();
        }
    }
}
