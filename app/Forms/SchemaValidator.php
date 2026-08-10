<?php

namespace App\Forms;

use App\Enums\FieldType;

/**
 * The one definition of a valid form schema.
 *
 * Four consumers call this and no other:
 *
 *   1. the builder, before writing a new form_version;
 *   2. the raw JSON editor, on every edit;
 *   3. the AI generator, before persisting anything an LLM produced;
 *   4. the document importer, before committing a parsed document.
 *
 * If any of them had its own notion of "valid", the three would drift and the
 * resulting bugs would only show up in production data. So this class is
 * deliberately strict and deliberately alone.
 *
 * It validates STRUCTURE and CONSISTENCY, not repairable sloppiness. Anything
 * that can be fixed mechanically — a missing id, a string option list, a
 * `type` of "select" — is SchemaNormaliser's job and should be run first.
 */
class SchemaValidator
{
    /** Keys permitted inside a field's `validation` object. */
    private const VALIDATION_KEYS = [
        'min_length', 'max_length', 'min', 'max', 'regex', 'mimes', 'max_kb',
    ];

    /** Upload extensions we are willing to accept. */
    private const ALLOWED_MIMES = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'zip',
    ];

    /** @var array<string, array<int, string>> */
    private array $errors = [];

    /** Every answer key seen so far, for cross-section duplicate detection. */
    private array $seenKeys = [];

    private array $seenFieldIds = [];

    private array $seenSectionIds = [];

    /**
     * @param  mixed  $schema
     */
    public function validate($schema): ValidationResult
    {
        $this->errors = [];
        $this->seenKeys = [];
        $this->seenFieldIds = [];
        $this->seenSectionIds = [];

        if (! is_array($schema)) {
            return ValidationResult::failed(['' => ['Schema must be a JSON object.']]);
        }

        $this->validateRoot($schema);
        $this->validateSettings($schema['settings'] ?? null);

        $sections = $schema['sections'] ?? null;

        if (! is_array($sections) || ! array_is_list($sections)) {
            $this->fail('sections', 'sections must be a JSON array.');

            return $this->result();
        }

        foreach ($sections as $i => $section) {
            $this->validateSection($section, "sections.{$i}");
        }

        // A form with no answerable field cannot receive a submission. Allowed
        // while drafting (sections may be empty), rejected only once fields
        // exist but none of them collect anything.
        if ($sections !== [] && $this->seenKeys === [] && $this->hasAnyField($sections)) {
            $this->fail('sections', 'The form has fields but none of them collect an answer.');
        }

        return $this->result();
    }

    // ── Root ─────────────────────────────────────────────────────────────

    private function validateRoot(array $schema): void
    {
        if (! isset($schema['version']) || ! is_int($schema['version']) || $schema['version'] < 1) {
            $this->fail('version', 'version must be an integer of 1 or more.');
        }

        $title = $schema['title'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            $this->fail('title', 'title is required.');
        } elseif (mb_strlen($title) > 200) {
            $this->fail('title', 'title must be 200 characters or fewer.');
        }

        if (array_key_exists('description', $schema)
            && $schema['description'] !== null
            && ! is_string($schema['description'])) {
            $this->fail('description', 'description must be a string or null.');
        }
    }

    private function validateSettings($settings): void
    {
        if ($settings === null) {
            return;
        }

        if (! is_array($settings)) {
            $this->fail('settings', 'settings must be a JSON object.');

            return;
        }

        if (isset($settings['multi_step']) && ! is_bool($settings['multi_step'])) {
            $this->fail('settings.multi_step', 'multi_step must be true or false.');
        }

        if (isset($settings['submit_label']) && ! is_string($settings['submit_label'])) {
            $this->fail('settings.submit_label', 'submit_label must be a string.');
        }

        $redirect = $settings['redirect_url'] ?? null;

        if ($redirect !== null && $redirect !== '') {
            if (! is_string($redirect) || ! filter_var($redirect, FILTER_VALIDATE_URL)) {
                $this->fail('settings.redirect_url', 'redirect_url must be a valid URL.');
            } elseif (! str_starts_with($redirect, 'https://') && ! str_starts_with($redirect, 'http://')) {
                // Blocks javascript: and data: URLs, which would otherwise be
                // an XSS vector on the post-submit redirect.
                $this->fail('settings.redirect_url', 'redirect_url must use http or https.');
            }
        }
    }

    // ── Sections ─────────────────────────────────────────────────────────

    private function validateSection($section, string $path): void
    {
        if (! is_array($section)) {
            $this->fail($path, 'Each section must be a JSON object.');

            return;
        }

        $id = $section['id'] ?? null;

        if (! is_string($id) || ! preg_match('/^sec_[a-z0-9]{4,32}$/', $id)) {
            $this->fail("{$path}.id", 'Section id must look like "sec_" followed by 4-32 lowercase letters or digits.');
        } elseif (in_array($id, $this->seenSectionIds, true)) {
            $this->fail("{$path}.id", "Duplicate section id \"{$id}\".");
        } else {
            $this->seenSectionIds[] = $id;
        }

        if (isset($section['title']) && $section['title'] !== null) {
            if (! is_string($section['title'])) {
                $this->fail("{$path}.title", 'Section title must be a string or null.');
            } elseif (mb_strlen($section['title']) > 200) {
                $this->fail("{$path}.title", 'Section title must be 200 characters or fewer.');
            }
        }

        $fields = $section['fields'] ?? null;

        if (! is_array($fields) || ! array_is_list($fields)) {
            $this->fail("{$path}.fields", 'fields must be a JSON array.');

            return;
        }

        foreach ($fields as $i => $field) {
            $this->validateField($field, "{$path}.fields.{$i}");
        }
    }

    // ── Fields ───────────────────────────────────────────────────────────

    private function validateField($field, string $path): void
    {
        if (! is_array($field)) {
            $this->fail($path, 'Each field must be a JSON object.');

            return;
        }

        // ── id
        $id = $field['id'] ?? null;

        if (! is_string($id) || ! preg_match('/^fld_[a-z0-9]{4,32}$/', $id)) {
            $this->fail("{$path}.id", 'Field id must look like "fld_" followed by 4-32 lowercase letters or digits.');
        } elseif (in_array($id, $this->seenFieldIds, true)) {
            $this->fail("{$path}.id", "Duplicate field id \"{$id}\".");
        } else {
            $this->seenFieldIds[] = $id;
        }

        // ── type. This is the hallucination gate: FieldType is the allow-list,
        //    so an invented type fails here rather than reaching the database.
        $rawType = $field['type'] ?? null;
        $type = is_string($rawType) ? FieldType::tryFrom($rawType) : null;

        if ($type === null) {
            $shown = is_string($rawType) ? "\"{$rawType}\"" : 'missing';
            $this->fail(
                "{$path}.type",
                "Unknown field type {$shown}. Must be one of: ".implode(', ', FieldType::values()).'.'
            );

            // Without a known type nothing else about this field can be judged.
            return;
        }

        // ── label
        $label = $field['label'] ?? null;

        if ($type === FieldType::Divider) {
            // A divider is a horizontal rule; a label on it is harmless but
            // meaningless, so it is simply not required.
        } elseif (! is_string($label) || trim($label) === '') {
            $this->fail("{$path}.label", 'label is required.');
        } elseif (mb_strlen($label) > 500) {
            $this->fail("{$path}.label", 'label must be 500 characters or fewer.');
        }

        // ── key
        $this->validateKey($field, $type, $path);

        // ── required
        if (isset($field['required']) && ! is_bool($field['required'])) {
            $this->fail("{$path}.required", 'required must be true or false.');
        }

        if (! $type->collectsAnswer() && ! empty($field['required'])) {
            $this->fail("{$path}.required", "A {$type->value} field collects no answer, so it cannot be required.");
        }

        // ── options
        $this->validateOptions($field, $type, $path);

        // ── validation
        $this->validateRules($field, $type, $path);
    }

    private function validateKey(array $field, FieldType $type, string $path): void
    {
        $key = $field['key'] ?? null;

        if (! $type->collectsAnswer()) {
            if ($key !== null && $key !== '') {
                $this->fail("{$path}.key", "A {$type->value} field collects no answer, so it must not have a key.");
            }

            return;
        }

        if (! is_string($key) || $key === '') {
            $this->fail("{$path}.key", 'key is required.');

            return;
        }

        // The key becomes an HTML input name, a JSON object key and a CSV
        // column header. snake_case keeps it safe in all three.
        if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
            $this->fail(
                "{$path}.key",
                "key \"{$key}\" must start with a lowercase letter and contain only lowercase letters, digits and underscores (max 64)."
            );

            return;
        }

        // Duplicates are checked across the WHOLE form, not per section — two
        // sections with a `phone` field would silently overwrite each other in
        // the submission JSON.
        if (in_array($key, $this->seenKeys, true)) {
            $this->fail("{$path}.key", "Duplicate key \"{$key}\". Every field key must be unique across the entire form.");

            return;
        }

        $this->seenKeys[] = $key;
    }

    private function validateOptions(array $field, FieldType $type, string $path): void
    {
        $options = $field['options'] ?? [];

        if (! is_array($options)) {
            $this->fail("{$path}.options", 'options must be a JSON array.');

            return;
        }

        if (! $type->hasOptions()) {
            if ($options !== []) {
                $this->fail("{$path}.options", "A {$type->value} field does not take options.");
            }

            return;
        }

        if ($options === []) {
            $this->fail("{$path}.options", "A {$type->value} field needs at least one option.");

            return;
        }

        if (count($options) > 200) {
            $this->fail("{$path}.options", 'A field may have at most 200 options.');
        }

        $values = [];

        foreach ($options as $i => $option) {
            $optionPath = "{$path}.options.{$i}";

            if (! is_array($option)) {
                $this->fail($optionPath, 'Each option must be an object with "value" and "label".');

                continue;
            }

            $value = $option['value'] ?? null;
            $optLabel = $option['label'] ?? null;

            if (! is_string($value) && ! is_int($value)) {
                $this->fail("{$optionPath}.value", 'Option value must be a string or integer.');
            } else {
                $value = (string) $value;

                if ($value === '') {
                    $this->fail("{$optionPath}.value", 'Option value must not be empty.');
                } elseif (in_array($value, $values, true)) {
                    $this->fail("{$optionPath}.value", "Duplicate option value \"{$value}\".");
                } else {
                    $values[] = $value;
                }
            }

            if (! is_string($optLabel) || trim($optLabel) === '') {
                $this->fail("{$optionPath}.label", 'Option label is required.');
            }
        }
    }

    private function validateRules(array $field, FieldType $type, string $path): void
    {
        $rules = $field['validation'] ?? [];

        if ($rules === null) {
            return;
        }

        if (! is_array($rules)) {
            $this->fail("{$path}.validation", 'validation must be a JSON object.');

            return;
        }

        foreach (array_keys($rules) as $key) {
            if (! in_array($key, self::VALIDATION_KEYS, true)) {
                $this->fail(
                    "{$path}.validation.{$key}",
                    "Unknown validation rule \"{$key}\". Allowed: ".implode(', ', self::VALIDATION_KEYS).'.'
                );
            }
        }

        $this->assertNullableInt($rules, 'min_length', "{$path}.validation", 0);
        $this->assertNullableInt($rules, 'max_length', "{$path}.validation", 1);
        $this->assertNullableInt($rules, 'max_kb', "{$path}.validation", 1);

        foreach (['min', 'max'] as $bound) {
            $value = $rules[$bound] ?? null;

            if ($value !== null && ! is_int($value) && ! is_float($value)) {
                $this->fail("{$path}.validation.{$bound}", "{$bound} must be a number or null.");
            }
        }

        // A range whose floor is above its ceiling can never be satisfied, so
        // every submission would fail with no way for the respondent to know why.
        $this->assertRange($rules, 'min_length', 'max_length', "{$path}.validation");
        $this->assertRange($rules, 'min', 'max', "{$path}.validation");

        // ── regex
        $regex = $rules['regex'] ?? null;

        if ($regex !== null && $regex !== '') {
            if (! is_string($regex)) {
                $this->fail("{$path}.validation.regex", 'regex must be a string.');
            } elseif (! $this->isCompilableRegex($regex)) {
                $this->fail("{$path}.validation.regex", "regex \"{$regex}\" is not a valid pattern.");
            }
        }

        // ── mimes
        $mimes = $rules['mimes'] ?? [];

        if ($mimes !== null && $mimes !== []) {
            if (! is_array($mimes) || ! array_is_list($mimes)) {
                $this->fail("{$path}.validation.mimes", 'mimes must be an array of file extensions.');
            } else {
                foreach ($mimes as $mime) {
                    if (! is_string($mime) || ! in_array(strtolower($mime), self::ALLOWED_MIMES, true)) {
                        $shown = is_string($mime) ? "\"{$mime}\"" : 'a non-string value';
                        $this->fail(
                            "{$path}.validation.mimes",
                            "Unsupported file extension {$shown}. Allowed: ".implode(', ', self::ALLOWED_MIMES).'.'
                        );
                    }
                }
            }
        }

        if ($type !== FieldType::File && (($mimes !== [] && $mimes !== null) || ($rules['max_kb'] ?? null) !== null)) {
            $this->fail("{$path}.validation", "mimes and max_kb only apply to a file field, not {$type->value}.");
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function assertNullableInt(array $rules, string $key, string $path, int $min): void
    {
        $value = $rules[$key] ?? null;

        if ($value === null) {
            return;
        }

        if (! is_int($value)) {
            $this->fail("{$path}.{$key}", "{$key} must be an integer or null.");
        } elseif ($value < $min) {
            $this->fail("{$path}.{$key}", "{$key} must be {$min} or more.");
        }
    }

    private function assertRange(array $rules, string $lowKey, string $highKey, string $path): void
    {
        $low = $rules[$lowKey] ?? null;
        $high = $rules[$highKey] ?? null;

        if (is_numeric($low) && is_numeric($high) && $low > $high) {
            $this->fail("{$path}.{$lowKey}", "{$lowKey} ({$low}) cannot be greater than {$highKey} ({$high}).");
        }
    }

    /**
     * Test-compile a user-supplied pattern.
     *
     * The pattern is stored without delimiters and wrapped at compile time, so
     * a form author cannot inject modifiers such as `/e`.
     *
     * The error handler is swapped out rather than using `@`. Under Laravel,
     * `@` does not stop the framework's handler from turning the compilation
     * warning into an ErrorException, and an invalid pattern is an expected
     * input here — it is exactly what we are testing for, not a fault.
     *
     * Public and static because SchemaNormaliser needs the same check, and two
     * copies of "is this regex safe?" is one too many.
     */
    public static function isCompilableRegex(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match('/'.str_replace('/', '\/', $pattern).'/', '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    private function hasAnyField(array $sections): bool
    {
        foreach ($sections as $section) {
            if (! empty($section['fields'])) {
                return true;
            }
        }

        return false;
    }

    private function fail(string $path, string $message): void
    {
        $this->errors[$path][] = $message;
    }

    private function result(): ValidationResult
    {
        return $this->errors === []
            ? ValidationResult::passed()
            : ValidationResult::failed($this->errors);
    }
}
