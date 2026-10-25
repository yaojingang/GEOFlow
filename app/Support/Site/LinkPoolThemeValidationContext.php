<?php

namespace App\Support\Site;

final class LinkPoolThemeValidationContext
{
    private ?array $block = null;

    private bool $active = false;

    public function run(?array $block, callable $callback): mixed
    {
        $previousBlock = $this->block;
        $previousActive = $this->active;
        $this->block = $block;
        $this->active = true;
        try {
            return $callback();
        } finally {
            $this->block = $previousBlock;
            $this->active = $previousActive;
        }
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function block(): ?array
    {
        return $this->block;
    }
}
