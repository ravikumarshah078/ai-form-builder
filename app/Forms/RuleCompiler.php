<?php

namespace App\Forms;

use App\Enums\FieldType;

/**
 * Compiles a form schema into Laravel validation rules.
 *
 * This is the concrete answer to the brief's "server-side validation is
 * derived from the same schema — never trust the browser".
 *
 * The critical detail is WHICH schema. The public submit handler compiles from
 * the FormVersion row it loaded, never from anything in the request. A
 * respondent can rewrite every `required`, `maxlength` and `accept` attribute
 * in devtools and it changes nothing, because the browser's copy of the schema
 * is never consulted on the way back in.
 *
 * Output is a plain array suitable for Validator::make(), so nothing about the
 * rest of the app has to know this class exists.
 */
class RuleCompiler
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array{rules: array<string, mixed>, attributes: array<string, string>, messages: array<string, string>}
     */
    public function compile(array $schema): array
    {
        $rules = [];
        $attributes = [];
        $messages = [];

        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $type = FieldType::tryFrom($field['type'] ?? '');

                // Presentational fields collect nothing, so they contribute no
                // rules. An unknown type contributes none either — it should
                // have been rejected before a form was ever published.
                if ($type === null || ! $type->collectsAnswer()) {
                    continue;
                }

                $key = $field['key'] ?? null;

                if (! is_string($key) || $key === '') {
                    continue;
                }

                $this->compileField($field, $type, $key, $rules, $attributes, $messages);
            }
        }

        return compact('rules', 'attributes', 'messages');
    }

    /**
     * Just the answerable keys, in display order.
     *
     * Used by the CSV exporter for its header row and by the submit handler to
     * strip any extra keys a client posted that the form does not define.
     *
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    public function answerKeys(array $schema): array
    {
        $keys = [];

        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $type = FieldType::tryFrom($field['type'] ?? '');

                if ($type?->collectsAnswer() && ! empty($field['key'])) {
                    $keys[] = $field['key'];
                }
            }
        }

        return $keys;
    }

    // ── Per-field ────────────────────────────────────────────────────────

    private function compileField(
        array $field,
        FieldType $type,
        string $key,
        array &$rules,
        array &$attributes,
        array &$messages,
    ): void {
        $required = ! empty($field['required']);
        $v = $field['validation'] ?? [];

        $attributes[$key] = $field['label'] ?? $key;

        $set = [$required ? 'required' : 'nullable'];

        // The type's intrinsic format rule (email, url, numeric, date, …).
        if ($base = $type->baseRule()) {
            $set[] = $base;
        }

        match (true) {
            $type === FieldType::Checkbox => $this->compileCheckbox($field, $key, $set, $rules, $messages, $required),
            $type->hasOptions() => $this->compileSingleChoice($field, $key, $set, $messages),
            $type === FieldType::File => $this->compileFile($v, $key, $set, $messages),
            $type === FieldType::Rating => $this->compileNumeric($v, $set, 1, 5),
            $type === FieldType::Number => $this->compileNumeric($v, $set),
            default => $this->compileText($v, $key, $set, $messages),
        };

        $rules[$key] = $set;
    }

    /**
     * A checkbox group posts an array, so the group and its members are
     * validated separately: the group for presence and size, each member for
     * membership in the option list.
     */
    private function compileCheckbox(
        array $field,
        string $key,
        array &$set,
        array &$rules,
        array &$messages,
        bool $required,
    ): void {
        // 'array' already came from baseRule(). A required checkbox group must
        // have at least one box ticked, which 'required' alone does not
        // guarantee for an empty array.
        if ($required) {
            $set[] = 'min:1';
        }

        $values = $this->optionValues($field);

        if ($values !== []) {
            $rules[$key.'.*'] = ['in:'.implode(',', $values)];
            $messages[$key.'.*.in'] = 'One of the selected choices is not a valid option.';
        }
    }

    private function compileSingleChoice(array $field, string $key, array &$set, array &$messages): void
    {
        $values = $this->optionValues($field);

        if ($values !== []) {
            $set[] = 'in:'.implode(',', $values);
            $messages[$key.'.in'] = 'The selected choice is not a valid option.';
        }
    }

    private function compileFile(array $v, string $key, array &$set, array &$messages): void
    {
        $mimes = $v['mimes'] ?? [];

        if (is_array($mimes) && $mimes !== []) {
            $set[] = 'mimes:'.implode(',', $mimes);
            $messages[$key.'.mimes'] = 'The file must be one of: '.implode(', ', $mimes).'.';
        }

        // Laravel's `max` on a file is in kilobytes, which is what the schema
        // stores, so no conversion is needed here.
        if (! empty($v['max_kb'])) {
            $set[] = 'max:'.(int) $v['max_kb'];
            $messages[$key.'.max'] = 'The file may not be larger than '
                .round(((int) $v['max_kb']) / 1024, 1).' MB.';
        }
    }

    private function compileNumeric(array $v, array &$set, $defaultMin = null, $defaultMax = null): void
    {
        $min = $v['min'] ?? $defaultMin;
        $max = $v['max'] ?? $defaultMax;

        if (is_numeric($min)) {
            $set[] = 'min:'.$min;
        }

        if (is_numeric($max)) {
            $set[] = 'max:'.$max;
        }
    }

    private function compileText(array $v, string $key, array &$set, array &$messages): void
    {
        // Guard the string rule so `min`/`max` are read as character counts
        // rather than numeric comparisons.
        $set[] = 'string';

        if (is_numeric($v['min_length'] ?? null)) {
            $set[] = 'min:'.(int) $v['min_length'];
        }

        // A ceiling always exists. Without one, a single request can push
        // megabytes into a JSON column.
        $set[] = 'max:'.(is_numeric($v['max_length'] ?? null) ? (int) $v['max_length'] : 5000);

        if (! empty($v['regex'])) {
            // The pattern is stored without delimiters and wrapped here, so a
            // form author cannot inject pattern modifiers.
            $set[] = 'regex:/'.str_replace('/', '\/', $v['regex']).'/';

            // Laravel's default regex message ("format is invalid") tells the
            // respondent nothing actionable, and we must not leak the pattern.
            $messages[$key.'.regex'] = 'This does not look like a valid entry.';
        }
    }

    /**
     * @return array<int, string>
     */
    private function optionValues(array $field): array
    {
        $values = [];

        foreach ($field['options'] ?? [] as $option) {
            if (isset($option['value']) && (is_string($option['value']) || is_int($option['value']))) {
                $values[] = (string) $option['value'];
            }
        }

        return $values;
    }
}
