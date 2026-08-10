<?php

namespace App\Forms;

/**
 * Restores field identity after an AI edit.
 *
 * THE PROBLEM THIS SOLVES, observed with a real model:
 *
 * Asked to "add an emergency contact section", Gemini returned a correct form
 * — and quietly dropped the `key` from fields it had kept. SchemaNormaliser
 * then did its job and derived fresh keys from the labels, so `email` became
 * `email_address` and `skills` became `which_of_these_have_you_worked_with`.
 *
 * The schema was valid. The form looked right. And every submission already
 * collected was now orphaned, because answers are stored keyed by field key.
 * Only 5 of 9 keys survived.
 *
 * The prompt already says, in capitals, to preserve keys. The model ignored it.
 * That is the lesson: an instruction is a request, not a guarantee. Anything
 * that must hold has to be enforced by code.
 *
 * So after an edit, every generated field is matched back to the field it came
 * from and has its original key and id restored. A field that matches nothing
 * is genuinely new and keeps what it was given.
 */
class SchemaReconciler
{
    /**
     * @param  array<string, mixed>  $original   the schema sent to the model
     * @param  array<string, mixed>  $generated  the normalised schema it returned
     * @return array<string, mixed>
     */
    public function reconcile(array $original, array $generated): array
    {
        $originalFields = $this->flatten($original);

        if ($originalFields === []) {
            return $generated;
        }

        // Two indexes, tried in order of confidence.
        $byId = [];
        $byLabel = [];

        foreach ($originalFields as $field) {
            if (! empty($field['id'])) {
                $byId[$field['id']] = $field;
            }

            $label = $this->normaliseLabel($field['label'] ?? '');

            // First occurrence wins: with duplicate labels there is no way to
            // tell which is which, so guessing would be worse than not matching.
            if ($label !== '' && ! isset($byLabel[$label])) {
                $byLabel[$label] = $field;
            }
        }

        $claimed = [];
        $usedKeys = [];

        // Pass 1: restore identity where a field clearly corresponds.
        foreach ($generated['sections'] ?? [] as $si => $section) {
            foreach ($section['fields'] ?? [] as $fi => $field) {
                $match = $this->match($field, $byId, $byLabel, $claimed);

                if ($match === null) {
                    continue;
                }

                $claimed[$match['id']] = true;

                // Only restore a key onto a field that takes one. A field the
                // model changed from text to heading no longer has a key.
                if (! empty($field['key']) && ! empty($match['key'])) {
                    $generated['sections'][$si]['fields'][$fi]['key'] = $match['key'];
                    $usedKeys[] = $match['key'];
                }

                // The id is ours, never the model's, and keeping it stable
                // means version diffs stay readable.
                $generated['sections'][$si]['fields'][$fi]['id'] = $match['id'];
            }
        }

        // Pass 2: make sure restoring keys did not create a collision with a
        // key the model invented for a genuinely new field.
        foreach ($generated['sections'] ?? [] as $si => $section) {
            foreach ($section['fields'] ?? [] as $fi => $field) {
                if (empty($field['key'])) {
                    continue;
                }

                if (in_array($field['key'], $usedKeys, true)
                    && ! $this->wasRestored($field, $byId, $claimed)) {
                    $field['key'] = FieldFactory::uniqueKey($field['key'], $usedKeys);
                    $generated['sections'][$si]['fields'][$fi]['key'] = $field['key'];
                }

                if (! in_array($field['key'], $usedKeys, true)) {
                    $usedKeys[] = $field['key'];
                }
            }
        }

        return $generated;
    }

    /**
     * Which original field does this generated one correspond to?
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, array<string, mixed>>  $byLabel
     * @param  array<string, bool>  $claimed
     * @return array<string, mixed>|null
     */
    private function match(array $field, array $byId, array $byLabel, array $claimed): ?array
    {
        // Strongest signal: the model echoed our id back.
        $id = $field['id'] ?? null;

        if (is_string($id) && isset($byId[$id]) && ! isset($claimed[$id])) {
            return $byId[$id];
        }

        // Next: the model echoed our key.
        $key = $field['key'] ?? null;

        if (is_string($key) && $key !== '') {
            foreach ($byId as $candidate) {
                if (($candidate['key'] ?? null) === $key && ! isset($claimed[$candidate['id']])) {
                    return $candidate;
                }
            }
        }

        // Weakest, and the one that actually saves us: an unchanged label.
        // A model that rewrites the label has arguably created a new question
        // anyway, so declining to match there is the right call.
        $label = $this->normaliseLabel($field['label'] ?? '');

        if ($label !== '' && isset($byLabel[$label]) && ! isset($claimed[$byLabel[$label]['id']])) {
            return $byLabel[$label];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, bool>  $claimed
     */
    private function wasRestored(array $field, array $byId, array $claimed): bool
    {
        return isset($field['id']) && isset($byId[$field['id']]) && isset($claimed[$field['id']]);
    }

    /**
     * Case and whitespace insensitive, and blind to trailing punctuation, so
     * "Email address" and "Email Address:" are the same question.
     */
    private function normaliseLabel(string $label): string
    {
        $label = mb_strtolower(trim($label));
        $label = preg_replace('/[\s\p{P}]+/u', ' ', $label);

        return trim((string) $label);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, array<string, mixed>>
     */
    private function flatten(array $schema): array
    {
        $out = [];

        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (! empty($field['id'])) {
                    $out[] = $field;
                }
            }
        }

        return $out;
    }
}
