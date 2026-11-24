<?php

namespace App\Services\Integration;

/**
 * Ang resulta ng isang tawag sa ibang system.
 *   status = null → hindi naabot (down, timeout, walang URL)
 */
final class IntegrationResult
{
    public function __construct(
        public readonly ?int $status,
        public readonly mixed $body,
        public readonly ?string $error,
    ) {}

    public function successful(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    /** 422 = may mali sa data; hindi aayos kahit ulitin (B7). */
    public function isValidationError(): bool
    {
        return $this->status === 422;
    }
}
