<?php

namespace App\Services\GeoFlow;

use Closure;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamableAgentResponse;

final class ArticleContentStreamSession
{
    private bool $completed = false;

    public function __construct(
        public readonly StreamableAgentResponse $stream,
        public readonly Meta $meta,
        private readonly Closure $completeCallback,
        private readonly Closure $abortCallback,
    ) {}

    public function complete(): void
    {
        if ($this->completed) {
            return;
        }

        ($this->completeCallback)();
        $this->completed = true;
    }

    public function abort(): void
    {
        if ($this->completed) {
            return;
        }

        ($this->abortCallback)();
        $this->completed = true;
    }
}
