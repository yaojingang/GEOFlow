<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\SensitiveWord;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Throwable;

/**
 * 敏感词管理控制器。
 *
 * 敏感词管理已合并到网站设置模块下，旧 security-settings 路由仅保留兼容。
 */
class SecuritySettingsController extends Controller
{
    /**
     * 敏感词管理页。
     */
    public function index(): View
    {
        /** @var Admin|null $currentAdmin */
        $currentAdmin = Auth::guard('admin')->user();

        return view('admin.security-settings.index', [
            'pageTitle' => __('admin.security.page_title'),
            'activeMenu' => 'site_settings',
            'adminSiteName' => AdminWeb::siteName(),
            'sensitiveWords' => $this->loadSensitiveWords(),
            'currentAdmin' => $currentAdmin,
            'isSuperAdmin' => $this->isSuperAdmin($currentAdmin),
        ]);
    }

    /**
     * 批量添加敏感词。
     */
    public function storeSensitiveWords(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'words' => ['required', 'string'],
        ], [
            'words.required' => __('admin.security.error.words_required'),
        ]);

        $rawWords = trim((string) ($payload['words'] ?? ''));
        if ($rawWords === '') {
            return back()->withErrors(__('admin.security.error.words_required'));
        }

        try {
            // 按行切分并去掉空行/重复词，确保一次提交可安全批量写入。
            $submittedWords = collect(preg_split('/\R/u', $rawWords) ?: [])
                ->map(static fn (string $word): string => trim($word))
                ->filter(static fn (string $word): bool => $word !== '')
                ->unique()
                ->values();

            if ($submittedWords->isEmpty()) {
                return back()->withErrors(__('admin.security.error.words_required'));
            }

            $existingWords = SensitiveWord::query()
                ->whereIn('word', $submittedWords->all())
                ->pluck('word')
                ->all();

            $wordsToInsert = $submittedWords
                ->reject(static fn (string $word): bool => in_array($word, $existingWords, true))
                ->map(static fn (string $word): array => [
                    'word' => $word,
                    'created_at' => now(),
                ])
                ->values()
                ->all();

            if (! empty($wordsToInsert)) {
                SensitiveWord::query()->insert($wordsToInsert);
            }

            return redirect()
                ->route('admin.site-settings.sensitive-words')
                ->with('message', __('admin.security.message.words_added', ['count' => count($wordsToInsert)]));
        } catch (Throwable $exception) {
            return back()->withErrors(__('admin.security.message.words_add_error', ['message' => $exception->getMessage()]));
        }
    }

    /**
     * 删除单个敏感词。
     */
    public function destroySensitiveWord(int $wordId): RedirectResponse
    {
        if ($wordId <= 0) {
            return back()->withErrors(__('admin.security.error.invalid_word_id'));
        }

        try {
            $deleted = SensitiveWord::query()->whereKey($wordId)->delete();
            if ($deleted <= 0) {
                return back()->withErrors(__('admin.security.message.word_delete_failed'));
            }

            return redirect()->route('admin.site-settings.sensitive-words')->with('message', __('admin.security.message.word_deleted'));
        } catch (Throwable $exception) {
            return back()->withErrors(__('admin.security.message.word_delete_error', ['message' => $exception->getMessage()]));
        }
    }

    /**
     * 更新当前管理员密码，并强制重新登录。
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:6'],
            'confirm_password' => ['required', 'string', 'same:new_password'],
        ], [
            'current_password.required' => __('admin.security.error.password_fields_required'),
            'new_password.required' => __('admin.security.error.password_fields_required'),
            'confirm_password.required' => __('admin.security.error.password_fields_required'),
            'confirm_password.same' => __('admin.security.error.password_mismatch'),
            'new_password.min' => __('admin.security.error.password_too_short'),
        ]);

        /** @var Admin|null $admin */
        $admin = Auth::guard('admin')->user();
        if (! $admin) {
            return redirect()->route('admin.login');
        }

        if (! Hash::check((string) $payload['current_password'], (string) $admin->password)) {
            return back()->withErrors(__('admin.security.error.current_password_wrong'));
        }

        try {
            $admin->forceFill([
                'password' => (string) $payload['new_password'],
            ])->save();

            // 与 bak 保持一致：密码修改后立即失效当前会话。
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('admin.login')
                ->with('message', __('admin.login.success.password_updated'));
        } catch (Throwable $exception) {
            return back()->withErrors(__('admin.security.message.password_update_error', ['message' => $exception->getMessage()]));
        }
    }

    /**
     * @return array<int, array{id:int,word:string,created_at:string}>
     */
    private function loadSensitiveWords(): array
    {
        return SensitiveWord::query()
            ->select(['id', 'word', 'created_at'])
            ->orderBy('word')
            ->get()
            ->map(static function (SensitiveWord $word): array {
                return [
                    'id' => (int) $word->id,
                    'word' => (string) $word->word,
                    'created_at' => (string) ($word->created_at?->format('Y-m-d') ?? ''),
                ];
            })
            ->all();
    }

    /**
     * 判断管理员是否为超级管理员。
     */
    private function isSuperAdmin(?Admin $admin): bool
    {
        return $admin instanceof Admin && $admin->isSuperAdmin();
    }
}
