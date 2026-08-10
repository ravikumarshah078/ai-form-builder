<?php

namespace App\Services\Import;

/**
 * What a deterministic parser extracted from a document.
 *
 * Three things travel together, and keeping them separate matters:
 *
 *   $schema     - a normal form schema. Nothing import-specific leaks into it,
 *                 so it can go straight through SchemaValidator and, on
 *                 commit, straight into form_versions.
 *
 *   $detections - per-field confidence, keyed by field id. This is the input to
 *                 the AI step: only LOW confidence fields are sent to a model.
 *                 It lives here rather than on the field because the schema is
 *                 a published contract and would drop unknown properties.
 *
 *   $warnings   - blocks we could not interpret. The brief requires these to be
 *                 reported clearly rather than silently dropped, so they carry
 *                 the source text and survive to the review screen.
 */
final class ParseResult
{
    public const HIGH = 'high';

    public const LOW = 'low';

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, array{confidence: string, reason: string, source: string}>  $detections
     * @param  array<int, array{type: string, message: string, excerpt: string|null}>  $warnings
     */
    public function __construct(
        public readonly array $schema,
        public readonly array $detections = [],
        public readonly array $warnings = [],
        public readonly ?string $layout = null,
    ) {}

    /**
     * Ids of fields the parser was not confident about. These, and only these,
     * are worth spending an LLM call on.
     *
     * @return array<int, string>
     */
    public function uncertainFieldIds(): array
    {
        return array_keys(array_filter(
            $this->detections,
            fn (array $d) => $d['confidence'] === self::LOW
        ));
    }

    public function fieldCount(): int
    {
        $n = 0;

        foreach ($this->schema['sections'] ?? [] as $section) {
            $n += count($section['fields'] ?? []);
        }

        return $n;
    }

    public function sectionCount(): int
    {
        return count($this->schema['sections'] ?? []);
    }

    /**
     * The header shown on the review screen.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $high = count(array_filter($this->detections, fn ($d) => $d['confidence'] === self::HIGH));

        return [
            'layout' => $this->layout,
            'sections' => $this->sectionCount(),
            'fields' => $this->fieldCount(),
            'confident' => $high,
            'uncertain' => count($this->detections) - $high,
            'warnings' => count($this->warnings),
            'detections' => $this->detections,
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, array{confidence: string, reason: string, source: string}>  $detections
     */
    public function withSchema(array $schema, ?array $detections = null): self
    {
        return new self($schema, $detections ?? $this->detections, $this->warnings, $this->layout);
    }
}
