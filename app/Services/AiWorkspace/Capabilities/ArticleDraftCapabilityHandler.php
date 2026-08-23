<?php

namespace App\Services\AiWorkspace\Capabilities;

use App\Ai\Workspace\AiCapabilityResult;
use App\Models\Admin;
use App\Services\GeoFlow\ArticleGeoFlowService;

final readonly class ArticleDraftCapabilityHandler implements AiCapabilityHandler
{
    public function __construct(private ArticleGeoFlowService $articles) {}

    public function execute(array $parameters, Admin $admin, ?string $executionKey = null): AiCapabilityResult
    {
        $article = $this->articles->createArticle([
            'title' => (string) $parameters['title'],
            'content' => (string) $parameters['content'],
            'category_id' => (int) $parameters['category_id'],
            'author_id' => (int) $parameters['author_id'],
            'status' => 'draft',
            'review_status' => 'pending',
            'is_ai_generated' => 1,
        ], (int) $admin->id);

        return new AiCapabilityResult(
            summary: sprintf('文章草稿“%s”已创建并完成风险扫描。', $article['title']),
            payload: ['article_id' => (int) $article['id'], 'status' => 'draft', 'review_status' => 'pending'],
            artifactType: 'article_draft',
            artifactName: (string) $article['title'],
            sourceRoute: 'admin.articles.edit',
            sourceUrl: route('admin.articles.edit', ['articleId' => $article['id']]),
        );
    }
}
