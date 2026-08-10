<?php

namespace App\Providers;

use App\Services\Ai\Contracts\LlmProvider;
use App\Services\Ai\FakeProvider;
use App\Services\Ai\GeminiProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Resolves the configured LLM provider.
 *
 * Everything downstream type-hints LlmProvider, so swapping vendor is this
 * file plus one new class. A test swaps it with a single line:
 *
 *     app()->instance(LlmProvider::class, new FakeProvider);
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmProvider::class, function () {
            $name = config('ai.provider', 'fake');
            $config = config("ai.providers.{$name}", []);

            return match ($name) {
                'gemini' => new GeminiProvider(
                    apiKey: (string) ($config['key'] ?? ''),
                    model: (string) ($config['model'] ?? 'gemini-2.5-flash'),
                    baseUrl: rtrim((string) $config['base_url'], '/'),
                    timeout: (int) ($config['timeout'] ?? 90),
                    temperature: (float) ($config['temperature'] ?? 0.2),
                    maxOutputTokens: (int) ($config['max_output_tokens'] ?? 16384),
                ),

                'fake' => new FakeProvider,

                default => throw new InvalidArgumentException(
                    "Unknown AI provider [{$name}]. Set AI_PROVIDER to one of: gemini, fake."
                ),
            };
        });
    }
}
