<?php

namespace App\Services\Outbound;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

final class ResponseSizeLimitedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private int $position;

    public function __construct(
        private StreamInterface $stream,
        private readonly int $maxBytes,
    ) {
        $this->position = $stream->tell();
        $this->assertWithinLimit($this->position);
    }

    public function read($length): string
    {
        if ($length === 0) {
            return '';
        }

        $remaining = $this->maxBytes - $this->position;
        $probeLength = min((int) $length, max(1, $remaining + 1));
        $chunk = $this->stream->read($probeLength);
        $this->position += strlen($chunk);
        $this->assertWithinLimit($this->position);

        return $chunk;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
        $this->position = $this->stream->tell();
        $this->assertWithinLimit($this->position);
    }

    private function assertWithinLimit(int $bytes): void
    {
        if ($bytes > $this->maxBytes) {
            throw new OutboundRequestBlockedException('response_too_large');
        }
    }
}
