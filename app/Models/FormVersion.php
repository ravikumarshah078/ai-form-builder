<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An immutable snapshot of a form's JSON schema.
 *
 * Treat instances as read-only once persisted. Anything that wants to change a
 * form creates the NEXT version instead of mutating this one; that is what
 * makes rollback and historically-accurate submission rendering possible.
 */
class FormVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_id',
        'version_number',
        'schema',
        'checksum',
        'origin',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'version_number' => 'integer',
        ];
    }

    /**
     * Fingerprint of a schema, used to detect "nothing actually changed".
     *
     * The schema is JSON-encoded with sorted keys so that two logically
     * identical schemas that differ only in key order produce the same hash.
     * Without this, autosave would create a new version on every keystroke.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function checksumFor(array $schema): string
    {
        return hash('sha256', json_encode(
            static::canonicalise($schema),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    /**
     * Recursively sort associative array keys so encoding is deterministic.
     * List arrays keep their order — field order is meaningful.
     *
     * @param  mixed  $value
     * @return mixed
     */
    protected static function canonicalise($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map([static::class, 'canonicalise'], $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Submissions captured while this version was live.
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    // ── Convenience ──────────────────────────────────────────────────────

    /**
     * Flat list of every field across every section, in display order.
     *
     * The validator, the CSV exporter and the renderer all want this view, so
     * it lives here rather than being re-derived in three places.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields(): array
    {
        $fields = [];

        foreach ($this->schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    public function isCurrent(): bool
    {
        return $this->form->current_version_id === $this->id;
    }
}
