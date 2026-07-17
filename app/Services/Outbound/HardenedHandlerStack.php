<?php

namespace App\Services\Outbound;

use GuzzleHttp\HandlerStack;

final class HardenedHandlerStack
{
    public static function create(callable $trustedTerminalHandler): HandlerStack
    {
        $stack = HandlerStack::create($trustedTerminalHandler);
        $stack->remove('allow_redirects');
        $stack->remove('http_errors');

        return $stack;
    }
}
