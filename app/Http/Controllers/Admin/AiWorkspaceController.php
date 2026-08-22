<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminWeb;
use Illuminate\View\View;

final class AiWorkspaceController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.ai-workspace.index', [
            'pageTitle' => __('admin.ai_workspace.page_title'),
            'activeMenu' => 'ai-workspace',
            'adminSiteName' => AdminWeb::siteName(),
        ]);
    }
}
