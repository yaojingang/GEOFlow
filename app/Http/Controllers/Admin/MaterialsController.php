<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Support\AdminWeb;
use Illuminate\View\View;

/**
 * 素材管理首页控制器。
 */
class MaterialsController extends Controller
{
    /**
     * 展示素材管理总览页。
     */
    public function index(): View
    {
        return view('admin.materials.index', [
            'pageTitle' => __('admin.materials.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'stats' => $this->loadStats(),
        ]);
    }

    /**
     * 加载素材管理统计数据。
     *
     * @return array{
     *     keyword_libraries:int,
     *     total_keywords:int,
     *     title_libraries:int,
     *     total_titles:int,
     *     image_libraries:int,
     *     total_images:int,
     *     knowledge_bases:int,
     *     authors:int
     * }
     */
    private function loadStats(): array
    {
        return [
            'keyword_libraries' => KeywordLibrary::query()->count(),
            'total_keywords' => Keyword::query()->count(),
            'title_libraries' => TitleLibrary::query()->count(),
            'total_titles' => Title::query()->count(),
            'image_libraries' => ImageLibrary::query()->count(),
            'total_images' => Image::query()->count(),
            'knowledge_bases' => KnowledgeBase::query()->count(),
            'authors' => Author::query()->count(),
        ];
    }
}
