<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\LlmResponse;

/**
 * Everything the form generator needs from a language model.
 *
 * Kept to a single method on purpose. This application only ever asks an LLM
 * for one thing — a JSON document conforming to a supplied schema — so the
 * interface says exactly that rather than exposing a general chat API we would
 * never use.
 *
 * Two implementations: GeminiProvider talks to Google, FakeProvider is
 * deterministic and offline.
 */
interface LlmProvider
{
    /**
     * Ask for a JSON document matching $responseSchema.
     *
     * Implementations must enforce the schema at the API level where the
     * provider supports it (Gemini's responseSchema), and must still return
     * whatever came back if the model ignores it — repairing bad output is the
     * caller's job, not the transport's.
     *
     * @param  array<string, mixed>  $responseSchema  JSON Schema for the reply
     * @param  array<string, mixed>  $options         provider-specific overrides
     *
     * @throws \App\Services\Ai\LlmException on transport or API failure
     */
    public function generateJson(
        string $systemPrompt,
        string $userPrompt,
        array $responseSchema,
        array $options = [],
    ): LlmResponse;

    /** Short identifier stored on the audit row, e.g. "gemini". */
    public function name(): string;

    /** The exact model id used, e.g. "gemini-2.5-flash". */
    public function model(): string;
}
