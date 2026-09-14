<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Support\AdminWeb;
use App\Support\Site\FriendLinkSettings;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FriendLinkSettingsController extends Controller
{
    public function edit(FriendLinkSettings $settings, SiteThemeCatalog $siteThemeCatalog): View
    {
        $activeThemeId = (string) SiteSetting::query()
            ->where('setting_key', 'active_theme')
            ->value('setting_value');
        $activeTheme = collect($siteThemeCatalog->all())->firstWhere('id', $activeThemeId);

        return view('admin.site-settings.friend-links', [
            'pageTitle' => __('friend_links.title'),
            'activeMenu' => 'site_settings',
            'adminSiteName' => AdminWeb::siteName(),
            'friendLinkSnapshot' => $settings->snapshot(),
            'friendLinkInstalledTheme' => ($activeTheme['source'] ?? null) === 'installed',
        ]);
    }

    public function __invoke(Request $request, FriendLinkSettings $settings): RedirectResponse
    {
        $destination = route('admin.site-settings.friend-links.edit');
        $summary = ['result' => 'invalid'];

        try {
            $payload = $settings->validateInput($request->input('friend_links'));
            $summary = ['count' => count($payload['config']['links']), 'enabled' => $payload['config']['enabled']];
            $settings->save($payload);
        } catch (ValidationException $exception) {
            $summary['result'] = isset($exception->errors()['friend_links.expected_revision']) ? 'conflict' : 'invalid';
            $request->attributes->set('admin_activity_details', ['success' => false, 'friend_links' => $summary]);

            $incomplete = count(array_intersect(array_keys($exception->errors()), [
                'friend_links', 'friend_links.links', 'friend_links.link_count', 'friend_links.enabled',
            ])) > 0;

            $submittedLinks = $request->input('friend_links.links');
            foreach (is_array($submittedLinks) ? $submittedLinks : [] as $link) {
                if (! is_array($link) || array_diff(FriendLinkSettings::LINK_FIELDS, array_keys($link)) !== []) {
                    $incomplete = true;
                    break;
                }
            }

            return redirect()->to($destination)->with('friend_links_reload_required', $incomplete)
                ->withErrors($exception->errors(), 'friend_links')
                ->withInput($request->only('friend_links'));
        }

        $request->attributes->set('admin_activity_details', [
            'success' => true, 'friend_links' => $summary + ['result' => 'saved'],
        ]);

        return redirect()->to($destination)->with('friend_links_saved', true);
    }
}
