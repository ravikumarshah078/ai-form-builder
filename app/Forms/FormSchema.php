<?php

namespace App\Forms;

use App\Enums\FieldType;
use App\Models\FormVersion;

/**
 * Read-oriented value object over a schema array.
 *
 * Exists so the rest of the app stops writing `$schema['sections'][0]['fields']`
 * everywhere. Mutation belongs to the Livewire builder, which owns the array
 * while it is being edited; this wraps a settled schema for reading.
 *
 * Construct with `from()` for input of unknown provenance — it normalises on
 * the way in. Use `wrap()` only for a schema already known to be clean, such
 * as one loaded straight from a form_versions row.
 */
final class FormSchema
{
    /**
     * @param  array<string, mixed>  $schema
     */
    private function __construct(
        private readonly array $schema,
    ) {}

    /**
     * Normalise, then wrap. Safe for LLM output, imported documents and
     * hand-edited JSON.
     *
     * @param  mixed  $input
     */
    public static function from($input, string $fallbackTitle = 'Untitled form'): self
    {
        return new self((new SchemaNormaliser)->normalise($input, $fallbackTitle));
    }

    /**
     * Wrap without normalising.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function wrap(array $schema): self
    {
        return new self($schema);
    }

    public static function fromVersion(FormVersion $version): self
    {
        return new self($version->schema ?? []);
    }

    // ── Access ───────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->schema;
    }

    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->schema, $flags);
    }

    public function title(): string
    {
        return $this->schema['title'] ?? '';
    }

    public function description(): ?string
    {
        return $this->schema['description'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return array_replace(FieldFactory::defaultSettings(), $this->schema['settings'] ?? []);
    }

    public function setting(string $key, $default = null)
    {
        return $this->settings()[$key] ?? $default;
    }

    public function isMultiStep(): bool
    {
        return (bool) $this->setting('multi_step', false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sections(): array
    {
        return $this->schema['sections'] ?? [];
    }

    /**
     * Every field, flattened across sections, in display order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        $out = [];

        foreach ($this->sections() as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $out[] = $field;
            }
        }

        return $out;
    }

    /**
     * Only the fields that collect an answer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function answerFields(): array
    {
        return array_values(array_filter(
            $this->fields(),
            fn (array $f) => FieldType::tryFrom($f['type'] ?? '')?->collectsAnswer() === true
                && ! empty($f['key'])
        ));
    }

    /**
     * @return array<string, string>  key => label, for CSV headers and tables
     */
    public function answerLabels(): array
    {
        $out = [];

        foreach ($this->answerFields() as $field) {
            $out[$field['key']] = $field['label'] ?? $field['key'];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function field(string $key): ?array
    {
        foreach ($this->fields() as $field) {
            if (($field['key'] ?? null) === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fieldById(string $id): ?array
    {
        foreach ($this->fields() as $field) {
            if (($field['id'] ?? null) === $id) {
                return $field;
            }
        }

        return null;
    }

    public function fieldCount(): int
    {
        return count($this->fields());
    }

    public function sectionCount(): int
    {
        return count($this->sections());
    }

    public function isEmpty(): bool
    {
        return $this->fieldCount() === 0;
    }

    // ── Derived ──────────────────────────────────────────────────────────

    public function validate(): ValidationResult
    {
        return (new SchemaValidator)->validate($this->schema);
    }

    public function isValid(): bool
    {
        return $this->validate()->passes();
    }

    /**
     * @return array{rules: array<string, mixed>, attributes: array<string, string>, messages: array<string, string>}
     */
    public function rules(): array
    {
        return (new RuleCompiler)->compile($this->schema);
    }

    /**
     * Stable fingerprint. Identical to FormVersion::checksumFor(), which is
     * what makes "has this actually changed?" a cheap string comparison.
     */
    public function checksum(): string
    {
        return FormVersion::checksumFor($this->schema);
    }

    /**
     * Render one stored answer for display or export.
     *
     * Lives here because it needs the field definition — a checkbox answer of
     * ["php","sql"] should read "PHP, SQL", which requires the option labels.
     *
     * @param  mixed  $value
     */
    public function displayAnswer(string $key, $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        $field = $this->field($key);

        if ($field === null) {
            return is_scalar($value) ? (string) $value : json_encode($value);
        }

        $type = FieldType::tryFrom($field['type'] ?? '');

        // Map stored option values back to their human labels.
        if ($type?->hasOptions()) {
            $labels = [];

            foreach ($field['options'] ?? [] as $option) {
                $labels[(string) ($option['value'] ?? '')] = $option['label'] ?? $option['value'] ?? '';
            }

            $values = is_array($value) ? $value : [$value];

            return implode(', ', array_map(
                fn ($v) => $labels[(string) $v] ?? (string) $v,
                $values
            ));
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value));
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return (string) $value;
    }
}
