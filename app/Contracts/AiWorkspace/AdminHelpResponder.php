<?php

namespace App\Contracts\AiWorkspace;

use App\Data\Ai\AiWorkspaceExecutionContext;
use App\Models\Admin;
use Generator;

interface AdminHelpResponder
{
    /**
     * @param  iterable<int, mixed>  $messages
     *                                          Events use {type: status|delta}; the return value contains the final answer, safe generation metadata, and usage.
     * @return Generator<int, array<string, mixed>, mixed, array{answer:string,meta:array<string,mixed>,usage:array<string,int>}>
     */
    public function stream(
        string $prompt,
        string $knowledgeContext,
        iterable $messages = [],
        Admin|AiWorkspaceExecutionContext|int|null $actor = null,
    ): Generator;

    /** @param iterable<int, mixed> $messages */
    public function answer(
        string $prompt,
        string $knowledgeContext,
        iterable $messages = [],
        Admin|AiWorkspaceExecutionContext|int|null $actor = null,
    ): string;
}
