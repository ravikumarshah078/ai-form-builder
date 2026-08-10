<?php

namespace App\Forms;

use App\Enums\FieldType;

/**
 * Turns near-miss input into a well-formed schema.
 *
 * SchemaValidator answers "is this valid?". This class answers "can I make it
 * valid without guessing at intent?". Run it first, always.
 *
 * Three callers, three different kinds of mess:
 *
 *   - THE AI (Part B) returns a document that is usually right but may use
 *     `"select"` instead of `"dropdown"`, omit ids, or give options as bare
 *     strings. Repairing that mechanically is far better than burning a retry.
 *   - THE IMPORTER (Part C) produces fields from prose, where keys and ids do
 *     not exist at all until we invent them.
 *   - THE RAW JSON EDITOR, where a human is typing into a textarea and will
 *     leave out `"validation"` on half the fields.
 *
 * The rule this class obeys: repair only what is UNAMBIGUOUS. A `type` of
 * "select" clearly means dropdown. A `type` of "widget" means nothing, so it
 * is left alone for the validator to reject. Silently inventing intent is how
 * you end up with a form that is valid and wrong.
 */
class SchemaNormaliser
{
    /**
     * Type names an LLM or a document parser plausibly emits, mapped to ours.
     *
     * This table is the difference between a retry and a repair. Every entry
     * was chosen because it has exactly one sensible reading.
     */
    private const TYPE_ALIASES = [
        // text
        'string' => 'text', 'input' => 'text', 'textbox' => 'text',
        'text_input' => 'text', 'short_text' => 'text', 'singleline' => 'text',
        'single_line_text' => 'text', 'name' => 'text',

        // textarea
        'long_text' => 'textarea', 'multiline' => 'textarea', 'paragraph_text' => 'textarea',
        'text_area' => 'textarea', 'multiline_text' => 'textarea', 'comment' => 'textarea',

        // number
        'integer' => 'number', 'int' => 'number', 'float' => 'number',
        'decimal' => 'number', 'numeric' => 'number', 'range' => 'number',

        // email / phone / url
        'email_address' => 'email', 'e-mail' => 'email', 'mail' => 'email',
        'tel' => 'phone', 'telephone' => 'phone', 'mobile' => 'phone',
        'phone_number' => 'phone', 'link' => 'url', 'website' => 'url',

        // date & time
        'datepicker' => 'date', 'date_picker' => 'date', 'day' => 'date',
        'timepicker' => 'time', 'datetime-local' => 'datetime',
        'date_time' => 'datetime', 'timestamp' => 'datetime',

        // choice
        'select' => 'dropdown', 'combobox' => 'dropdown', 'picklist' => 'dropdown',
        'single_select' => 'dropdown', 'choice' => 'radio', 'radio_group' => 'radio',
        'radio_buttons' => 'radio', 'option' => 'radio',
        'multiselect' => 'checkbox', 'multi_select' => 'checkbox',
        'checkboxes' => 'checkbox', 'checkbox_group' => 'checkbox',
        'boolean' => 'checkbox', 'bool' => 'checkbox', 'toggle' => 'checkbox',
        'stars' => 'rating', 'star_rating' => 'rating', 'scale' => 'rating',

        // upload
        'upload' => 'file', 'file_upload' => 'file', 'attachment' => 'file',
        'image' => 'file', 'document' => 'file',

        // presentational
        'title' => 'heading', 'header' => 'heading', 'section_heading' => 'heading',
        'subheading' => 'heading', 'label' => 'heading',
        'description' => 'paragraph', 'text_block' => 'paragraph',
        'static_text' => 'paragraph', 'info' => 'paragraph',
        'separator' => 'divider', 'hr' => 'divider', 'line' => 'divider',
        'new_line' => 'divider', 'spacer' => 'divider',
    ];

    /**
     * Media-type subtypes that do not match their file extension.
     *
     * Only the awkward ones. "application/pdf" already reduces to "pdf" by
     * taking the part after the slash; these do not.
     */
    private const MIME_SUBTYPES = [
        'msword' => 'doc',
        'vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'vnd.ms-excel' => 'xls',
        'vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'vnd.ms-powerpoint' => 'ppt',
        'vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'vnd.oasis.opendocument.text' => 'doc',
        'vnd.oasis.opendocument.spreadsheet' => 'xls',
        'plain' => 'txt',
        'x-zip-compressed' => 'zip',
        'zip-compressed' => 'zip',
        'svg+xml' => 'svg',
        'comma-separated-values' => 'csv',
        'jpg' => 'jpg',
        'pjpeg' => 'jpg',
    ];

