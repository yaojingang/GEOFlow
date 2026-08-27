<?php

namespace App\Contracts\SystemUpdater;

interface AgentClient
{
    /**
     * @return array<string, mixed>
     */
    public function status(): array;
}
