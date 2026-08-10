<?php

namespace App\Forms;

use App\Enums\FieldType;
use Illuminate\Support\Str;

/**
 * Builds the default shape of a new field or section.
 *
 * Every field in the system is born here — the palette's click-to-add, the
 * drag-and-drop handler, the AI normaliser filling a gap, and the document
 * importer all call this rather than hand-rolling an array. That is what keeps
 * "what a field looks like" in one place.
 */
class FieldFactory
{
    /**
     * A brand-new field of the given type, ready for the canvas.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function make(FieldType $type, array $overrides = []): array
    {
        $label = $overrides['label'] ?? $type->label();

        $field = [
            'id' => self::fieldId(),
            'key' => $type->collectsAnswer() ? self::keyFrom($label) : null,
            'type' => $type->value,
            'label' => $label,
            'placeholder' => self::defaultPlaceholder($type),
            'help' => null,
            'default' => null,
            'required' => false,
            'options' => $type->hasOptions() ? self::starterOptions() : [],
            'validation' => self::defaultValidation($type),
            'conditional' => null,
        ];

        // Presentational fields collect nothing, so these keys are meaningless
        // on them. The validator rejects a schema where they are set.
        if (! $type->collectsAnswer()) {
            $field['key'] = null;
            $field['required'] = false;
            $field['placeholder'] = null;
        }

        return array_replace($field, $overrides);
    }

    /**
     * An empty section.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function makeSection(array $overrides = []): array
    {
        return array_replace([
            'id' => self::sectionId(),
            'title' => 'Untitled section',
            'description' => null,
            'fields' => [],
        ], $overrides);
    }

    /**
     * An empty but valid schema, used for a form that has no fields yet.
     *
     * @return array<string, mixed>
     */
    public static function emptySchema(string $title, ?string $description = null): array
    {
        return [
            'version' => 1,
            'title' => $title,
            'description' => $description,
            'settings' => self::defaultSettings(),
            'sections' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultSettings(): array
    {
        return [
            'multi_step' => false,
            'submit_label' => 'Submit',
            'success_message' => 'Thank you — your response has been recorded.',
            'redirect_url' => null,
        ];
    }

    // ── Identifiers ──────────────────────────────────────────────────────

    /**
     * Random rather than sequential, because ids have to survive reordering,
     * duplication and a round trip through the raw JSON editor. A positional
     * id would be wrong the moment a field moved.
     */
    public static function fieldId(): string
    {
        return 'fld_'.Str::lower(Str::random(10));
    }

    public static function sectionId(): string
    {
        return 'sec_'.Str::lower(Str::random(10));
    }

    /**
     * Derive a machine key from a human label.
     *
     * The key becomes a form input name, a JSON object key and a CSV column
     * header, so it is constrained to lowercase snake_case. Uniqueness is not
     * handled here — the caller knows the sibling keys; see uniqueKey().
     */
    public static function keyFrom(?string $label): string
    {
        $key = Str::of((string) $label)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->limit(60, '')
            ->value();

        // A label of "???" or "" leaves nothing usable, and a key starting
        // with a digit is not a valid identifier in several of the places
        // these end up.
        if ($key === '' || ! preg_match('/^[a-z]/', $key)) {
            $key = 'field_'.Str::lower(Str::random(6));
        }

        return $key;
    }

    /**
     * keyFrom(), then suffixed until it does not collide.
     *
     * @param  array<int, string>  $taken
     */
    public static function uniqueKey(?string $label, array $taken): string
    {
        $base = self::keyFrom($label);
        $key = $base;
        $n = 2;

        while (in_array($key, $taken, true)) {
            $key = $base.'_'.$n;
            $n++;
        }

        return $key;
    }

    // ── Per-type defaults ────────────────────────────────────────────────

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private static function starterOptions(): array
    {
        return [
            ['value' => 'option_1', 'label' => 'Option 1'],
            ['value' => 'option_2', 'label' => 'Option 2'],
        ];
    }

    private static function defaultPlaceholder(FieldType $type): ?string
    {
        return match ($type) {
            FieldType::Email => 'name@example.com',
            FieldType::Phone => '+91 98765 43210',
            FieldType::Url => 'https://example.com',
            FieldType::Dropdown => 'Choose…',
            default => null,
        };
    }

    /**
     * The full validation slot, with every key present and nulled.
     *
     * Keeping the shape constant means the config panel can bind to
     * `validation.max_length` without checking whether it exists, and a diff
     * between two versions never shows a key appearing from nowhere.
     *
     * @return array<string, mixed>
     */
    public static function defaultValidation(FieldType $type): array
    {
        $base = [
            'min_length' => null,
            'max_length' => null,
            'min' => null,
            'max' => null,
            'regex' => null,
            'mimes' => [],
            'max_kb' => null,
        ];

        return match ($type) {
            // A rating without bounds is meaningless, so it gets real ones.
            FieldType::Rating => array_replace($base, ['min' => 1, 'max' => 5]),

            // Sensible ceiling so an upload field is never unbounded by
            // accident. 5 MB matches the default in php.ini on most hosts.
            FieldType::File => array_replace($base, [
                'mimes' => ['pdf', 'doc', 'docx', 'png', 'jpg'],
                'max_kb' => 5120,
            ]),

            default => $base,
        };
    }
}
