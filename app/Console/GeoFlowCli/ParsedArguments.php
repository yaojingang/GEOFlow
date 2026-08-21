<?php

namespace App\Console\GeoFlowCli;

class ParsedArguments
{
    /**
     * @param  array<string,mixed>  $options
     * @param  list<string>  $positionals
     */
    public function __construct(
        public readonly array $options,
        public readonly array $positionals,
    ) {}
}
