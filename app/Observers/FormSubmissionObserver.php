<?php

namespace App\Observers;

use App\Models\Form;
use App\Models\FormSubmission;

/**
 * Keeps the two denormalised columns in sync with the submission itself.
 *
 * Both exist to keep the submissions list fast; neither is a source of truth,
 * and both can be rebuilt from `data` if they ever drift.
 */
class FormSubmissionObserver
{
    /**
     * Flatten the answers into `search_text` before every write.
     *
     * `saving` rather than `created`, so an edited submission re-indexes too.
     */
    public function saving(FormSubmission $submission): void
    {
        if ($submission->isDirty('data')) {
            $submission->search_text = $this->flatten($submission->data ?? []);
        }
    }

    /**
     * Maintain forms.submission_count.
     *
     * A raw increment rather than read-modify-write, so two submissions
     * arriving at the same instant cannot overwrite each other's count.
     */
    public function created(FormSubmission $submission): void
    {
        Form::whereKey($submission->form_id)->increment('submission_count');
    }

    public function deleted(FormSubmission $submission): void
    {
        Form::whereKey($submission->form_id)->decrement('submission_count');
    }

    /**
     * Reduce an answers array of unknown depth to one searchable string.
     *
     * Handles the shapes the field types actually produce: scalars from text
     * inputs, arrays from checkbox groups, and nested arrays from repeating
     * groups. Keys are excluded — searching for the field name "email" should
     * not match every submission that has an email field.
     *
     * @param  array<string, mixed>  $data
     */
    protected function flatten(array $data): string
    {
        $parts = [];

        array_walk_recursive($data, function ($value) use (&$parts) {
            if (is_scalar($value) && $value !== '' && ! is_bool($value)) {
                $parts[] = (string) $value;
            }
        });

        // MEDIUMTEXT holds ~16MB; the FULLTEXT index does not benefit from
        // anything near that, so cap it and keep the row small.
        return mb_substr(implode(' ', $parts), 0, 60000);
    }
}
