<?php

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Services\GeoFlow\DistributionOrchestrator;

/** Reuse the guarded temporary DB bootstrap and add local work orders; no platform requests. */
require __DIR__.'/article_workflow_fixture.php';

$retryTask = $task->replicate();
$retryTask->fill(['name' => 'Browser manual retry task', 'status' => 'active', 'schedule_enabled' => 1,
    'publish_scope' => 'distribution_only', 'ai_quality_enabled' => false])->save();
$persona = ManualPublicationPersona::create(['name' => 'Retry fixture persona', 'is_active' => true]);
$account = ManualPublicationAccount::create(['persona_id' => $persona->id,
    'platform' => 'toutiao', 'account_name' => 'Retry fixture account', 'is_active' => true]);
$channel = DistributionChannel::create(['name' => 'Retry fixture channel', 'domain' => 'mp.toutiao.com',
    'endpoint_url' => 'https://mp.toutiao.com', 'channel_type' => 'platform_web', 'status' => 'active',
    'created_by_admin_id' => $admin->id, 'channel_config' => ['platform' => 'toutiao', 'manual_publication_account_id' => $account->id]]);
$retryTask->distributionChannels()->attach($channel->id);
$orders = [];
foreach (['failed', 'outcome_unknown'] as $outcome) {
    $article = Article::create(['title' => 'Retry browser '.$outcome, 'slug' => 'retry-browser-'.$outcome,
        'content' => "## Browser heading\n\n**Verified** [reference](https://example.test/reference).\n\n&#32;Keep this paragraph.\n\n`**literal code**`", 'category_id' => $category->id,
        'author_id' => $author->id, 'task_id' => $retryTask->id, 'status' => 'private',
        'review_status' => 'approved', 'publication_intent' => 'immediate']);
    $orchestrator = app(DistributionOrchestrator::class);
    $deliveryIds = $orchestrator->enqueueForArticle($article);
    $orchestrator->process(ArticleDistribution::findOrFail($deliveryIds[0]));
    $publication = ManualPublication::where('source_distribution_id', $deliveryIds[0])->sole();
    $orders[] = ['id' => $publication->id, 'revision' => $publication->revision, 'distribution_id' => $deliveryIds[0], 'outcome' => $outcome];
}
$token = $admin->createToken('Private browser retry fixture', ['browser-operations:read', 'browser-operations:execute']);
echo json_encode(['retry_work_orders' => $orders, 'browser_token' => $token->plainTextToken], JSON_THROW_ON_ERROR).PHP_EOL;
