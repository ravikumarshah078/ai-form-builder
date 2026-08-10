<?php

namespace App\Services\Ai;

/**
 * One provider call's result, normalised across providers.
 *
 * Carries the observability data the brief asks us to log — model, tokens,
 * latency — alongside the text, so nothing downstream has to know how a
 * particular vendor reports usage.
 */
final class LlmResponse
{
    public function __construct(
        /** The model's output text, exactly as returned. */
        public readonly string $text,

        public readonly string $model,

        /** Wall-clock time of the HTTP call, excluding any queue wait. */
        public readonly int $latencyMs,

        public readonly ?int $inputTokens = null,

        public readonly ?int $outputTokens = null,

        /**
         * Why the model stopped. Worth keeping: a truncated response looks
         * like malformed JSON, and only this distinguishes "the model got it
         * wrong" from "we did not allow enough output tokens".
         */
        public readonly ?string $finishReason = null,

        /** The full decoded response body, for the audit log. */
        public readonly array $raw = [],
    ) {}

    public function totalTokens(): int
    {
        return (int) $this->inputTokens + (int) $this->outputTokens;
    }

    /**
     * True when the model ran out of room mid-answer.
     */
    public function wasTruncated(): bool
    {
        return in_array(strtoupper((string) $this->finishReason), ['MAX_TOKENS', 'LENGTH'], true);
    }
}
