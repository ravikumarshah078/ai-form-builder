<?php

namespace App\Services\Ai;

use App\Enums\FieldType;
use App\Services\Ai\Contracts\LlmProvider;

/**
 * A deterministic, offline stand-in for a real model.
 *
 * This is not padding. It does three jobs:
 *
 *   1. THE LIVE DEMO. The brief requires a demo URL usable with zero setup.
 *      Without a key configured, AI generation degrades to this instead of
 *      throwing a 500 at a reviewer.
 *   2. TESTS. The whole suite runs offline, for free, and with no flake from
 *      a model that phrases things differently on Tuesday.
 *   3. FAULT INJECTION. Tests need to prove the repair loop works, which means
 *      producing malformed and invalid output on demand. `queue()` does that.
 *
 * The generated form is keyword-driven, not intelligent. It is honest about
 * being canned rather than pretending to understand the prompt.
 */
class FakeProvider implements LlmProvider
{
    /**
     * Responses to hand back before falling through to the generated one.
     *
     * @var array<int, string|\Throwable>
     */
    private static array $queued = [];

    /** @var array<int, array{system: string, user: string}> */
    private static array $calls = [];

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-deterministic';
    }

    /**
     * Push an exact response (or an exception) for the next call.
     *
     * Lets a test drive the repair loop: queue malformed JSON, then valid JSON,
     * and assert the generator recovered on the second attempt.
     */
    public static function queue(string|\Throwable ...$responses): void
    {
        foreach ($responses as $response) {
            self::$queued[] = $response;
        }
    }

    public static function reset(): void
    {
        self::$queued = [];
        self::$calls = [];
    }

    /**
     * Every prompt this provider has seen, so tests can assert on what was
     * actually sent — notably that the repair attempt includes the errors.
     *
     * @return array<int, array{system: string, user: string}>
     */
    public static function calls(): array
    {
        return self::$calls;
    }

    public static function callCount(): int
    {
        return count(self::$calls);
    }

    public function generateJson(
        string $systemPrompt,
        string $userPrompt,
        array $responseSchema,
        array $options = [],
    ): LlmResponse {
        self::$calls[] = ['system' => $systemPrompt, 'user' => $userPrompt];

        if (self::$queued !== []) {
            $next = array_shift(self::$queued);

            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $this->wrap($next);
        }

        return $this->wrap(json_encode($this->invent($userPrompt)));
    }

    private function wrap(string $text): LlmResponse
    {
        return new LlmResponse(
            text: $text,
            model: $this->model(),
            // Plausible, stable numbers so the observability UI has something
            // to render without pretending to be a real measurement.
            latencyMs: 42,
            inputTokens: (int) ceil(mb_strlen($text) / 4),
            outputTokens: (int) ceil(mb_strlen($text) / 4),
            finishReason: 'STOP',
            raw: ['fake' => true],
        );
    }

    /**
     * Build a plausible form from keywords in the prompt.
     *
     * @return array<string, mixed>
     */
    private function invent(string $prompt): array
    {
        $p = mb_strtolower($prompt);

        $fields = [
            $this->field('Full name', FieldType::Text, required: true, placeholder: 'Jane Doe'),
            $this->field('Email address', FieldType::Email, required: true, placeholder: 'jane@example.com'),
        ];

        if ($this->mentions($p, ['phone', 'mobile', 'contact', 'number'])) {
            $fields[] = $this->field('Phone number', FieldType::Phone, placeholder: '+91 98765 43210');
        }

        if ($this->mentions($p, ['address', 'location', 'city'])) {
            $fields[] = $this->field('Address', FieldType::Textarea);
        }

        $sections = [[
            'title' => 'Personal details',
            'fields' => $fields,
        ]];

        if ($this->mentions($p, ['education', 'degree', 'university', 'college', 'academic', 'qualification'])) {
            $sections[] = ['title' => 'Education history', 'fields' => [
                $this->field('Institution', FieldType::Text, required: true),
                $this->field('Degree', FieldType::Dropdown, options: ['B.Sc.', 'B.Tech.', 'BCA', 'M.Sc.', 'Other']),
                $this->field('Graduation date', FieldType::Date),
            ]];
        }

        if ($this->mentions($p, ['skill', 'technology', 'language', 'expertise', 'proficien'])) {
            $sections[] = ['title' => 'Skills', 'fields' => [
                $this->field('Which of these have you worked with?', FieldType::Checkbox,
                    options: ['PHP', 'Laravel', 'MySQL', 'JavaScript', 'Python']),
                $this->field('Years of experience', FieldType::Number),
            ]];
        }

        if ($this->mentions($p, ['resume', 'cv', 'upload', 'attach', 'document', 'file', 'portfolio'])) {
            $sections[] = ['title' => 'Attachments', 'fields' => [
                $this->field('Resume', FieldType::File, required: true,
                    help: 'PDF or Word document, up to 5 MB.'),
            ]];
        }

        if ($this->mentions($p, ['feedback', 'rate', 'rating', 'satisfaction', 'review', 'survey'])) {
            $sections[] = ['title' => 'Feedback', 'fields' => [
                $this->field('How would you rate your experience?', FieldType::Rating),
                $this->field('Any additional comments?', FieldType::Textarea),
            ]];
        }

        return [
            'title' => $this->titleFrom($prompt),
            'description' => 'Generated offline by the fake provider. Configure GEMINI_API_KEY for real generation.',
            'sections' => $sections,
        ];
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function mentions(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function titleFrom(string $prompt): string
    {
        // First clause of the prompt, tidied into a title.
        $title = trim(preg_split('/[.\n,;]/', $prompt)[0] ?? '');
        $title = preg_replace('/^(create|build|make|generate|design)\s+(me\s+)?(an?\s+)?/i', '', $title);

        return ucfirst(mb_substr($title ?: 'Untitled form', 0, 120));
    }

    /**
     * @param  array<int, string>  $options
     * @return array<string, mixed>
     */
    private function field(
        string $label,
        FieldType $type,
        bool $required = false,
        ?string $placeholder = null,
        ?string $help = null,
        array $options = [],
    ): array {
        $field = [
            'label' => $label,
            'type' => $type->value,
            'required' => $required,
        ];

        if ($placeholder !== null) {
            $field['placeholder'] = $placeholder;
        }

        if ($help !== null) {
            $field['help'] = $help;
        }

        if ($options !== []) {
            // Bare strings on purpose: the normaliser turns them into
            // value/label pairs, which is the same path a real model's looser
            // output takes.
            $field['options'] = $options;
        }

        return $field;
    }
}
