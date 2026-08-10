<?php

namespace App\Services\Ai;

use App\Forms\SchemaNormaliser;
use App\Forms\SchemaReconciler;
use App\Forms\SchemaValidator;
use App\Forms\ValidationResult;
use App\Models\AiGeneration;
use App\Services\Ai\Contracts\LlmProvider;
use Illuminate\Support\Facades\Log;

/**
 * Turns a prompt into a validated form schema, and records what it cost.
 *
 * The brief asks for three things this class provides:
 *
 *   "Output must be schema-valid"          - nothing leaves here unvalidated.
 *   "validate, repair or retry, and never
 *    persist a broken schema"              - the loop below, in that order.
 *   "Log model, tokens and latency"        - written to the AiGeneration row.
 *
 * The order of operations matters and is worth being able to explain:
 *
 *   call  ->  extract  ->  normalise  ->  validate
 *                                            |
 *                              passes -------+------- fails
 *                                 |                     |
 *                              done            feed the errors back
 *                                              and ask for a correction
 *
 * NORMALISE BEFORE VALIDATE is the key decision. A model that writes "select"
 * instead of "dropdown", or gives options as bare strings, is not wrong in any
 * way worth spending a round trip on — that is mechanically repairable. Only
 * genuine mistakes reach the retry, so the retry budget is spent on real
 * problems.
 */
class FormGenerator
{
    public function __construct(
        private readonly LlmProvider $provider,
        private readonly SchemaNormaliser $normaliser = new SchemaNormaliser,
        private readonly SchemaValidator $validator = new SchemaValidator,
        private readonly SchemaReconciler $reconciler = new SchemaReconciler,
    ) {}

    /**
     * Run a generation to completion, updating its row as it goes.
     *
     * The AiGeneration row is both the audit log and the status the browser
     * polls, so it is written at every transition rather than only at the end.
     */
    public function run(AiGeneration $generation): AiGeneration
    {
        $generation->update([
            'status' => 'running',
            'provider' => $this->provider->name(),
            'model' => $this->provider->model(),
        ]);

        $isEdit = $generation->mode === 'edit';

        $system = $isEdit ? FormPrompt::editSystem() : FormPrompt::createSystem();

        $user = $isEdit
            ? FormPrompt::editUser($generation->input_schema ?? [], $generation->prompt)
            : FormPrompt::createUser($generation->prompt);

        $schema = FormPrompt::responseSchema();

        $maxAttempts = max(1, (int) config('ai.max_attempts', 3));

        $totalLatency = 0;
        $totalInput = 0;
        $totalOutput = 0;
        $lastRaw = null;
        $lastErrors = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->provider->generateJson($system, $user, $schema);
            } catch (LlmException $e) {
                // A transport failure has nothing to repair. Retry only if the
                // provider said it was worth retrying, and never on the last
                // attempt.
                if ($e->retryable && $attempt < $maxAttempts) {
                    // Linear backoff; these are seconds-scale problems.
                    usleep(($attempt * 500) * 1000);

                    continue;
                }

                return $this->fail($generation, $e->getMessage(), $attempt, $totalLatency, $totalInput, $totalOutput, $lastRaw);
            }

            $totalLatency += $response->latencyMs;
            $totalInput += (int) $response->inputTokens;
            $totalOutput += (int) $response->outputTokens;
            $lastRaw = $response->text;

            // A truncated reply is not a model mistake, so say so plainly
            // rather than reporting it as invalid JSON.
            if ($response->wasTruncated()) {
                $lastErrors = ValidationResult::failed([
                    '' => ['The response was cut off before the form was complete. Return a shorter form.'],
                ]);

                $user = FormPrompt::repairUser($response->text, $lastErrors);

                continue;
            }

            $decoded = $this->extractJson($response->text);

            if ($decoded === null) {
                $lastErrors = ValidationResult::failed([
                    '' => ['The response was not valid JSON. Return a single JSON object and nothing else.'],
                ]);

                $user = FormPrompt::repairUser($response->text, $lastErrors);

                continue;
            }

            // Mechanical repair first: aliases, missing keys and ids, bare
            // string options. None of that is worth a round trip.
            $normalised = $this->normaliser->normalise(
                $decoded,
                $generation->form?->title ?? 'Untitled form'
            );

            // On an edit, restore the identity of every field that survived.
            // Models drop `key` from fields they are keeping, and the
            // normaliser then derives a fresh one from the label — which
            // silently orphans every answer already collected against it.
            // Enforced in code because the prompt asking for it is not enough.
            if ($isEdit && $generation->input_schema !== null) {
                $normalised = $this->reconciler->reconcile($generation->input_schema, $normalised);
            }

            $result = $this->validator->validate($normalised);

            if ($result->passes()) {
                return $this->succeed(
                    $generation, $normalised, $attempt,
                    $totalLatency, $totalInput, $totalOutput, $response->text
                );
            }

            $lastErrors = $result;

            Log::info('AI schema failed validation, repairing', [
                'generation' => $generation->uuid,
                'attempt' => $attempt,
                'errors' => $result->messages(),
            ]);

            // Feed the exact errors back. This is the whole repair mechanism.
            $user = FormPrompt::repairUser($response->text, $result);
        }

        return $this->fail(
            $generation,
            'The model could not produce a valid schema after '.$maxAttempts.' attempts. '
                .'Last errors: '.implode(' | ', $lastErrors?->messages() ?? ['unknown']),
            $maxAttempts, $totalLatency, $totalInput, $totalOutput, $lastRaw
        );
    }

    /**
     * Pull a JSON object out of a model response.
     *
     * responseSchema means we should receive bare JSON, but this survives the
     * two things that still happen: a markdown fence, and a sentence of
     * preamble before the object.
     *
     * @return array<string, mixed>|null
     */
    public function extractJson(string $text): ?array
    {
        $text = trim($text);

        // ```json … ```
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $m)) {
            $text = trim($m[1]);
        }

        $decoded = json_decode($text, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Fall back to the outermost braces, which handles a leading
        // "Here is your form:" that the fence check missed.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function succeed(
        AiGeneration $generation,
        array $schema,
        int $attempts,
        int $latency,
        int $input,
        int $output,
        string $raw,
    ): AiGeneration {
        // result_schema is only ever written after validation passes, so a
        // broken schema cannot reach form_versions through this path.
        $generation->update([
            'status' => 'succeeded',
            'attempts' => $attempts,
            'latency_ms' => $latency,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'raw_response' => $raw,
            'result_schema' => $schema,
            'error' => null,
        ]);

        return $generation->refresh();
    }

    private function fail(
        AiGeneration $generation,
        string $error,
        int $attempts,
        int $latency,
        int $input,
        int $output,
        ?string $raw,
    ): AiGeneration {
        Log::warning('AI generation failed', [
            'generation' => $generation->uuid,
            'attempts' => $attempts,
            'error' => $error,
        ]);

        $generation->update([
            'status' => 'failed',
            'attempts' => $attempts,
            'latency_ms' => $latency,
            'input_tokens' => $input,
            'output_tokens' => $output,
            // Kept even on failure: improving the repair step is impossible
            // without seeing exactly what came back.
            'raw_response' => $raw,
            'error' => $error,
        ]);

        return $generation->refresh();
    }
}
