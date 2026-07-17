<?php

namespace App\Services\Outbound;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;

final class SecurePendingRequest extends PendingRequest
{
    /** @var Collection<int, callable> */
    private readonly Collection $secureRequestMiddleware;

    /** @var Collection<int, callable> */
    private readonly Collection $secureResponseMiddleware;

    /** @var array{ResolvedOutboundTarget, string, bool, int, bool}|null */
    private ?array $fixedSecurityContext = null;

    /**
     * @param  array<int, callable>  $requestMiddleware
     * @param  array<int, callable>  $responseMiddleware
     * @param  Closure(string): ResolvedOutboundTarget  $resolveTarget
     */
    public function __construct(
        ?Factory $factory,
        array $requestMiddleware,
        array $responseMiddleware,
        private readonly FinalOutboundSecurityPolicy $securityPolicy,
        private readonly Closure $resolveTarget,
        private readonly Closure $trustedTerminalHandler,
        private readonly object $fixedContextCapability,
    ) {
        parent::__construct($factory);
        $this->secureRequestMiddleware = new Collection($requestMiddleware);
        $this->secureResponseMiddleware = new Collection($responseMiddleware);
    }

    public function withMiddleware(callable $middleware)
    {
        throw new OutboundRequestBlockedException('generic_http_middleware_forbidden');
    }

    public function withRequestMiddleware(callable $middleware)
    {
        $this->secureRequestMiddleware->push(Middleware::mapRequest($middleware));

        return $this;
    }

    public function withResponseMiddleware(callable $middleware)
    {
        $this->secureResponseMiddleware->push(Middleware::mapResponse($middleware));

        return $this;
    }

    public function withFixedSecurityContext(
        ResolvedOutboundTarget $target,
        string $method,
        bool $crossOrigin,
        int $maxBytes,
        bool $streamingRequested,
        ?object $fixedContextCapability = null,
    ): self {
        if ($fixedContextCapability !== $this->fixedContextCapability) {
            throw new OutboundRequestBlockedException('fixed_context_unauthorized');
        }

        $clone = clone $this;
        $clone->fixedSecurityContext = [
            $target,
            strtoupper($method),
            $crossOrigin,
            $maxBytes,
            $streamingRequested,
        ];

        return $clone;
    }

    public function buildHandlerStack()
    {
        $stack = HardenedHandlerStack::create($this->trustedTerminalHandler);
        $this->secureRequestMiddleware->each(static function (callable $middleware) use ($stack): void {
            $stack->push($middleware);
        });
        $stack->push($this->buildBeforeSendingHandler());
        $middleware = $this->fixedSecurityContext === null
            ? $this->securityPolicy->dynamicMiddleware($this->getOptions(), $this->resolveTarget)
            : $this->securityPolicy->fixedTargetMiddleware(
                $this->getOptions(),
                ...$this->fixedSecurityContext,
            );
        $stack->push($middleware, 'geoflow_final_outbound_security');
        $this->secureResponseMiddleware->each(static function (callable $middleware) use ($stack): void {
            $stack->push($middleware);
        });
        $stack->push($this->buildRecorderHandler());
        $stack->push($this->buildStubHandler());

        return $stack;
    }

    public function buildClient()
    {
        return $this->createClient($this->buildHandlerStack());
    }

    protected function sendRequest(string $method, string $url, array $options = [])
    {
        unset($this->options['handler'], $options['handler']);

        return parent::sendRequest($method, $url, $options);
    }

    public function setClient(Client $client)
    {
        return $this;
    }

    public function setHandler($handler)
    {
        return $this;
    }
}
