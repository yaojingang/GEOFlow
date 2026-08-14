<?php

namespace App\Services\ZeroPoint;

use App\Models\Admin;
use App\Models\ContentApproval;
use App\Models\PublicPage;
use App\Models\PublicationSnapshot;
use DomainException;
use Illuminate\Support\Facades\DB;

class PublicContentWorkflow
{
    public function approve(PublicPage $page, string $gate, Admin $reviewer, string $decision, string $note = ''): ContentApproval
    {
        if (! in_array($gate, ContentApproval::GATES, true)) {
            throw new DomainException('未知审核门。');
        }

        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new DomainException('未知审核结论。');
        }

        return ContentApproval::query()->updateOrCreate(
            [
                'public_page_id' => $page->id,
                'content_hash' => $page->content_hash,
                'gate' => $gate,
            ],
            [
                'decision' => $decision,
                'reviewer_id' => $reviewer->id,
                'reviewer_name' => $reviewer->name,
                'note' => trim($note),
                'decided_at' => now(),
            ]
        );
    }

    public function publish(PublicPage $page, Admin $publisher): PublicationSnapshot
    {
        return DB::transaction(function () use ($page, $publisher): PublicationSnapshot {
            $locked = PublicPage::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();
            $this->assertPublishable($locked);

            PublicationSnapshot::query()
                ->where('public_page_id', $locked->id)
                ->where('is_active', true)
                ->update(['is_active' => false, 'superseded_at' => now()]);

            $snapshot = PublicationSnapshot::query()->create([
                'public_page_id' => $locked->id,
                'content_hash' => $locked->content_hash,
                'version' => $locked->version,
                'payload' => $locked->approvalPayload(),
                'is_active' => true,
                'published_by' => $publisher->id,
                'published_at' => now(),
            ]);

            $locked->forceFill(['status' => 'published'])->saveQuietly();

            return $snapshot;
        });
    }

    public function rollback(PublicPage $page, PublicationSnapshot $snapshot, Admin $publisher): PublicationSnapshot
    {
        if ((int) $snapshot->public_page_id !== (int) $page->id) {
            throw new DomainException('回滚版本不属于当前页面。');
        }

        return DB::transaction(function () use ($page, $snapshot, $publisher): PublicationSnapshot {
            PublicationSnapshot::query()
                ->where('public_page_id', $page->id)
                ->where('is_active', true)
                ->update(['is_active' => false, 'superseded_at' => now()]);

            return PublicationSnapshot::query()->create([
                'public_page_id' => $page->id,
                'content_hash' => $snapshot->content_hash,
                'version' => $snapshot->version,
                'payload' => $snapshot->payload,
                'is_active' => true,
                'published_by' => $publisher->id,
                'published_at' => now(),
            ]);
        });
    }

    private function assertPublishable(PublicPage $page): void
    {
        $approvedGates = ContentApproval::query()
            ->where('public_page_id', $page->id)
            ->where('content_hash', $page->content_hash)
            ->where('decision', 'approved')
            ->pluck('gate')
            ->unique()
            ->all();

        $missing = array_values(array_diff(ContentApproval::GATES, $approvedGates));
        if ($missing !== []) {
            throw new DomainException('缺少当前版本审核：'.implode('、', $missing));
        }

        if ($page->area === 'health' && (trim((string) $page->cta_label) !== '' || trim((string) $page->cta_url) !== '')) {
            throw new DomainException('健康科普页不得配置预约或服务 CTA。');
        }

        if ($page->area === 'institution' && ! $page->is_placeholder) {
            $facts = $page->facts()->get();
            if ($facts->isEmpty() || $facts->contains(fn ($fact): bool => ! $fact->isPublishable())) {
                throw new DomainException('机构页面必须绑定全部达到 E3、公开且有效的事实。');
            }
        }
    }
}
