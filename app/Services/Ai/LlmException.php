<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * A provider call failed at the transport or API level.
 *
 * Distinct from a validation failure: this means we never got a usable
 * response at all, so there is nothing to repair and retrying the same request
 * is only worthwhile if $retryable is true.
 */
class LlmException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    public static function transport(string $message): self
    {
        // Network blips and timeouts are worth another go.
        return new self($message, retryable: true);
    }

    public static function api(string $message, int $status): self
    {
        // 429 is rate limiting and 5xx is the provider's problem; both clear up
        // on their own. 4xx otherwise means our request is wrong, and sending
        // it again will fail identically.
        $retryable = $status === 429 || $status >= 500;

        return new self($message, retryable: $retryable, status: $status);
    }

    public static function empty(string $reason): self
    {
        return new self("The model returned no usable content ({$reason}).", retryable: false);
    }
}
