<?php

namespace Tests\Support;

use Generator;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use ReflectionProperty;

final class InterruptedStreamingFakeTextGateway extends FakeTextGateway
{
    public ?int $promptTimeout = null;

    public ?int $streamTimeout = null;

    /** @param list<string> $plainTextResponses */
    public function __construct(
        private readonly string $streamFailureMode,
        array $plainTextResponses = ['普通文本调用可用。'],
    ) {
        parent::__construct($plainTextResponses);
    }

    /**
     * @param  class-string  $agentClass
     * @param  list<string>  $plainTextResponses
     */
    public static function install(
        string $agentClass,
        string $streamFailureMode,
        array $plainTextResponses = ['普通文本调用可用。'],
    ): self {
        $gateway = new self($streamFailureMode, $plainTextResponses);
        $manager = app(AiManager::class);
        $property = new ReflectionProperty($manager, 'fakeAgentGateways');
        $gateways = $property->getValue($manager);
        $gateways[$agentClass] = $gateway;
        $property->setValue($manager, $gateways);

        return $gateway;
    }

    /**
     * @param  array<string, Type>|null  $schema
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $this->promptTimeout = $timeout;

        return parent::generateTextStep(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
            $timeout,
            $stepContext,
        );
    }

    /**
     * @param  array<string, Type>|null  $schema
     * @return Generator<int, mixed, mixed, StepResponse|null>
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $this->streamTimeout = $timeout;
        $messageId = 'interrupted-message';

        yield (new StreamStart('interrupted-start', $provider->name(), $model, time()))
            ->withInvocationId($invocationId);
        yield (new TextDelta('interrupted-delta', $messageId, '未完成回答', time()))
            ->withInvocationId($invocationId);

        if ($this->streamFailureMode === 'error_event') {
            yield (new Error('interrupted-error', 'connection_lost', 'stream interrupted', true, time()))
                ->withInvocationId($invocationId);
        }

        return null;
    }
}
