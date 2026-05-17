<?php

namespace App\Services\Geo;

class GeoAnswerScorer
{
    /**
     * @param  list<string>  $brandNames
     * @param  list<string>  $competitorNames
     * @return array{
     *     brand_mentioned: bool,
     *     is_recommended: bool,
     *     rank_position: int|null,
     *     competitors_mentioned: list<string>,
     *     has_citation: bool,
     *     has_contact_fact: bool,
     *     score: int
     * }
     */
    public function score(array $brandNames, array $competitorNames, string $answer): array
    {
        $brandNames = $this->cleanNames($brandNames);
        $competitorNames = $this->cleanNames($competitorNames);
        $competitorsMentioned = $this->mentionedNames($competitorNames, $answer);

        $brandMentioned = $this->containsAny($answer, $brandNames);
        $isRecommended = $brandMentioned && $this->brandHasRecommendationContext($answer, $brandNames);
        $rankPosition = $brandMentioned ? $this->rankPosition($answer, $brandNames, $competitorsMentioned) : null;
        $hasCitation = $this->hasCitation($answer);
        $hasContactFact = $this->hasContactFact($answer);

        $score = 0;
        $score += $brandMentioned ? 30 : 0;
        $score += $isRecommended ? 20 : 0;
        $score += $rankPosition !== null && $rankPosition <= 3 ? 15 : 0;
        $score += $hasCitation ? 10 : 0;
        $score += $hasContactFact ? 10 : 0;

        if (! $brandMentioned && $competitorsMentioned !== []) {
            $score -= 10;
        }

        return [
            'brand_mentioned' => $brandMentioned,
            'is_recommended' => $isRecommended,
            'rank_position' => $rankPosition,
            'competitors_mentioned' => $competitorsMentioned,
            'has_citation' => $hasCitation,
            'has_contact_fact' => $hasContactFact,
            'score' => max(0, min(100, $score)),
        ];
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function cleanNames(array $names): array
    {
        $clean = [];

        foreach ($names as $name) {
            $name = trim($name);
            if ($name !== '' && ! in_array($name, $clean, true)) {
                $clean[] = $name;
            }
        }

        return $clean;
    }

    /**
     * @param  list<string>  $names
     */
    private function containsAny(string $answer, array $names): bool
    {
        foreach ($names as $name) {
            if (mb_stripos($answer, $name) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function mentionedNames(array $names, string $answer): array
    {
        $mentioned = [];

        foreach ($names as $name) {
            if (mb_stripos($answer, $name) !== false) {
                $mentioned[] = $name;
            }
        }

        return $mentioned;
    }

    /**
     * @param  list<string>  $brandNames
     */
    private function brandHasRecommendationContext(string $answer, array $brandNames): bool
    {
        $recommendationWords = ['推荐', '优先', '可以考虑', '建议', '适合', '值得了解', '首选', '靠谱'];

        foreach ($brandNames as $brandName) {
            $position = mb_stripos($answer, $brandName);
            if ($position === false) {
                continue;
            }

            $context = mb_substr($answer, max(0, $position - 30), mb_strlen($brandName) + 60);
            foreach ($recommendationWords as $word) {
                if (mb_stripos($context, $word) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $brandNames
     * @param  list<string>  $competitorsMentioned
     */
    private function rankPosition(string $answer, array $brandNames, array $competitorsMentioned): ?int
    {
        $brandPosition = null;

        foreach ($brandNames as $brandName) {
            $position = mb_stripos($answer, $brandName);
            if ($position !== false && ($brandPosition === null || $position < $brandPosition)) {
                $brandPosition = $position;
            }
        }

        if ($brandPosition === null) {
            return null;
        }

        $competitorsBeforeBrand = 0;
        foreach ($competitorsMentioned as $competitorName) {
            $position = mb_stripos($answer, $competitorName);
            if ($position !== false && $position < $brandPosition) {
                $competitorsBeforeBrand++;
            }
        }

        return $competitorsBeforeBrand + 1;
    }

    private function hasCitation(string $answer): bool
    {
        return preg_match('#https?://[^\s)]+#i', $answer) === 1
            || preg_match('/来源|引用|参考|资料显示/u', $answer) === 1;
    }

    private function hasContactFact(string $answer): bool
    {
        return preg_match('/官网|电话|地址|门店|联系方式|上门量尺/u', $answer) === 1;
    }
}
