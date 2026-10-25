<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ThemeRevision;
use App\Services\Admin\SiteThemePackageService;
use App\Services\Api\ThemeRevisionStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ThemePackageFixture;
use Tests\TestCase;

class InstalledThemeWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_installed_theme_supports_two_remote_draft_edits_and_signed_previews_without_changing_its_installation(): void
    {
        Storage::fake('local');
        $theme = 'remote-installed-fixture';
        $admin = Admin::query()->create(['username' => 'installed-theme-editor', 'password' => 'fixture-password', 'role' => 'super_admin', 'status' => 'active']);
        $files = ThemePackageFixture::files($theme);
        $packages = app(SiteThemePackageService::class);
        $inspection = $packages->inspect(ThemePackageFixture::archive($files, ThemePackageFixture::package($files, $theme)), $admin->id);
        $packages->install($admin->id, $inspection['token'], true);
        $installedRoot = Storage::disk('local')->path('geoflow-site-themes/installed/'.$theme);
        $installationReceipt = file_get_contents($installedRoot.'/installation.json');
        $this->withToken($admin->createToken('remote-installed', ['themes:read', 'themes:write', 'themes:code'])->plainTextToken);
        $workspace = $this->postJson('/api/v1/management/theme-workspaces', ['site' => 'primary', 'theme' => $theme])
            ->assertCreated()->assertJsonPath('data.source', 'installed')->json('data');
        $base = '/api/v1/management/theme-workspaces/'.$workspace['id'];
        $this->postJson($base.'/code-authorizations', ['password' => 'fixture-password'])->assertOk();
        $home = 'resources/views/theme/'.$theme.'/home.blade.php';
        $css = 'public/themes/'.$theme.'/theme.css';
        $revisions = [];
        foreach ([1 => '#135', 2 => '#246'] as $round => $color) {
            $homeContent = "\n  @extends('theme.$theme.layout')\n@section('content')\nInstalled draft round $round\n@endsection\n\t";
            $cssContent = " \nbody{color:".$color."}\n ";
            $changes = [
                ['action' => 'put', 'path' => $home, 'expected_sha256' => $workspace['files'][$home]['sha256'], 'content' => $homeContent],
                ['action' => 'put', 'path' => $css, 'expected_sha256' => $workspace['files'][$css]['sha256'], 'content' => $cssContent],
            ];
            if ($round === 2) {
                $changes[] = ['action' => 'put', 'path' => 'resources/views/theme/'.$theme.'/empty.blade.php', 'expected_sha256' => null, 'content' => ''];
            }
            $workspace = $this->postJson($base.'/changes', ['expected_version' => $workspace['lock_version'], 'changes' => $changes])
                ->assertOk()->assertJsonPath('data.lock_version', $round + 1)->json('data');
            $revisions[] = ['id' => $workspace['revision_id'], 'home' => $homeContent, 'css' => $cssContent];
            $url = $this->postJson($base.'/previews')->assertOk()->json('data.pages.home.url');
            $preview = $this->get($url)->assertOk()->assertSee('Installed draft round '.$round)
                ->assertHeader('Cache-Control', 'no-store, private');
            $document = new \DOMDocument;
            @$document->loadHTML($preview->getContent());
            $link = (new \DOMXPath($document))->query('//link[@rel="stylesheet"]')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $link);
            $this->get($link->getAttribute('href'))->assertOk()->assertSee($cssContent);
            foreach ($files as $path => $contents) {
                $this->assertSame($contents, file_get_contents($installedRoot.'/'.$path));
            }
            $this->assertSame($installationReceipt, file_get_contents($installedRoot.'/installation.json'));
        }

        $this->assertNotSame($revisions[0]['id'], $revisions[1]['id']);
        foreach ($revisions as $snapshot) {
            $revision = ThemeRevision::query()->findOrFail($snapshot['id']);
            $contents = app(ThemeRevisionStorage::class)->contents($revision);
            $this->assertSame($snapshot['home'], $contents[$home]);
            $this->assertSame($snapshot['css'], $contents[$css]);
        }
        $this->assertSame('', $contents['resources/views/theme/'.$theme.'/empty.blade.php']);
    }
}
