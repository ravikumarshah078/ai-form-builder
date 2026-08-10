<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\LlmProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini, via the stateless generateContent endpoint.
 *
 * WHY generateContent AND NOT THE INTERACTIONS API
 *
 * Google now presents the Interactions API as the primary interface, with
 * generateContent marked legacy but fully supported. We use generateContent
 * deliberately:
 *
 *   - Our calls are one-shot. Prompt in, JSON out. There is no conversation to
 *     carry, so the Interactions API's server-side state buys us nothing.
 *   - Interactions defaults to store=true, meaning Google retains every
 *     interaction. The content here is a user's form design; not storing it
 *     server-side is the better default.
 *   - Its request/response shape is documented inconsistently across Google's
 *     own pages, whereas generateContent's is stable and unambiguous.
 *
 * Swapping is a change to this one class, because everything above it depends
 * on LlmProvider rather than on Google.
 */
class GeminiProvider implements LlmProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly int $timeout = 90,
        private readonly float $temperature = 0.2,
        private readonly int $maxOutputTokens = 16384,
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function generateJson(
        string $systemPrompt,
        string $userPrompt,
        array $responseSchema,
        array $options = [],
    ): LlmResponse {
        if ($this->apiKey === '') {
            throw new LlmException('No Gemini API key configured. Set GEMINI_API_KEY.');
        }

        $model = $options['model'] ?? $this->model;

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $userPrompt]],
            ]],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? $this->temperature,
                'maxOutputTokens' => $options['max_output_tokens'] ?? $this->maxOutputTokens,

                // The important pair. Together these constrain the reply at the
                // API level rather than by asking nicely in the prompt: the
                // model is structurally prevented from returning prose, a code
                // fence, or a field `type` outside our enum.
                'responseMimeType' => 'application/json',
                'responseSchema' => $responseSchema,
            ],
        ];

        $started = hrtime(true);

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->connectTimeout(10)
                ->post("{$this->baseUrl}/models/{$model}:generateContent", $payload);
        } catch (ConnectionException $e) {
            throw LlmException::transport($e->getMessage());
        }

        $latencyMs = (int) round((hrtime(true) - $started) / 1e6);

        if ($response->failed()) {
            throw LlmException::api(
                $this->describeError($response->json(), $response->status()),
                $response->status(),
            );
        }

        return $this->toLlmResponse($response->json() ?? [], $model, $latencyMs);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function toLlmResponse(array $body, string $model, int $latencyMs): LlmResponse
    {
        $candidate = $body['candidates'][0] ?? null;
        $usage = $body['usageMetadata'] ?? [];

        // A prompt can be rejected before any candidate is produced, in which
        // case the reason lives at the top level rather than on a candidate.
        if ($candidate === null) {
            $blocked = $body['promptFeedback']['blockReason'] ?? null;

            throw LlmException::empty($blocked ? "prompt blocked: {$blocked}" : 'no candidates returned');
        }

        $finishReason = $candidate['finishReason'] ?? null;

        // Concatenate parts: with a large schema the reply can arrive split.
        $text = '';

        foreach ($candidate['content']['parts'] ?? [] as $part) {
            $text .= $part['text'] ?? '';
        }

        if (trim($text) === '') {
            throw LlmException::empty('finishReason='.($finishReason ?? 'unknown'));
        }

        return new LlmResponse(
            text: $text,
            model: $model,
            latencyMs: $latencyMs,
            inputTokens: $usage['promptTokenCount'] ?? null,
            // thoughtsTokenCount is billed as output on thinking models but is
            // reported separately, so it is added in rather than lost.
            outputTokens: isset($usage['candidatesTokenCount'])
                ? (int) $usage['candidatesTokenCount'] + (int) ($usage['thoughtsTokenCount'] ?? 0)
                : null,
            finishReason: $finishReason,
            raw: $body,
        );
    }

    /**
     * Turn Google's error body into something a log reader can act on.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function describeError(?array $body, int $status): string
    {
        $message = $body['error']['message'] ?? 'Unknown error';
        $reason = $body['error']['status'] ?? null;

        return trim("Gemini API {$status}".($reason ? " ({$reason})" : '').": {$message}");
    }

    /**
     * Models this key can actually reach, for `php artisan ai:models`.
     *
     * Model ids change and documentation lags, so listing them from the live
     * API beats hard-coding a guess.
     *
     * @return array<int, array{id: string, input: int, output: int}>
     */
    public function listModels(): array
    {
        $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->timeout(30)
            ->get("{$this->baseUrl}/models", ['pageSize' => 200]);

        if ($response->failed()) {
            throw LlmException::api($this->describeError($response->json(), $response->status()), $response->status());
        }

        $models = [];

        foreach ($response->json('models') ?? [] as $model) {
            if (! in_array('generateContent', $model['supportedGenerationMethods'] ?? [], true)) {
                continue;
            }

            $models[] = [
                'id' => str_replace('models/', '', $model['name'] ?? ''),
                'input' => (int) ($model['inputTokenLimit'] ?? 0),
                'output' => (int) ($model['outputTokenLimit'] ?? 0),
            ];
        }

        usort($models, fn ($a, $b) => strcmp($a['id'], $b['id']));

        return $models;
    }
}
