<?php

namespace App\Services\Import;

use App\Enums\FieldType;
use App\Services\Ai\Contracts\LlmProvider;
use App\Services\Ai\LlmException;
use Illuminate\Support\Facades\Log;

/**
 * THE AI HALF of the import hybrid.
 *
 * The brief asks us to "parse deterministically first, use AI only to infer
 * types and validations where the document is ambiguous" and to explain the
 * split. This class is that split, made literal.
 *
 * What it does NOT do is as important as what it does:
 *
 *   - It never sees the whole document. Only the questions the parser marked
 *     LOW confidence are sent.
 *   - It cannot add, remove or reorder fields. It answers a closed question
 *     about existing ones: "what type is this?"
 *   - It cannot rename anything. Labels, keys and ids come from the document.
 *   - If it fails, is unavailable, or returns nonsense, the deterministic
 *     result stands. Import never depends on it.
 *
 * That containment is what makes the AI step safe to run on a stranger's
 * document: the worst case is that a field keeps the type the parser guessed.
 */
class SchemaRefiner
{
    public function __construct(
        private readonly LlmProvider $provider,
    ) {}

    /**
     * @return array{schema: array<string, mixed>, detections: array<string, mixed>, used: bool, tokens: int, latency_ms: int}
     */
    public function refine(ParseResult $result): array
    {
        $uncertain = $result->uncertainFieldIds();

        $unchanged = [
            'schema' => $result->schema,
            'detections' => $result->detections,
            'used' => false,
            'tokens' => 0,
            'latency_ms' => 0,
        ];

        // Nothing ambiguous: the deterministic pass settled every field, so
        // there is no call to make. On a well-formed definition spreadsheet
        // this is the normal outcome, and the import costs nothing.
        if ($uncertain === []) {
            return $unchanged;
        }

        $questions = $this->questionsFor($result, $uncertain);

        if ($questions === []) {
            return $unchanged;
        }

        try {
            $response = $this->provider->generateJson(
                $this->systemPrompt(),
                $this->userPrompt($questions),
                $this->responseSchema(),
            );
        } catch (LlmException $e) {
            // Degrade, never fail. The parser's guesses are still usable.
            Log::info('Import refinement skipped', ['error' => $e->getMessage()]);

            return $unchanged;
        }

        $decoded = json_decode($response->text, true);

        if (! is_array($decoded) || ! isset($decoded['fields']) || ! is_array($decoded['fields'])) {
            return $unchanged;
        }

        [$schema, $detections] = $this->apply($result, $decoded['fields'], $uncertain);

        return [
            'schema' => $schema,
            'detections' => $detections,
            'used' => true,
            'tokens' => $response->totalTokens(),
            'latency_ms' => $response->latencyMs,
        ];
    }

    /**
     * The ambiguous questions, with their surrounding section for context.
     *
     * Section titles are included because "Details" under "Emergency contact"
     * means something different from "Details" under "Payment".
     *
     * @param  array<int, string>  $uncertain
     * @return array<int, array<string, mixed>>
     */
    private function questionsFor(ParseResult $result, array $uncertain): array
    {
        $out = [];

        foreach ($result->schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (! in_array($field['id'], $uncertain, true)) {
                    continue;
                }

                $out[] = [
                    'id' => $field['id'],
                    'section' => $section['title'] ?? null,
                    'question' => $field['label'] ?? '',
                    'current_guess' => $field['type'] ?? 'text',
                ];
            }
        }

        return $out;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You classify form questions extracted from a document.

        For each question you are given, decide which field type best collects
        the answer, and whether any validation is genuinely implied.

        Rules:

        - Return JSON only, with one entry per question, using the id given.
        - Do not invent, merge, split or rename questions. Answer only about the
          ones supplied.
        - Choose the most specific type that fits. Prefer "text" only when
          nothing more specific applies.
        - Set "options" only when the question clearly implies a small fixed set
          of answers that the document did not list, such as a yes/no question.
          Otherwise omit it.
        - Set "required" true only when the wording says so.
        - Add "validation" only where a real constraint is implied, such as a
          maximum length for a short code. Do not invent regular expressions.
        - If you are not confident, keep the current guess.
        PROMPT;
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     */
    private function userPrompt(array $questions): string
    {
        $json = json_encode($questions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "Classify these questions extracted from an uploaded document:\n\n```json\n{$json}\n```";
    }

    /**
     * @return array<string, mixed>
     */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['fields'],
            'properties' => [
                'fields' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['id', 'type'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            // Same allow-list as everywhere else in the app.
                            'type' => ['type' => 'string', 'enum' => FieldType::values()],
                            'required' => ['type' => 'boolean'],
                            'options' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['value', 'label'],
                                    'properties' => [
                                        'value' => ['type' => 'string'],
                                        'label' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                            'validation' => [
                                'type' => 'object',
                                'properties' => [
                                    'min_length' => ['type' => 'integer'],
                                    'max_length' => ['type' => 'integer'],
                                    'min' => ['type' => 'number'],
                                    'max' => ['type' => 'number'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Merge the model's answers back in, touching nothing it was not asked about.
     *
     * @param  array<int, mixed>  $answers
     * @param  array<int, string>  $uncertain
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function apply(ParseResult $result, array $answers, array $uncertain): array
    {
        $byId = [];

        foreach ($answers as $answer) {
            if (is_array($answer) && isset($answer['id']) && is_string($answer['id'])) {
                $byId[$answer['id']] = $answer;
            }
        }

        $schema = $result->schema;
        $detections = $result->detections;

        foreach ($schema['sections'] ?? [] as $si => $section) {
            foreach ($section['fields'] ?? [] as $fi => $field) {
                $id = $field['id'] ?? null;

                // Silently ignore an answer about a field we did not ask about.
                if ($id === null || ! in_array($id, $uncertain, true) || ! isset($byId[$id])) {
                    continue;
                }

                $answer = $byId[$id];
                $type = FieldType::tryFrom($answer['type'] ?? '');

                if ($type === null) {
                    continue;
                }

                $changed = $type->value !== ($field['type'] ?? null);

                $schema['sections'][$si]['fields'][$fi]['type'] = $type->value;

                if (isset($answer['required']) && is_bool($answer['required']) && $type->collectsAnswer()) {
                    $schema['sections'][$si]['fields'][$fi]['required'] = $answer['required'];
                }

                // Options only make sense on a choice type, and only if the
                // document did not already supply them.
                if ($type->hasOptions()) {
                    $existing = $field['options'] ?? [];

                    if ($existing === [] && ! empty($answer['options'])) {
                        $schema['sections'][$si]['fields'][$fi]['options'] = $answer['options'];
                    }
                } else {
                    $schema['sections'][$si]['fields'][$fi]['options'] = [];
                }

                if (! empty($answer['validation']) && is_array($answer['validation'])) {
                    $schema['sections'][$si]['fields'][$fi]['validation'] = array_replace(
                        $field['validation'] ?? [],
                        array_intersect_key($answer['validation'], array_flip(['min_length', 'max_length', 'min', 'max'])),
                    );
                }

                // The review screen shows this, so the user can see which
                // types came from the document and which from a model.
                $detections[$id] = [
                    'confidence' => ParseResult::HIGH,
                    'reason' => $changed
                        ? 'AI changed this from "'.($field['type'] ?? '?').'" to "'.$type->value.'"'
                        : 'AI confirmed the parser\'s guess',
                    'source' => 'ai',
                ];
            }
        }

        return [$schema, $detections];
    }
}
