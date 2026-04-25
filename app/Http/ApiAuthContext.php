<?php

namespace App\Http;

final class ApiAuthContext
{
    /**
     * @param  array<string, mixed>  $token
     */
    public function __construct(
        public array $token,
        public int $auditAdminId
    ) {}
}
