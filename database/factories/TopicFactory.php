<?php

namespace Database\Factories;

use App\Models\Topic;
use App\Services\Topics\TopicPayload;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Topic> */
class TopicFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'site_key' => 'primary',
            'title' => $title,
            'normalized_title_key' => TopicPayload::titleKey($title),
            'slug' => 'topic-'.Str::lower((string) Str::ulid()),
            'draft_payload' => app(TopicPayload::class)->normalize(['title' => $title]),
            'draft_version' => 1,
        ];
    }
}