    /** Keys taken so far, so generated keys never collide. */
    private array $usedKeys = [];

    private array $usedFieldIds = [];

    private array $usedSectionIds = [];

    /**
     * @param  mixed  $input
     * @return array<string, mixed>
     */
    public function normalise($input, string $fallbackTitle = 'Untitled form'): array
    {
        $this->usedKeys = [];
        $this->usedFieldIds = [];
        $this->usedSectionIds = [];

        if (! is_array($input)) {
            $input = [];
        }

        // Some models wrap the payload: {"form": {...}} or {"schema": {...}}.
        foreach (['form', 'schema', 'data', 'result'] as $wrapper) {
            if (isset($input[$wrapper]) && is_array($input[$wrapper]) && ! isset($input['sections'])) {
                $input = $input[$wrapper];
                break;
            }
        }

        $title = $this->str($input['title'] ?? null) ?? $fallbackTitle;

        return [
            'version' => is_int($input['version'] ?? null) && $input['version'] >= 1
                ? $input['version']
                : 1,
            'title' => mb_substr($title, 0, 200),
            'description' => $this->str($input['description'] ?? null),
            'settings' => $this->normaliseSettings($input['settings'] ?? null),
            'sections' => $this->normaliseSections($input),
        ];
    }

    // ── Settings ─────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function normaliseSettings($settings): array
    {
        $defaults = FieldFactory::defaultSettings();

        if (! is_array($settings)) {
            return $defaults;
        }

        $redirect = $this->str($settings['redirect_url'] ?? null);

        // Drop anything that is not an http(s) URL rather than failing later.
        if ($redirect !== null && ! preg_match('#^https?://#i', $redirect)) {
            $redirect = null;
        }

        return [
            'multi_step' => (bool) ($settings['multi_step'] ?? $defaults['multi_step']),
            'submit_label' => $this->str($settings['submit_label'] ?? null) ?? $defaults['submit_label'],
            'success_message' => $this->str($settings['success_message'] ?? null) ?? $defaults['success_message'],
            'redirect_url' => $redirect,
        ];
    }

    // ── Sections ─────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normaliseSections(array $input): array
    {
        $sections = $input['sections'] ?? null;

        // A flat {"fields": [...]} with no sections is a very common LLM
        // shape, and an entirely reasonable one. Wrap it in a single section
        // rather than rejecting it.
        if (! is_array($sections) && isset($input['fields']) && is_array($input['fields'])) {
            $sections = [['title' => null, 'fields' => $input['fields']]];
        }

        if (! is_array($sections)) {
            return [];
        }

        // Guard against {"0": {...}, "1": {...}} coming back from json_decode
        // on a JSON object with numeric keys.
        $sections = array_values($sections);

        $out = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $fields = $section['fields'] ?? [];

            if (! is_array($fields)) {
                $fields = [];
            }

            $normalisedFields = [];

            foreach (array_values($fields) as $field) {
                $normalised = $this->normaliseField($field);

                if ($normalised !== null) {
                    $normalisedFields[] = $normalised;
                }
            }

            $out[] = [
                'id' => $this->sectionId($section['id'] ?? null),
                'title' => $this->str($section['title'] ?? $section['name'] ?? null),
                'description' => $this->str($section['description'] ?? null),
                'fields' => $normalisedFields,
            ];
        }

