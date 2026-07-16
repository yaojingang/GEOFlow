<?php

namespace App\Services\Outbound;

use Closure;
use GuzzleHttp\Middleware;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory;

final class SecureHttpFactory extends Factory
{
    /** @var list<callable> */
    private array $secureGlobalRequestMiddleware = [];

    /** @var list<callable> */
    private array $secureGlobalResponseMiddleware = [];

    /**
     * @param  Closure(string): ResolvedOutboundTarget  $resolveTarget
     */
    public function __construct(
        ?Dispatcher $dispatcher,
        private readonly FinalOutboundSecurityPolicy $securityPolicy,
        private readonly Closure $resolveTarget,
        private readonly Closure $trustedTerminalHandler,
        private readonly object $fixedContextCapability,
    ) {
        parent::__construct($dispatcher);
    }

    public function globalMiddleware($middleware)
    {
        throw new OutboundRequestBlockedException('generic_http_middleware_forbidden');
    }

    public function globalRequestMiddleware($middleware)
    {
        $this->secureGlobalRequestMiddleware[] = Middleware::mapRequest($middleware);

        return $this;
    }

    public function globalResponseMiddleware($middleware)
    {
        $this->secureGlobalResponseMiddleware[] = Middleware::mapResponse($middleware);

        return $this;
    }

    public function getGlobalMiddleware()
    {
        return [
            ...$this->secureGlobalRequestMiddleware,
            ...$this->secureGlobalResponseMiddleware,
        ];
    }

    protected function newPendingRequest()
    {
        return (new SecurePendingRequest(
            $this,
            $this->secureGlobalRequestMiddleware,
            $this->secureGlobalResponseMiddleware,
            $this->securityPolicy,
            $this->resolveTarget,
            $this->trustedTerminalHandler,
            $this->fixedContextCapability,
        ))->withOptions(value($this->globalOptions));
    }
}
