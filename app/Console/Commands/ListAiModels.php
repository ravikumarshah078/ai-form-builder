<?php

namespace App\Console\Commands;

use App\Services\Ai\Contracts\LlmProvider;
use App\Services\Ai\GeminiProvider;
use App\Services\Ai\LlmException;
use Illuminate\Console\Command;

/**
 * Lists the models the configured API key can actually reach.
 *
 * Exists because model ids move faster than documentation. Google's own docs
 * listed gemini-2.0-flash as retired while the live API still served it, and
 * listed models this key has no access to. Asking the API is the only reliable
 * way to choose a value for GEMINI_MODEL.
 */
class ListAiModels extends Command
{
    protected $signature = 'ai:models {--all : Include preview, image, audio and embedding models}';

    protected $description = 'List the AI models available to the configured API key';

    public function handle(LlmProvider $provider): int
    {
        if (! $provider instanceof GeminiProvider) {
            $this->warn("The active provider is [{$provider->name()}], which has no model list.");
            $this->line('Set GEMINI_API_KEY in .env to use the Gemini provider.');

            return self::SUCCESS;
        }

        try {
            $models = $provider->listModels();
        } catch (LlmException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('all')) {
            // Text generation only. The full list is mostly image, video, TTS
            // and embedding models that cannot produce a form schema.
            $models = array_values(array_filter($models, fn (array $m) => ! preg_match(
                '/(image|tts|audio|embedding|video|veo|lyria|robotics|computer-use|banana)/i',
                $m['id']
            )));
        }

        $current = config('ai.providers.gemini.model');

        $this->table(
            ['Model', 'Input tokens', 'Output tokens', ''],
            array_map(fn (array $m) => [
                $m['id'],
                number_format($m['input']),
                number_format($m['output']),
                $m['id'] === $current ? '← current' : '',
            ], $models),
        );

        $this->newLine();
        $this->line("Active: <info>{$current}</info>   Change it with GEMINI_MODEL in .env");

        if (! $this->option('all')) {
            $this->line('<comment>Text models only. Use --all to see everything.</comment>');
        }

        return self::SUCCESS;
    }
}
