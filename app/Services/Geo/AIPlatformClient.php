<?php

namespace App\Services\Geo;

use App\Models\BrandProfile;
use App\Models\GeoAiPlatform;
use App\Models\GeoTaskQuestion;

interface AIPlatformClient
{
    public function ask(GeoAiPlatform $platform, BrandProfile $brandProfile, GeoTaskQuestion $question, string $prompt): string;
}
