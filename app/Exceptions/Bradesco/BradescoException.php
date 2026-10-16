<?php

namespace App\Exceptions\Bradesco;

use RuntimeException;

/**
 * Base error for the read-only Bradesco integration (Phase 4A).
 *
 * Messages are operator-safe Portuguese strings. The optional context
 * carries masked identifiers only — never tokens, secrets or full
 * account numbers.
 */
class BradescoException extends RuntimeException
{
    /**
     * @var array<string, mixed>
     */
    public array $context = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public static function withContext(string $message, array $context = []): static
    {
        $exception = new static($message);
        $exception->context = $context;

        return $exception;
    }
}
