<?php

namespace App\Enums;

/**
 * Every field type the builder understands.
 *
 * This enum is the allow-list for the whole application. It is used by:
 *
 *   - the builder palette, to render the "add field" buttons;
 *   - the JSON schema validator, to reject an unknown `type`;
 *   - the AI layer, which is given this exact list in its system prompt and
 *     whose output is checked against it — this is how a hallucinated type
 *     like "signature_pad" gets caught rather than persisted;
 *   - the document importer, which maps detected question shapes onto it;
 *   - the public renderer and the server-side validator.
 *
 * Because all six read the same enum, adding a field type is a one-line change
 * here plus a Blade partial, and nothing can drift.
 *
 * The brief asks for at least ten. There are seventeen.
 */
enum FieldType: string
{
    // ── Text input ───────────────────────────────────────────────────────
    case Text = 'text';
    case Textarea = 'textarea';
    case Email = 'email';
    case Phone = 'phone';
    case Url = 'url';
    case Number = 'number';

    // ── Date and time ────────────────────────────────────────────────────
    case Date = 'date';
    case Time = 'time';
    case DateTime = 'datetime';

    // ── Choice ───────────────────────────────────────────────────────────
    case Dropdown = 'dropdown';
    case Radio = 'radio';
    case Checkbox = 'checkbox';
    case Rating = 'rating';

    // ── Upload ───────────────────────────────────────────────────────────
    case File = 'file';

    // ── Presentational: rendered on the form but never collect an answer ──
    case Heading = 'heading';
    case Paragraph = 'paragraph';
    case Divider = 'divider';

    /**
     * Human label for the builder palette.
     */
    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text input',
            self::Textarea => 'Text area',
            self::Email => 'Email input',
            self::Phone => 'Phone input',
            self::Url => 'URL input',
            self::Number => 'Number input',
            self::Date => 'Date picker',
            self::Time => 'Time picker',
            self::DateTime => 'Date & time',
            self::Dropdown => 'Dropdown',
            self::Radio => 'Radio buttons',
            self::Checkbox => 'Checkboxes',
            self::Rating => 'Rating',
            self::File => 'File upload',
            self::Heading => 'Title',
            self::Paragraph => 'Description',
            self::Divider => 'New line',
        };
    }

    /**
     * Presentational types have no answer, so they are skipped by the
     * validator, excluded from CSV export, and never given a `key`.
     */
    public function collectsAnswer(): bool
    {
        return ! in_array($this, [self::Heading, self::Paragraph, self::Divider], true);
    }

    /**
     * Choice types are the only ones for which an `options` array is
     * meaningful. The schema validator rejects options on anything else, which
     * is a common shape of AI hallucination.
     */
    public function hasOptions(): bool
    {
        return in_array($this, [self::Dropdown, self::Radio, self::Checkbox], true);
    }

    /**
     * Types whose answer is an array rather than a scalar. Drives both the
     * validation rules and how the value is flattened for CSV export.
     */
    public function isMultiValue(): bool
    {
        return $this === self::Checkbox;
    }

    /**
     * The Laravel validation rule that expresses this type's intrinsic format,
     * before any user-configured rules are layered on top.
     */
    public function baseRule(): ?string
    {
        return match ($this) {
            self::Email => 'email:rfc',
            self::Url => 'url',
            self::Number, self::Rating => 'numeric',
            self::Date => 'date',
            self::DateTime => 'date',
            self::File => 'file',
            self::Checkbox => 'array',
            default => null,
        };
    }

    /**
     * Grouping for the builder palette, matching the reference UI's
     * "STANDARD FIELDS" style sections.
     */
    public function group(): string
    {
        return match (true) {
            in_array($this, [self::Heading, self::Paragraph, self::Divider], true) => 'Layout',
            in_array($this, [self::Dropdown, self::Radio, self::Checkbox, self::Rating], true) => 'Choice',
            $this === self::File => 'Upload',
            default => 'Standard',
        };
    }

    /**
     * @return array<string> Plain string values, for the AI system prompt and
     *                       the JSON schema `enum` constraint.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