        return $out;
    }

    // ── Fields ───────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null  null when the input is unsalvageable
     */
    private function normaliseField($field): ?array
    {
        if (! is_array($field)) {
            return null;
        }

        $type = $this->resolveType($field);

        // Unknown type: keep the field but leave `type` exactly as supplied so
        // the validator can name it in the error. Repairing it would mean
        // guessing, and a wrong guess is worse than an honest failure.
        if ($type === null) {
            return array_replace(
                FieldFactory::make(FieldType::Text),
                [
                    'type' => is_string($field['type'] ?? null) ? $field['type'] : null,
                    'label' => $this->str($field['label'] ?? null) ?? 'Untitled field',
                ]
            );
        }

        $label = $this->str($field['label'] ?? $field['name'] ?? $field['question'] ?? null);

        if ($label === null && $type !== FieldType::Divider) {
            $label = $type->label();
        }

        $out = [
            'id' => $this->fieldId($field['id'] ?? null),
            'type' => $type->value,
            'label' => $label === null ? '' : mb_substr($label, 0, 500),
            'placeholder' => $type->collectsAnswer() ? $this->str($field['placeholder'] ?? null) : null,
            'help' => $this->str($field['help'] ?? $field['help_text'] ?? $field['hint'] ?? null),
            'default' => $field['default'] ?? null,
            'required' => $type->collectsAnswer() && $this->bool($field['required'] ?? false),
            'options' => $type->hasOptions() ? $this->normaliseOptions($field['options'] ?? []) : [],
            'validation' => $this->normaliseValidation($field['validation'] ?? $field['rules'] ?? null, $type),
            'conditional' => is_array($field['conditional'] ?? null) ? $field['conditional'] : null,
        ];

        // Key last: it may need to be derived from the label we just settled.
        $out['key'] = $type->collectsAnswer()
            ? $this->fieldKey($field['key'] ?? $field['name'] ?? null, $out['label'])
            : null;

        // A choice field with no options is unusable. Two placeholders are a
        // better outcome than a validation failure the user cannot act on.
        if ($type->hasOptions() && $out['options'] === []) {
            $out['options'] = [
                ['value' => 'option_1', 'label' => 'Option 1'],
                ['value' => 'option_2', 'label' => 'Option 2'],
            ];
        }

        return $out;
    }

    /**
     * Map whatever arrived onto a FieldType, via the alias table.
     */
    private function resolveType(array $field): ?FieldType
    {
        $raw = $field['type'] ?? $field['field_type'] ?? $field['input_type'] ?? null;

        if (! is_string($raw)) {
            return null;
        }

        $key = self::normaliseTypeKey($raw);

        if ($direct = FieldType::tryFrom($key)) {
            return $direct;
        }

        $aliases = self::aliases();

        if (isset($aliases[$key])) {
            return FieldType::from($aliases[$key]);
        }

        return null;
    }

    /**
     * Both sides of the alias lookup go through this.
     *
     * Doing it to the incoming value only was a bug: "e-mail" became "e_mail"
     * before the lookup, so the table's "e-mail" key could never match. Running
     * the table's keys through the same function means an entry can be written
     * whichever way reads best without becoming unreachable.
     */
    private static function normaliseTypeKey(string $raw): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim($raw)));
    }

    /**
     * TYPE_ALIASES with every key normalised. Built once per request.
     *
     * @return array<string, string>
     */
    private static function aliases(): array
    {
        static $map = null;

        if ($map === null) {
            $map = [];

            foreach (self::TYPE_ALIASES as $alias => $target) {
                $map[self::normaliseTypeKey($alias)] = $target;
            }
        }

        return $map;
    }

    /**
     * Accept the three option shapes we actually see in the wild:
     *
     *   ["Yes", "No"]                                  bare strings
     *   [{"value": "y", "label": "Yes"}]               our own shape
     *   {"y": "Yes", "n": "No"}                        value-keyed object
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function normaliseOptions($options): array
    {
        if (! is_array($options) || $options === []) {
            return [];
        }

        $out = [];
        $seen = [];

        // Value-keyed object.
        if (! array_is_list($options)) {
            foreach ($options as $value => $label) {
                $options[] = ['value' => (string) $value, 'label' => is_string($label) ? $label : (string) $value];
            }

            $options = array_values(array_filter($options, 'is_array'));
        }

        foreach ($options as $option) {
            if (is_string($option) || is_int($option) || is_float($option)) {
                $label = trim((string) $option);
                $value = FieldFactory::keyFrom($label);
            } elseif (is_array($option)) {
                $label = $this->str($option['label'] ?? $option['text'] ?? $option['name'] ?? null);
                $rawValue = $option['value'] ?? $option['id'] ?? null;

                if ($label === null && $rawValue !== null) {
                    $label = (string) $rawValue;
                }

                if ($label === null) {
                    continue;
                }

                $value = is_string($rawValue) || is_int($rawValue)
                    ? (string) $rawValue
                    : FieldFactory::keyFrom($label);
            } else {
                continue;
            }

            if ($label === '' || $value === '') {
                continue;
            }

            // De-duplicate by value; a repeated value would make the answer
            // ambiguous.
            if (in_array($value, $seen, true)) {
                continue;
            }

            $seen[] = $value;
            $out[] = ['value' => $value, 'label' => $label];

            if (count($out) >= 200) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function normaliseValidation($rules, FieldType $type): array
    {
        $out = FieldFactory::defaultValidation($type);

        if (! is_array($rules)) {
            return $out;
        }

        // Accept a few common aliases for the length/bound keys.
        $map = [
            'minlength' => 'min_length', 'min_len' => 'min_length', 'minimum_length' => 'min_length',
            'maxlength' => 'max_length', 'max_len' => 'max_length', 'maximum_length' => 'max_length',
            'minimum' => 'min', 'maximum' => 'max',
            'pattern' => 'regex',
            'max_size' => 'max_kb', 'max_size_kb' => 'max_kb', 'maxsize' => 'max_kb',
            'accept' => 'mimes', 'extensions' => 'mimes', 'file_types' => 'mimes',
        ];

        foreach ($rules as $key => $value) {
            $key = $map[strtolower((string) $key)] ?? strtolower((string) $key);

            if (! array_key_exists($key, $out)) {
                continue; // unknown rule, dropped
            }

            $out[$key] = match ($key) {
                'min_length', 'max_length', 'max_kb' => is_numeric($value) ? (int) $value : null,
                'min', 'max' => is_numeric($value) ? $value + 0 : null,
                'regex' => $this->str($value),
                'mimes' => $this->normaliseMimes($value),
                default => $value,
            };
        }

        // Rules that do not apply to this type are silently discarded rather
        // than left to trip the validator.
        if ($type !== FieldType::File) {
            $out['mimes'] = [];
            $out['max_kb'] = null;
        }

        // An inverted range is a repairable mistake: swap it.
        foreach ([['min_length', 'max_length'], ['min', 'max']] as [$low, $high]) {
            if (is_numeric($out[$low]) && is_numeric($out[$high]) && $out[$low] > $out[$high]) {
                [$out[$low], $out[$high]] = [$out[$high], $out[$low]];
            }
        }

        // A pattern that will not compile is worse than no pattern. Shares the
        // validator's definition of "compilable" so the two cannot disagree.
        if (is_string($out['regex']) && $out['regex'] !== '') {
            if (! SchemaValidator::isCompilableRegex($out['regex'])) {
                $out['regex'] = null;
            }
        } else {
            $out['regex'] = null;
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function normaliseMimes($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,\s|]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $mime) {
            if (! is_string($mime)) {
                continue;
            }

            // Accept ".pdf", "PDF" and "application/pdf".
            $mime = strtolower(trim($mime));
            $mime = ltrim($mime, '.');

            if (str_contains($mime, '/')) {
                $mime = substr($mime, strrpos($mime, '/') + 1);
            }

            // Office media types do not end in their extension, so stripping
            // the "application/" prefix leaves "msword" or the 60-character
            // wordprocessingml string rather than "doc" or "docx".
            //
            // This map was added after watching a real Gemini response fail
            // validation on exactly that, then self-correct on retry. The
            // repair loop worked, but it cost a full round trip and 5,000
            // output tokens for something entirely mechanical.
            $mime = self::MIME_SUBTYPES[$mime] ?? $mime;

            if ($mime !== '' && ! in_array($mime, $out, true)) {
                $out[] = $mime;
            }
        }

        return $out;
    }

    // ── Identity helpers ─────────────────────────────────────────────────

    private function fieldId($given): string
    {
        if (is_string($given)
            && preg_match('/^fld_[a-z0-9]{4,32}$/', $given)
            && ! in_array($given, $this->usedFieldIds, true)) {
            $this->usedFieldIds[] = $given;

            return $given;
        }

        do {
            $id = FieldFactory::fieldId();
        } while (in_array($id, $this->usedFieldIds, true));

        $this->usedFieldIds[] = $id;

        return $id;
    }

    private function sectionId($given): string
    {
        if (is_string($given)
            && preg_match('/^sec_[a-z0-9]{4,32}$/', $given)
            && ! in_array($given, $this->usedSectionIds, true)) {
            $this->usedSectionIds[] = $given;

            return $given;
        }

        do {
            $id = FieldFactory::sectionId();
        } while (in_array($id, $this->usedSectionIds, true));

        $this->usedSectionIds[] = $id;

        return $id;
    }

    private function fieldKey($given, string $label): string
    {
        $candidate = is_string($given) && trim($given) !== ''
            ? FieldFactory::keyFrom($given)
            : FieldFactory::keyFrom($label);

        $key = FieldFactory::uniqueKey($candidate, $this->usedKeys);

        $this->usedKeys[] = $key;

        return $key;
    }

    // ── Scalar coercion ──────────────────────────────────────────────────

    private function str($value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private function bool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['true', '1', 'yes', 'y', 'required', 'on'], true);
        }

        return (bool) $value;
    }
}
