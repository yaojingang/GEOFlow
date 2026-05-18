<?php

namespace App\Jobs;

use App\Models\BrandProfile;
use App\Models\GeoCitationSource;
use App\Models\Organization;
use App\Services\Geo\GeoReferenceContentQualityScorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GeoBatchScoreCitationSourcesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  list<int>  $sourceIds
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly array $sourceIds
    ) {}

    public function handle(GeoReferenceContentQualityScorer $scorer): void
    {
        $organization = Organization::query()->whereKey($this->organizationId)->first();
        if (! $organization) {
            return;
        }

        GeoCitationSource::query()
            ->where('organization_id', $this->organizationId)
            ->whereIn('id', $this->sourceIds)
            ->with(['searchAnswer.question', 'searchAnswer.opportunity', 'searchAnswer.searchRun.brandProfile'])
            ->each(function (GeoCitationSource $source) use ($organization, $scorer): void {
                try {
                    $snapshot = $source->pageSnapshots()
                        ->where('crawl_status', 'succeeded')
                        ->latest()
                        ->first();

                    if (! $snapshot) {
                        $source->forceFill([
                            'metadata' => array_merge((array) $source->metadata, [
                                'last_score_status' => 'skipped_no_snapshot',
                            ]),
                        ])->save();

                        return;
                    }

                    $score = $scorer->scoreSnapshot($snapshot, $this->context($source, $organization));
                    $source->forceFill([
                        'metadata' => array_merge((array) $source->metadata, [
                            'last_score_status' => 'scored',
                            'last_score_id' => $score->id,
                            'last_score_total' => $score->total_score,
                        ]),
                    ])->save();
                } catch (Throwable $exception) {
                    $source->forceFill([
                        'metadata' => array_merge((array) $source->metadata, [
                            'last_score_status' => 'failed',
                            'last_score_error' => mb_substr($exception->getMessage(), 0, 1000),
                        ]),
                    ])->save();
                }
            });
    }

    /**
     * @return array{query: string, keywords: list<string>, brand_names: list<string>, competitor_names: list<string>}
     */
    private function context(GeoCitationSource $source, Organization $organization): array
    {
        $answer = $source->searchAnswer;
        $brandProfile = $answer?->searchRun?->brandProfile
            ?? BrandProfile::query()->where('organization_id', $organization->id)->latest()->first();
        $brandNames = $brandProfile instanceof BrandProfile
            ? array_values(array_filter(array_merge([$brandProfile->brand_name], (array) $brandProfile->aliases)))
            : [];

        return [
            'query' => (string) ($answer?->question?->question ?? $answer?->opportunity?->keyword ?? ''),
            'keywords' => array_values(array_filter([
                (string) ($answer?->opportunity?->keyword ?? ''),
                (string) ($answer?->opportunity?->intent ?? ''),
                (string) $source->domain,
                (string) ($brandProfile?->service_area ?? ''),
                (string) ($brandProfile?->products ?? ''),
                (string) ($brandProfile?->advantages ?? ''),
            ])),
            'brand_names' => $brandNames,
            'competitor_names' => array_values(array_filter((array) ($answer?->competitors_mentioned ?? []))),
        ];
    }
}
