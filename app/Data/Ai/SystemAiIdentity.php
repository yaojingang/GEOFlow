<?php

namespace App\Data\Ai;

use JsonSerializable;
use LogicException;

final readonly class SystemAiIdentity implements JsonSerializable
{
    private const VISIBILITY_COLLECTION = 'visibility_collection';

    private const VISIBILITY_ANALYTICS = 'visibility_analytics';

    private const KNOWLEDGE_INDEX = 'knowledge_index';

    private function __construct(private string $purpose) {}

    public static function forVisibilityCollection(): self
    {
        return self::visibilityCollection();
    }

    public static function visibilityCollection(): self
    {
        return new self(self::VISIBILITY_COLLECTION);
    }

    public static function forVisibilityAnalytics(): self
    {
        return new self(self::VISIBILITY_ANALYTICS);
    }

    public static function knowledgeIndex(): self
    {
        return new self(self::KNOWLEDGE_INDEX);
    }

    public static function fromKnowledgeIndexPurpose(string $purpose): self
    {
        if (! hash_equals(self::KNOWLEDGE_INDEX, trim($purpose))) {
            throw new LogicException('The system identity purpose cannot run a knowledge index pipeline.');
        }

        return self::knowledgeIndex();
    }

    public function assertCanBuildKnowledgeIndex(): void
    {
        if (! hash_equals(self::KNOWLEDGE_INDEX, $this->purpose)) {
            throw new LogicException('The system identity purpose cannot build a knowledge index.');
        }
    }

    public function assertCanCollectVisibility(): void
    {
        if (! hash_equals(self::VISIBILITY_COLLECTION, $this->purpose)) {
            throw new LogicException('The system identity purpose cannot collect AI visibility.');
        }
    }

    public function purpose(): string
    {
        return $this->purpose;
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
