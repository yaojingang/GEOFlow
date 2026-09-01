<?php

namespace App\Data\Ai;

use JsonSerializable;
use LogicException;

final readonly class SystemAiIdentity implements JsonSerializable
{
    private const VISIBILITY_COLLECTION = 'visibility_collection';

    private const VISIBILITY_ANALYTICS = 'visibility_analytics';

    private function __construct(private string $purpose) {}

    public static function forVisibilityCollection(): self
    {
        return new self(self::VISIBILITY_COLLECTION);
    }

    public static function forVisibilityAnalytics(): self
    {
        return new self(self::VISIBILITY_ANALYTICS);
    }

    public function assertCanResolveVisibilityConfiguration(): void
    {
        if (! in_array($this->purpose, [self::VISIBILITY_COLLECTION, self::VISIBILITY_ANALYTICS], true)) {
            throw new LogicException('The system identity purpose cannot resolve AI visibility configuration.');
        }
    }

    /** @return array{purpose:string} */
    public function jsonSerialize(): array
    {
        return ['purpose' => $this->purpose];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('System AI identities cannot be serialized.');
    }
}
