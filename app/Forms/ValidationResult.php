<?php

namespace App\Forms;

/**
 * The outcome of validating a schema.
 *
 * Errors are keyed by dot-path into the schema (`sections.0.fields.2.type`) so
 * three different consumers can each use them the way they need:
 *
 *   - the raw JSON editor highlights the offending node;
 *   - the builder canvas maps the path back to a field and marks it;
 *   - the AI repair loop feeds them back to the model as plain sentences.
 *
 * Immutable: build one with `failed()` / `passed()` rather than mutating.
 */
final class ValidationResult
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function __construct(
        private readonly array $errors = [],
    ) {}

    public static function passed(): self
    {
        return new self();
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public static function failed(array $errors): self
    {
        return new self($errors);
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return ! $this->passes();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Every message as a flat list, path-prefixed.
     *
     * @return array<int, string>
     */
    public function messages(): array
    {
        $out = [];

        foreach ($this->errors as $path => $messages) {
            foreach ($messages as $message) {
                $out[] = $path === '' ? $message : "{$path}: {$message}";
            }
        }

        return $out;
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->errors));
    }

    public function first(): ?string
    {
        return $this->messages()[0] ?? null;
    }

    /**
     * Render the failures as instructions for an LLM repair pass.
     *
     * Deliberately imperative and numbered. A bare error dump invites the model
     * to apologise and re-explain; a numbered list of fixes gets a corrected
     * document back.
     */
    public function toPromptFeedback(): string
    {
        $lines = ["Your previous response failed validation. Fix exactly these problems and return the corrected JSON:"];

        foreach ($this->messages() as $i => $message) {
            $lines[] = ($i + 1).'. '.$message;
        }

        return implode("\n", $lines);
    }
}
